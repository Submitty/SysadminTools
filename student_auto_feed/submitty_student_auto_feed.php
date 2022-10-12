#!/usr/bin/env php
<?php
/**
 * submitty_student_auto_feed.php
 *
 * This script will read a student enrollment CSV feed provided by the campus
 * registrar or data warehouse and "upsert" (insert/update) the feed into
 * Submitty's course databases.  Requires PHP 7.1 and pgsql extension.
 *
 * @author Peter Bailie, Rensselaer Polytechnic Institute
 */

namespace ssaf;
require __DIR__ . "/config.php";
require __DIR__ . "/ssaf_cli.php";
require __DIR__ . "/ssaf_db.php";
require __DIR__ . "/ssaf_validate.php";

// Important: Make sure we are running from CLI
if (php_sapi_name() !== "cli") {
    die("This is a command line tool.");
}

$proc = new submitty_student_auto_feed();
$proc->go();
exit;

/** primary process class */
class submitty_student_auto_feed {
    /** @var resource File handle to read CSV */
    private $fh;
    /** @var string Semester code */
    private $semester;
    /** @var array List of courses registered in Submitty */
    private $course_list;
    /** @var array Describes how courses are mapped from one to another */
    private $mapped_courses;
    /** @var array Courses with invalid data. */
    private $invalid_courses;
    /** @var array All CSV data to be upserted */
    private $data;
    /** @var string Ongoing string of messages to write to logfile */
    private $log_msg_queue;

    /** Init properties.  Open DB connection.  Open CSV file. */
    public function __construct() {
        // Get semester from CLI arguments.
        $opts = cli_args::parse_args();
        if (array_key_exists('l', $opts)) {
            $this->log_it("Logging test requested.  There is no actual error to report.");
            exit;
        }
        $this->semester = $opts['t'];

        // Connect to "master" submitty DB.
        if (array_key_exists('a', $opts)) {
            $db_user     = strtok($opts['a'], ":");
            $db_password = strtok("@");
            $db_host     = strtok("");
        } else {
            $db_user     = DB_LOGIN;
            $db_password = DB_PASSWORD;
            $db_host     = DB_HOST;
        }

        if (!$this->open_csv()) {
            $this->log_it("Error: Cannot open CSV file");
            exit(1);
        }

        if (!db::open($db_host, $db_user, $db_password)) {
            $this->log_it("Error: Cannot connect to Submitty DB");
            exit(1);
        }

        // Get course list
        $error = null;
        $this->course_list = db::get_course_list($this->semester, $error);
        if ($this->course_list === false) {
            $this->log_it($error);
            exit(1);
        }

        // Get mapped_courses list (when one class is merged into another)
        $this->mapped_courses = db::get_mapped_courses($this->semester, $error);
        if ($this->mapped_courses === false) {
            $this->log_it($error);
            exit(1);
        }

        // Init other properties.
        $this->invalid_courses = array();
        $this->data = array();
        $this->log_msg_queue = "";
    }

    public function __destruct() {
        db::close();
        $this->close_csv();

        //Output logs, if any.
        if ($this->log_msg_queue !== "") {
            error_log($this->log_msg_queue, 3, ERROR_LOG_FILE);  // to file
            if (!is_null(ERROR_EMAIL)) {
                $is_sent = error_log($this->log_msg_queue, 1, ERROR_EMAIL);  // to email
                if (!$is_sent) {
                    // This gives cron a chance to email the log to a sysadmin.
                    fprintf(STDERR, "PHP could not send error log by email.\n%s", $this->log_msg_queue);
                }
            }
        }
    }

    /** Main process workflow */
    public function go() {
        switch(false) {
        case $this->get_csv_data():
            $this->log_it("Error getting CSV data.");
            break;
        case $this->check_for_excessive_dropped_users():
            // This check will block all upserts when an error is detected.
            exit(1);
        case $this->check_for_duplicate_user_ids():
            $this->log_it("Duplicate user IDs detected in CSV file.");
            break;
        case $this->invalidate_courses():
            // Should do nothing when $this->invalid_courses is empty
            $this->log_it("Error when removing data from invalid courses.");
            break;
        case $this->upsert_data():
            $this->log_it("Error during upsert.");
            break;
        }
    }

    /**
     * Read CSV file and sort data into $this->data.
     *
     * The sorting process includes ensuring the data row is associated with an
     * active course in Submitty and that the data row passes a series of
     * validation checks.  When a row fails validation, we flag that course in
     * $this->invalid_courses, which later will be used to prevent that course
     * from being upserted to preserve data integrity.
     *
     * @see validate::validate_row()  Row validation method in ssaf_validate.php
     */
    private function get_csv_data() {
        if (!is_resource($this->fh) || get_resource_type($this->fh) !== "stream") {
            $this->log_it("CSV file not open when get_csv_data() called.");
            return false;
        }

        // Consume/discard header row, if it exists.
        if (HEADER_ROW_EXISTS) {
            fgets($this->fh);
            $row_num = 2;
        } else {
            $row_num = 1;
        }

        // Read and assign csv rows into $this->data array
        $row = fgetcsv($this->fh, 0, CSV_DELIM_CHAR);
        while(!feof($this->fh)) {
            //Trim whitespace from all fields in $row
            array_walk($row, function(&$val, $key) { $val = trim($val); });

            // Remove any leading zeroes from "integer" registration sections.
            if (ctype_digit($row[COLUMN_SECTION])) $row[COLUMN_SECTION] = ltrim($row[COLUMN_SECTION], "0");

            $course = strtolower($row[COLUMN_COURSE_PREFIX] . $row[COLUMN_COURSE_NUMBER]);

            // Does $row have a valid registration code?
            $graded_codes = STUDENT_REGISTERED_CODES;
            $audit_codes = is_null(STUDENT_AUDIT_CODES) ? array() : STUDENT_AUDIT_CODES;
            $latedrop_codes = is_null(STUDENT_LATEDROP_CODES) ? array() : STUDENT_LATEDROP_CODES;
            $all_valid_codes = array_merge($graded_codes, $audit_codes, $latedrop_codes);
            if (array_search($row[COLUMN_REGISTRATION], $all_valid_codes) !== false) {
                // Check that $row is associated with the course list
                if (array_search($course, $this->course_list) !== false) {
                    if (validate::validate_row($row, $row_num)) {
                        $this->data[$course][] = $row;
                        // Rows with blank emails are allowed, but they are being logged.
                        if ($row[COLUMN_EMAIL] === "") {
                            $this->log_it("Blank email found for user {$row[COLUMN_USER_ID]}, row {$row_num}.");
                        }
                    } else {
                        $this->invalid_courses[$course] = true;
                        $this->log_it(validate::$error);
                    }
                // Instead, check that the $row is associated with mapped course
                } else if (array_key_exists($course, $this->mapped_courses)) {
                    $section = $row[COLUMN_SECTION];
                    // Also verify that the section is mapped.
                    if (array_key_exists($section, $this->mapped_courses[$course])) {
                        $m_course = $this->mapped_courses[$course][$section]['mapped_course'];
                        if (validate::validate_row($row, $row_num)) {
                            $row[COLUMN_SECTION] = $this->mapped_courses[$course][$section]['mapped_section'];
                            $this->data[$m_course][] = $row;
                            // Rows with blank emails are allowed, but they are being logged.
                            if ($row[COLUMN_EMAIL] === "") {
                                $this->log_it("Blank email found for user {$row[COLUMN_USER_ID]}, row {$row_num}.");
                            }
                        } else {
                            $this->invalid_courses[$m_course] = true;
                            $this->log_it(validate::$error);
                        }
                    }
                }
            }

            $row = fgetcsv($this->fh, 0, CSV_DELIM_CHAR);
            $row_num++;
        }

        /* ---------------------------------------------------------------------
        There may be "fake" or "practice" courses in Submitty that shouldn't be
        altered by the autofeed.  These courses will have no enrollments in the
        csv file as these courses are not recognized by the registrar.
        --------------------------------------------------------------------- */

        // Filter out any "empty" courses so they are not processed.
        // There shouldn't be any "empty" course data, but this is just in case.
        $this->data = array_filter($this->data, function($course) { return !empty($course); }, 0);

        // Most runtime involves the database, so we'll release the CSV now.
        $this->close_csv();

        // Done.
        return true;
    }

    /**
     * Users cannot be registered to the same course multiple times.
     *
     * Any course with a user registered more than once is flagged invalid as
     * it is indicative of data errors from the CSV file.
     *
     * @return bool always TRUE
     */
    private function check_for_duplicate_user_ids() {
        foreach($this->data as $course => $rows) {
            $user_ids = null;
            $d_rows = null;
            // Returns FALSE (as in there is an error) when duplicate IDs are found.
            // However, a duplicate ID does not invalidate a course.  Instead, the
            // first enrollment is accepted, the other enrollments are discarded,
            // and the event is logged.
            if (validate::check_for_duplicate_user_ids($rows, $user_ids, $d_rows) === false) {
                foreach($d_rows as $user_id => $userid_rows) {
                    $length = count($userid_rows);
                    for ($i = 1; $i < $length; $i++) {
                        unset($this->data[$course][$userid_rows[$i]]);
                    }
                }

                $msg = "Duplicate user IDs detected in {$course} data: ";
                $msg .= implode(", ", $user_ids);
                $this->log_it($msg);
            }
        }

        return true;
    }

    private function check_for_excessive_dropped_users() {
        $is_validated = true;
        $invalid_courses = array(); // intentional local array
        $ratio = 0;
        $diff = 0;
        foreach($this->data as $course => $rows) {
            if (!validate::check_for_excessive_dropped_users($rows, $this->semester, $course, $diff, $ratio)) {
                $invalid_courses[] = array('course' => $course, 'diff' => $diff, 'ratio' => round(abs($ratio), 3));
                $is_validated = false;
            }
        }

        if (!empty($invalid_courses)) {
            usort($invalid_courses, function($a, $b) { return $a['course'] <=> $b['course']; });
            $msg = "The following course(s) have an excessive ratio of dropped students.\n  Stats show mapped courses combined in base courses.\n";
            array_unshift($invalid_courses, array('course' => "COURSE", 'diff' => "DIFF", 'ratio' => "RATIO")); // Header
            foreach ($invalid_courses as $invalid_course) {
                $msg .= "    " .
                        str_pad($invalid_course['course'], 18, " ", STR_PAD_RIGHT) .
                        str_pad($invalid_course['diff'], 6, " ", STR_PAD_LEFT) .
                        str_pad($invalid_course['ratio'], 8, " ", STR_PAD_LEFT) .
                        PHP_EOL;
            }
            $msg .= "  No upsert performed on any/all courses in Submitty due to suspicious data sheet.";

            $this->log_it($msg);
            return false;
        }

        return true;
    }

    /**
     * Call db::upsert to process CSV data to DB
     *
     * @return bool Always true
     */
    private function upsert_data() {
        foreach ($this->data as $course => $rows) {
            if (db::upsert($this->semester, $course, $rows) === false) {
                $this->log_it(db::$error);
            }
        }

        // Done.
        return true;
    }

    /**
     * Remove process records for a specific course due to a problem with CSV data.
     *
     * If a problem with CSV data is detected, the entire course will not be
     * processed to preserve data integrity.  This is done by removing all
     * course related records from $this->data.
     * Both $this->data and $this->invalid_courses are indexed by course code,
     * so removing course data is trivially accomplished by array_diff_key().
     *
     * @param string $course Course being removed from process records.
     */
    private function invalidate_courses() {
        if (!empty($this->invalid_courses)) {
            // Remove course data for invalid courses.
            $this->data = array_diff_key($this->data, $this->invalid_courses);

            // Log what courses have been flagged invalid.
            $msg = "The following courses were not processed: ";
            $msg .= implode(", ", array_keys($this->invalid_courses));
            $this->log_it($msg);
        }

        // Done.
        return true;
    }

    /**
     * Open auto feed CSV data file.
     *
     * @return boolean Indicates success/failure of opening and locking CSV file.
     */
    private function open_csv() {
        $this->fh = fopen(CSV_FILE, "r");
        if ($this->fh === false) {
            $this->log_it("Could not open CSV file.");
            return false;
        }
    }

    /** Close CSV file */
    private function close_csv() {
        if (is_resource($this->fh) && get_resource_type($this->fh) === "stream") {
            fclose($this->fh);
        }
    }

    /**
     * log msg queue holds messages intended for email and text logs.
     *
     * @param string $msg Message to write to log file
     */
    private function log_it($msg) {
        $this->log_msg_queue .= date('m/d/y H:i:s : ', time()) . $msg . PHP_EOL;
    }
}

// EOF
?>
