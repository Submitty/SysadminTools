<?php
namespace ssaf;

/** class to parse command line arguments */
class cli_args {
    /** @var array Holds all CLI argument flags and their values */
    private static $args            = array();
    /** @var string usage help message */
    private static $help_usage      = "Usage: submitty_student_auto_feed.php [-h | --help] [-a auth str] (-t term code)\n";
    /** @var string short description help message */
    private static $help_short_desc = "Read student enrollment CSV and upsert to Submitty database.\n";
    /** @var string argument list help message */
    private static $help_args_list  = <<<HELP
Arguments:
-h, --help    Show this help message.
-a auth str   Specify 'user:password@server', overriding config.php.  Optional.
-t term code  Term code associated with current student enrollment.  Required.
-l            Send a test message to error log(s) and quit.

HELP;

    /**
     * Parse command line arguments
     *
     * Called with 'cli_args::parse_args()'
     *
     * @return mixed term code as string or boolean false when no term code is present.
     */
    public static function parse_args() {
        self::$args = getopt('ha:t:l', array('help'));

        switch(true) {
        case array_key_exists('h', self::$args):
        case array_key_exists('help', self::$args):
            print self::$help_usage . self::$help_short_desc . self::$help_args_list;
            exit;
        case array_key_exists('t', self::$args):
        case array_key_exists('l', self::$args):
            return self::$args;
        default:
            print self::$help_usage . PHP_EOL;
            exit;
        }
    }
}
// EOF
?>
