#!/usr/bin/env php
<?php
/**
 * Generates a report of how many students have dropped courses.
 *
 * This is meant to be run immediately before and immediately after the autofeed.
 * The first run (before running the autofeed) will cache enrollment numbers in
 * a CSV temp file.  The second run (after running the autofeed) will read the
 * cached results, compare with the database, compile the report, write to a
 * file, and optionally email it.  The first CLI arg must be '1' on the first
 * run and '2' on the second run.  Second CLI arg must be the term code.
 * e.g. $ ./add_drop_report.php 1 f21    will invoke first run for Fall 2021.
 *      $ ./add_drop_report.php 2 f21    will invoke second run for Fall 2021.
 *
 * @author Peter Bailie, Renssealer Polytechnic Institute, Research Computing
 */

require "config.php";

if (php_sapi_name() !== "cli") {
    die("This is a command line script\n");
}

if (!array_key_exists(1, $argv)) {
    die("Missing process pass # (1 or 2)");
}

if (!array_key_exists(2, $argv)) {
    die("Missing term code.\n");
}

$proc = new add_drop_report($argv);
$proc->go();
exit;

/**
 * Main process class
 *
 * @param array $argv
 */
class add_drop_report {

    /** @var string "Pass" as in which pass is being run: "1" or "2" */
    private $pass;

    /** @var string academic term / semester code.  e.g. "f21" for Fall 2021 */
    private $term;

    public function __construct($argv) {
        $this->pass = $argv[1];
        $this->term = $argv[2];
    }

    public function __destruct() {
        db::close();
    }

    /** Main process flow
     *
     * $argv[1] = "1": First run to read course list and cache results to CSV temp file
     * $argv[1] = "2": Second run to compare cached results with database and make report\
     */
    public function go() {
        switch($this->pass) {
        case "1":
            // Record current course enrollments to temporary CSV
            db::open();
            $courses = db::get_courses($this->term);
            $mapped_courses = db::get_mapped_courses($this->term);
            $enrollments = db::count_enrollments($this->term, $courses, $mapped_courses);
            $course_enrollments = $enrollments[0];
            // -----------------------------------------------------------------
            reports::write_temp_csv($course_enrollments);
            return null;
        case "2":
            // Read temporary CSV and compile and send add/drop report.
            db::open();
            $courses = db::get_courses($this->term);
            $mapped_courses = db::get_mapped_courses($this->term);
            $enrollments = db::count_enrollments($this->term, $courses, $mapped_courses);
            $course_enrollments = $enrollments[0];
            $manual_flags = $enrollments[1];
            // -----------------------------------------------------------------
            $prev_course_enrollments = reports::read_temp_csv();
            $report = reports::compile_report($prev_course_enrollments, $course_enrollments, $manual_flags);
            reports::send_report($this->term, $report);
            return null;
        default:
            die("Unrecognized pass \"{$this->pass}\"\n");
        }
    }
}

/** Static callback functions used with array_walk() */
class callbacks {
    /** Convert string to lowercase */
    public static function strtolower_cb(&$val, $key) { $val = strtolower($val); }

    /** Convert array to CSV data (as string) */
    public static function str_getcsv_cb(&$val, $key) { $val = str_getcsv($val, CSV_DELIM_CHAR); }
}

/** Database static class */
class db {
    /** @var resource DB connection resource */
    private static $db = null;

    /** Open connection to DB */
    public static function open() {
        // constants defined in config.php
        $user = DB_LOGIN;
        $host = DB_HOST;
        $password = DB_PASSWORD;

        self::$db = pg_connect("host={$host} dbname=submitty user={$user} password={$password} sslmode=prefer");
        if (!self::check()) {
            die("Failed to connect to DB\n");
        }
    }

    /** Close connection to DB */
    public static function close() {
        if (self::check()) {
            pg_close(self::$db);
        }
    }

    /**
     * Verify that DB connection resource/instance is OK
     *
     * PHP <  8.1: self::$db is a resource
     * PHP >= 8.1: self::$db is an instanceof \PgSql\Connection
     *
     * @access private
     * @return bool true when DB connection resource/instance is OK, false otherwise.
     */
    private static function check() {
        return (is_resource(self::$db) || self::$db instanceof \PgSql\Connection) && pg_connection_status(self::$db) === PGSQL_CONNECTION_OK;
    }

    /**
     * Retrieve course list from DB's courses table
     *
     * @param string $term
     * @return string[]
     */
    public static function get_courses($term) {
        if (!self::check()) {
            die("Not connected to DB when querying course list\n");
        }

        // Undergraduate courses from DB.
        $sql = "SELECT course FROM courses WHERE term=$1 AND status=1";
        $params = [$term];
        $res = pg_query_params(self::$db, $sql, $params);
        if ($res === false)
            die("Failed to retrieve course list from DB\n");
        $course_list = pg_fetch_all_columns($res, 0);
        array_walk($course_list, 'callbacks::strtolower_cb');

        return $course_list;
    }

    /**
     *  Retrieve mapped courses from DB's mapped_courses table
     *
     * @param $term
     * @return string[] [course] => mapped_course
     */
    public static function get_mapped_courses($term) {
        if (!self::check()) {
            die("Not connected to DB when querying mapped courses list\n");
        }

        // mapped courses from DB
        $sql = "SELECT course, mapped_course FROM mapped_courses WHERE term=$1";
        $params = [$term];
        $res = pg_query_params(self::$db, $sql, $params);
        if ($res === false) {
            die("Failed to retrieve mapped courses from DB\n");
        }

        $keys = pg_fetch_all_columns($res, 0);
        array_walk($keys, 'callbacks::strtolower_cb');
        $vals = pg_fetch_all_columns($res, 1);
        array_walk($vals, 'callbacks::strtolower_cb');
        $mapped_courses = array_combine($keys, $vals);

        return $mapped_courses;
    }

    /**
     * Retrieve number of students (1) with manual flag set, (2) enrolled in courses
     *
     * @param $term
     * @param $course_list
     * @param $mapped_courses
     * @return int[] ([0] => course enrollment counts, [1] => manual flag counts)
     */
    public static function count_enrollments($term, $course_list, $mapped_courses) {
        if (!self::check()) {
            die("Not connected to DB when querying course enrollments\n");
        }

        $course_enrollments = [];
        $manual_flags = [];

        foreach ($course_list as $course) {
            $grad_course = array_search($course, $mapped_courses);
            if ($grad_course === false) {
                // COURSE HAS NO GRAD SECTION (not mapped).
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section IS NOT NULL";
                $params = [$term, $course];
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup enrollments for {$course}\n");
                $course_enrollments[$course] = (int) pg_fetch_result($res, 0);

                // Get manual flag count
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section IS NOT NULL AND manual_registration=TRUE";
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup counts with manual flag set for {$course}\n");
                $manual_flags[$course] = (int) pg_fetch_result($res, 0);
            } else {
                // UNDERGRADUATE SECTION
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section='1'";
                $params = [$term, $course];
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup enrollments for {$course}\n");
                $course_enrollments[$course] = (int) pg_fetch_result($res, 0);

                // Get manual flag count
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section='1' AND manual_registration=TRUE";
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup counts with manual flag set for {$course} (undergrads)\n");
                $manual_flags[$course] = (int) pg_fetch_result($res, 0);

                // GRADUATE SECTION
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section='2'";
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup enrollments for {$grad_course}\n");
                $course_enrollments[$grad_course] = (int) pg_fetch_result($res, 0);

                // Get manual flag count
                $sql = "SELECT COUNT(*) FROM courses_users WHERE term=$1 AND course=$2 AND user_group=4 AND registration_section='2' AND manual_registration=TRUE";
                $res = pg_query_params(self::$db, $sql, $params);
                if ($res === false)
                    die("Failed to lookup counts with manual flag set for {$course} (grads)\n");
                $manual_flags[$grad_course] = (int) pg_fetch_result($res, 0);
            }
        }

        // Courses make up array keys.  Sort by courses.
        ksort($course_enrollments);
        ksort($manual_flags);
        return [$course_enrollments, $manual_flags];
    }
}

/** Reports related methods */
class reports {
    /**
     * Write course enrollment counts to temporary CSV file
     *
     * @param $course_enrollments
     */
    public static function write_temp_csv($course_enrollments) {
        $today = date("ymd");
        $tmp_path = ADD_DROP_FILES_PATH . "tmp/";
        $tmp_file = "{$today}.tmp";

        if (!is_dir($tmp_path)) {
            if (!mkdir($tmp_path, 0770, true)) {
                die("Can't create tmp folder.\n");
            }
        }

        $fh = fopen($tmp_path . $tmp_file, "w");
        if ($fh === false) {
            die("Could not create temp file.\n");
        }

        foreach($course_enrollments as $course=>$num_students) {
            fputcsv($fh, [$course, $num_students], CSV_DELIM_CHAR);
        }
        fclose($fh);
        chmod($tmp_path . $tmp_file, 0660);
    }

    /**
     * Read temporary CSV file.  Delete it when done.
     *
     * @return string[] "previous" course list of [course] => num_students
     */
    public static function read_temp_csv() {
        $today = date("ymd");
        $tmp_path = ADD_DROP_FILES_PATH . "tmp/";
        $tmp_file = "{$today}.tmp";

        $csv = file($tmp_path . $tmp_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($csv === false) {
            die("Could not read temp file to prepare report.\n");
        }

        unlink($tmp_path . $tmp_file);  // remove tmp file.
        array_walk($csv, 'callbacks::str_getcsv_cb');
        // return array of ['course' => enrollment].  e.g. ['csci1000' => 100]
        return array_combine(array_column($csv, 0), array_column($csv, 1));
    }

    /**
     * Compile $report from params' data
     *
     * @param $prev_course_enrollments
     * @param $course_enrollments
     * @param $manual_flags
     * @return string $report
     */
    public static function compile_report($prev_course_enrollments, $course_enrollments, $manual_flags) {
        // Compile stats
        $date = date("F j, Y");
        $time = date("g:i A");
        $report  = <<<HEADING
        Student autofeed counts report for {$date} at {$time}
        NOTE: Difference and ratio do not account for the manual flag.
        COURSE        YESTERDAY  TODAY  MANUAL  DIFFERENCE    RATIO\n
        HEADING;

        foreach ($course_enrollments as $course=>$course_enrollment) {
            // Calculate data
            $prev_course_enrollment = array_key_exists($course, $prev_course_enrollments) ? $prev_course_enrollments[$course] : 0;
            $manual_flag = array_key_exists($course, $manual_flags) ? $manual_flags[$course] : 0;
            $diff = $course_enrollment - $prev_course_enrollment;
            $ratio = $prev_course_enrollment != 0 ? abs(round(($diff / $prev_course_enrollment), 3)) : "N/A";

            // Align into columns
            $course = str_pad($course, 18, " ", STR_PAD_RIGHT);
            $prev_course_enrollment = str_pad($prev_course_enrollment, 5, " ", STR_PAD_LEFT);
            $course_enrollment = str_pad($course_enrollment, 5, " ", STR_PAD_LEFT);
            $manual_flag = str_pad($manual_flag, 6, " ", STR_PAD_LEFT);
            $diff = str_pad($diff, 10, " ", STR_PAD_LEFT);

            // Add row to report.
            $report .= "{$course}{$prev_course_enrollment}  {$course_enrollment}  {$manual_flag}  {$diff}    {$ratio}\n";
        }

        return $report;
    }

    /**
     * Write $report to file.  Optionally send $report by email.
     *
     * Email requires sendmail (or equivalent) installed and configured in php.ini.
     * Emails are sent "unauthenticated".
     *
     * @param $term
     * @param $repprt
     */
    public static function send_report($term, $report) {
        // Email stats (print stats if email is null or otherwise not sent)
        if (!is_null(ADD_DROP_TO_EMAIL)) {
            $date = date("M j, Y");
            $to = ADD_DROP_TO_EMAIL;
            $from = ADD_DROP_FROM_EMAIL;
            $subject = "Submitty Autofeed Add/Drop Report For {$date}";
            $report = str_replace("\n", "\r\n", $report); // needed for email formatting
            $is_sent = mail($to, $subject, $report, ['from' => $from]);
            if (!$is_sent) {
                $report = str_replace("\r\n", "\n", $report); // revert back since not being emailed.
                fprintf(STDERR, "Add/Drop report could not be emailed.\n%s", $report);
            }
        }

        // Write report to file.
        $path = ADD_DROP_FILES_PATH . $term . "/";
        if (!is_dir($path)) {
            if (!mkdir($path, 0770, true)) {
                die("Cannot create reports path {$path}.\n");
            }
        }

        $today = date("Y-m-d");
        file_put_contents("{$path}report_{$today}.txt", $report);
        chmod("{$path}report_{$today}.txt", 0660);
    }
}

// EOF
?>
