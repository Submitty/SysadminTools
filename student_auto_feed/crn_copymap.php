#!/usr/bin/env php
<?php
require __DIR__ . "/config.php";

if (php_sapi_name() !== "cli") {
    die("This is a command line tool.\n");
}

if (is_null(CRN_COPYMAP_FILE)) {
    die("CRN_COPYMAP_FILE is null.  Check config.php.\n");
}

$proc = new crn_copy();
$proc->main();
exit;

class crn_copy {
    public $err;

    public function __construct() {
        $this->err = "";
    }

    public function __destruct() {
        if ($this->err !== "") fprintf(STDERR, $this->err);
    }

    public function main() {
        // Reminder: cli::parse_args() returns an array captured by regex,
        // so we need to always look at index [0] when reading $args data.
        $args = cli::parse_args();
        $args['source']['sections'][0] = $this->get_mappings($args['source']['sections'][0]);
        $args['dest']['sections'][0] = $this->get_mappings($args['dest']['sections'][0]);
        if (count($args['source']['sections'][0]) !== count($args['dest']['sections'][0])) {
            $this->err = "One course has more sections than the other.  Sections need to map 1:1.\n";
            exit(1);
        }

        $this->write_mappings($args);
    }

    private function write_mappings($args) {
        $term = $args['term'][0];
        $source_course = $args['source']['course'][0];
        $source_sections = $args['source']['sections'][0];
        $dest_course = $args['dest']['course'][0];
        $dest_sections = $args['dest']['sections'][0];

        // Insert "_{$term}" right before file extension.
        // e.g. "/path/to/crn_copymap.csv" for term f23 becomes "/path/to/crn_copymap_f23.csv"
        $filename = preg_replace("/([^\/]+?)(\.[^\/\.]*)?$/", "$1_{$term}$2", CRN_COPYMAP_FILE, 1);

        $fh = fopen($filename, "a");
        if ($fh === false) {
            $this->err = "Could not open crn copymap file for writing.\n";
            exit(1);
        }

        $len = count($source_sections);
        for ($i = 0; $i < $len; $i++) {
            $row = array($source_course, $source_sections[$i], $dest_course, $dest_sections[$i]);
            fputcsv($fh, $row, ",");
        }

        fclose($fh);
    }

    private function get_mappings($sections) {
        if ($sections === "" || $sections === "all") return array($sections);

        $arr = explode(",", $sections);
        $expanded = array();
        foreach($arr as $val) {
            if (preg_match("/(\d+)\-(\d+)/", $val, $matches) === 1) {
                $expanded = array_merge($expanded, range((int) $matches[1], (int) $matches[2]));
            } else {
                $expanded[] = $val;
            }
        }

        return $expanded;
    }
}

/** class to parse command line arguments */
class cli {
    /** @var string usage help message */
    private static $help_usage = "Usage: map_crn_copy.php [-h | --help | help] (term) (course-a) (sections) (course-b) (sections)\n";
    /** @var string short description help message */
    private static $help_short_desc = "Create duplicate enrollment mapping of courses and semesters.\n";
    /** @var string long description help message */
    private static $help_long_desc = <<<LONG_DESC
    Create a mapping of CRNs (course and sections) that are to be duplicated.
    This is useful if a professor wishes to have a course enrollment,
    by section, duplicated to another course.  Particularly when the
    duplicated course has no enrollment data provided by IT.\n
    LONG_DESC;
    /** @var string argument list help message */
    private static $help_args_list  = <<<ARGS_LIST
    Arguments:
    -h, --help, help  Show this help message.
    term       Term code of courses and sections being mapped.  Required.
    course-a   Original course
    sections   Section list, or "all" of preceding course
    course-b   Course being copied to
    sections   For course-b, this can be ommited when course-a sections is "all"
    ARGS_LIST;

    /**
     * Parse command line arguments
     *
     * CLI arguments are captured from global $argv by regular expressions during validation.
     *
     * @return array cli arguments
     */
    public static function parse_args() {
        global $argc, $argv;
        $matches = array();

        switch(true) {
        // Check for request for help
        case $argc > 1 && ($argv[1] === "-h" || $argv[1] === "--help" || $argv[1] === "help"):
            self::print_help();
            exit;
        // Validate CLI arguments.  Something is wrong (invalid) when a case condition is true.
        case $argc < 5 || $argc > 6:
        case $argv[3] === "all" && (array_key_exists(5, $argv) && $argv[5] !== "all"):
        case $argv[3] !== "all" && (!array_key_exists(5, $argv) || $argv[5] === "all"):
        case preg_match("/^[a-z][\d]{2}$/", $argv[1], $matches['term']) !== 1:
        case preg_match("/^[\w\d\-]+$/", $argv[2], $matches['source']['course']) !== 1:
        case preg_match("/^\d+(?:(?:,|\-)\d+)*$|^all$/", $argv[3], $matches['source']['sections']) !== 1:
        case preg_match("/^[\w\d\-]+$/", $argv[4], $matches['dest']['course']) !== 1:
        case preg_match("/^\d+(?:(?:,|\-)\d+)*$|^(?:all)?$/", $argv[5], $matches['dest']['sections']) !== 1:
            self::print_usage();
            exit;
        }

        // $matches['dest']['sections'][0] must be "all" when ['source']['sections'][0] is "all".
        if ($matches['source']['sections'][0] === "all") $matches['dest']['sections'][0] = "all";
        return $matches;
    }

    /** Print complete help */
    private static function print_help() {
        $msg  = self::$help_usage . PHP_EOL;
        $msg .= self::$help_short_desc . PHP_EOL;
        $msg .= self::$help_long_desc . PHP_EOL;
        $msg .= self::$help_args_list . PHP_EOL;
        print $msg;
    }

    /** Print CLI usage */
    private static function print_usage() {
        print self::$help_usage . PHP_EOL;
    }
}
// EOF
?>
