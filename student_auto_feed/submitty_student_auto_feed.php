#!/usr/bin/env php
<?php
/**
 * submitty_student_auto_feed.php
 *
 * This script will read a student enrollment CSV feed provided by the campus
 * registrar or data warehouse and "upsert" (insert/update) the feed into
 * Submitty's course databases.  Requires pgsql extension.
 *
 * @author Peter Bailie, Rensselaer Polytechnic Institute
 */

namespace ssaf;
require __DIR__ . "/config.php";
require __DIR__ . "/ssaf_cli.php";
require __DIR__ . "/ssaf_db.php";
require __DIR__ . "/ssaf_validate.php";
require __DIR__ . "/ssaf_rcos.php";

// Important: Make sure we are running from CLI
if (php_sapi_name() !== "cli") {
    die("This is a command line tool.\n");
}

$proc = new submitty_student_auto_feed();
$proc->go();
exit;

/** primary process class */
class submitty_student_auto_feed {
    /** File handle to read CSV */
    private $fh;
    /** Semester code */
    private string $semester;
    /** List of courses registered in Submitty */
    private array $course_list;
    /** Describes how courses are mapped from one to another */
    private array $mapped_courses;
    /** Describes courses/sections that are duplicated to other courses/sections */
    private array $crn_copymap;
    /** Courses with invalid data. */
    private array $invalid_courses;
    /** All CSV data to be upserted */
    private array $data;
    /** Ongoing string of messages to write to logfile */
    private string $log_msg_queue;
    /** For special cases involving Renssealer Center for Open Source */
    private object $rcos;

    /** Init properties.  Open DB connection.  Open CSV file. */
    public function __construct() {
        $this->log_msg_queue = "";

        // Get semester from CLI arguments.
        $opts = cli_args::parse_args();
        if (array_key_exists('l', $opts)) {
            $this->log_it("Logging test requested.  There is no actual error to report.");
            $this->shutdown();
            exit(0);
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

        if (!$this->open_data_csv(CSV_FILE)) {
            // Error is already in log queue.
            $this->shutdown();
            exit(1);
        }

        if (!db::open($db_host, $db_user, $db_password)) {
            $this->log_it("Error: Cannot connect to Submitty DB.");
            $this->shutdown();
            exit(1);
        }

        // Get course list
        $error = null;
        $this->course_list = db::get_course_list($this->semester, $error);
        if ($this->course_list === false) {
            $this->log_it($error);
            $this->shutdown();
            exit(1);
        }

        // Get mapped_courses list (when one class is merged into another)
        $this->mapped_courses = db::get_mapped_courses($this->semester, $error);
        if ($this->mapped_courses === false) {
            $this->log_it($error);
            $this->shutdown();
            exit(1);
        }

        // Get CRN shared courses/sections (when a course/section is copied to another course/section)
        $this->crn_copymap = $this->read_crn_copymap();

        // Helper object for special-cases involving RCOS.
        $this->rcos = new rcos();

        // Init other properties.
        $this->invalid_courses = [];
        $this->data = [];
    }

    public function __destruct() {
        $this->shutdown();
    }

    private function shutdown() {
        db::close();
        $this->close_data_csv();

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
        case $this->filter_duplicate_registrations():
            // Never returns false.  Error messages are already in log queue.
            break;
        case $this->invalidate_courses():
            // Should do nothing when $this->invalid_courses is empty
            $this->log_it("Error when removing data from invalid courses.");
            break;
        case $this->process_crn_copymap():
            // Never returns false, so no error to log.
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

        $graded_reg_codes = STUDENT_REGISTERED_CODES;
        $audit_reg_codes = is_null(STUDENT_AUDIT_CODES) ? [] : STUDENT_AUDIT_CODES;
        $latedrop_reg_codes = is_null(STUDENT_LATEDROP_CODES) ? [] : STUDENT_LATEDROP_CODES;
        $all_valid_reg_codes = array_merge($graded_reg_codes, $audit_reg_codes, $latedrop_reg_codes);
        $unexpected_term_codes = [];

        // Read and assign csv rows into $this->data array
        $row = fgetcsv($this->fh, 0, CSV_DELIM_CHAR);
        while(!feof($this->fh)) {
            // Trim whitespace from all fields in $row.
            array_walk($row, function(&$val, $key) { $val = trim($val); });

            // Remove any leading zeroes from "integer" registration sections.
            if (ctype_digit($row[COLUMN_SECTION])) $row[COLUMN_SECTION] = ltrim($row[COLUMN_SECTION], "0");

            // Course is comprised of an alphabetic prefix and a numeric suffix.
            $course = strtolower($row[COLUMN_COURSE_PREFIX] . $row[COLUMN_COURSE_NUMBER]);

            switch(true) {
            // Check that $row has an appropriate student registration.
            case array_search($row[COLUMN_REGISTRATION], $all_valid_reg_codes) === false:
                // Skip row.
                break;

            // Check that $row is associated with the current term (if check is enabled)
            // Assume this check is OK, when EXPECTED_TERM_CODE is null (check disabled)
            case is_null(EXPECTED_TERM_CODE) ? false : $row[COLUMN_TERM_CODE] !== EXPECTED_TERM_CODE:
                // Note the unexpected term code for logging, if not already noted.
                if (array_search($row[COLUMN_TERM_CODE], $unexpected_term_codes) === false) {
                    $unexpected_term_codes[] = $row[COLUMN_TERM_CODE];
                }
                break;

            // Check that $row is associated with the course list.
            case array_search($course, $this->course_list) !== false:
                if (validate::validate_row($row, $row_num)) {
                    // Check (and perform) special-case RCOS registration section mapping.
                    $this->rcos->map($course, $row);

                    // Include $row
                    $this->data[$course][] = $row;

                    // $row with a blank email is included, but it is also logged.
                    if ($row[COLUMN_EMAIL] === "") {
                        $this->log_it("Blank email found for user {$row[COLUMN_USER_ID]}, row {$row_num}.");
                    }
                } else {
                    // There is a problem with $row, so log the problem and skip.
                    $this->invalid_courses[$course] = true;
                    $this->log_it(validate::$error);
                } // END if (validate::validate_row())
                break;

            // Check that the $row is associated with a mapped course.
            case array_key_exists($course, $this->mapped_courses):
                // Also verify that the section is mapped.
                $section = $row[COLUMN_SECTION];
                if (array_key_exists($section, $this->mapped_courses[$course])) {
                    $m_course = $this->mapped_courses[$course][$section]['mapped_course'];
                    if (validate::validate_row($row, $row_num)) {
                        // Do course mapping (alters registration section).
                        $row[COLUMN_SECTION] = $this->mapped_courses[$course][$section]['mapped_section'];

                        // Check (and override) for special-case RCOS registration section mapping.
                        $this->rcos->map($course, $row);

                        // Include $row.
                        $this->data[$m_course][] = $row;

                        // $row with a blank email is allowed, but it is also logged.
                        if ($row[COLUMN_EMAIL] === "") {
                            $this->log_it("Blank email found for user {$row[COLUMN_USER_ID]}, row {$row_num}.");
                        }
                    } else {
                        // There is a problem with $row, so log the problem and skip.
                        $this->invalid_courses[$m_course] = true;
                        $this->log_it(validate::$error);
                    }
                }
                break;

            default:
                // Skip row by default.
                break;

            } // END switch (true)

            $row = fgetcsv($this->fh, 0, CSV_DELIM_CHAR);
            $row_num++;
        }

        // Log any unexpected term codes.
        // This may provide a notice that the next term's data is available.
        if (!empty($unexpected_term_codes)) {
            $msg = "Unexpected term codes in CSV: ";
            $msg .= implode(", ", $unexpected_term_codes);
            $this->log_it($msg);
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
        $this->close_data_csv();

        // Done.
        return true;
    }

    /**
     * Students cannot be registered to the same course multiple times.
     *
     * If multiple registrations for the same student and course are found, the first instance is allowed to be
     * upserted to the database.  All other instances are removed from the data set and therefore not upserted.
     */
    private function filter_duplicate_registrations(): true {
        foreach($this->data as $course => &$rows) {
            usort($rows, function($a, $b) { return $a[COLUMN_USER_ID] <=> $b[COLUMN_USER_ID]; });
            $duplicated_ids = [];
            $num_rows = count($rows);

            // We are iterating from bottom to top through a course's data set.  Should we find a duplicate registration
            // and unset it from the array, (1) we are unsetting duplicates starting from the bottom, (2) which preserves
            // the first entry among duplicate entries, and (3) we do not make a comparison with a null key.
            for ($j = $num_rows - 1, $i = $j - 1; $i >= 0; $i--, $j--) {
                if ($rows[$i][COLUMN_USER_ID] === $rows[$j][COLUMN_USER_ID]) {
                    $duplicated_ids[] = $rows[$j][COLUMN_USER_ID];
                    unset($rows[$j]);
                }
            }

            if (count($duplicated_ids) > 0) {
                array_unique($duplicated_ids, SORT_STRING);
                $msg = "Duplicate user IDs detected in {$course} data: ";
                $msg .= implode(", ", $duplicated_ids);
                $this->log_it($msg);
            }
        }

        return true;
    }

    /**
     * An excessive ratio of dropped users may indicate bad data in the CSV.
     *
     * The confidence ratio is defined in config.php as VALIDATE_DROP_RATIO.
     * Confidence value is a float between 0 and 1.0.
     *
     * @see validate::check_for_excessive_dropped_users()  Found in ssaf_validate.php
     *
     * @return bool True when check is within confidence ratio.  False otherwise.
     */
    private function check_for_excessive_dropped_users() {
        $is_validated = true;
        $invalid_courses = []; // intentional local array
        $ratio = 0;
        $diff = 0;
        foreach($this->data as $course => $rows) {
            if (!validate::check_for_excessive_dropped_users($rows, $this->semester, $course, $diff, $ratio)) {
                $invalid_courses[] = ['course' => $course, 'diff' => $diff, 'ratio' => round(abs($ratio), 3)];
                $is_validated = false;
            }
        }

        if (!empty($invalid_courses)) {
            usort($invalid_courses, function($a, $b) { return $a['course'] <=> $b['course']; });
            $msg = "The following course(s) have an excessive ratio of dropped students.\n  Stats show mapped courses combined in base courses.\n";
            array_unshift($invalid_courses, ['course' => "COURSE", 'diff' => "DIFF", 'ratio' => "RATIO"]); // Header
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
     * @return bool true upon completion.
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
     * Read crn copymap csv into array.
     *
     * CRN copymap is a csv that maps what courses/sections are duplicated to
     * other courses/sections.  This is useful for duplicating enrollments to
     * "practice" courses (of optional participation) that are not officially
     * in the school's course catalog.
     * *** MUST BE RUN AFTER FILLING $this->course_list
     *
     * @see CRN_COPYMAP_FILE located in config.php
     * @see db::get_course_list() located in ssaf_db.php
     * @return array copymap array, or empty array when copymap disabled or copymap file open failure.
     */
    private function read_crn_copymap() {
        // Skip this function and return empty copymap array when CRN_COPYMAP_FILE is null
        if (is_null(CRN_COPYMAP_FILE)) return [];

        // Insert "_{$this->semester}" right before file extension.
        // e.g. When term is "f23", "/path/to/copymap.csv" becomes "/path/to/copymap_f23.csv"
        $filename = preg_replace("/([^\/]+?)(\.[^\/\.]*)?$/", "$1_{$this->semester}$2", CRN_COPYMAP_FILE);

        if (!is_file($filename)) {
            $this->log_it("crn copymap file not found: {$filename}");
            return [];
        }

        $fh = fopen($filename, 'r');
        if ($fh === false) {
            $this->log_it("Failed to open crn copymap file: {$filename}");
            return [];
        }

        // source course  == $row[0]
        // source section == $row[1]
        // dest course    == $row[2]
        // dest section   == $row[3]
        $arr = [];
        $row = fgetcsv($fh, 0, ",");
        while (!feof($fh)) {
            if (in_array($row[2], $this->course_list, true)) {
                $arr[$row[0]][$row[1]] = ['course' => $row[2], 'section' => $row[3]];
            } else {
                $this->log_it("Duplicated course {$row[2]} not created in Submitty.");
            }
            $row = fgetcsv($fh, 0, ",");
        }

        fclose($fh);

        if (empty($arr)) $this->log_it("No CRN copymap data could be read.");
        return $arr;
    }

    /**
     * Duplicate course enrollment data based on crn copymap.
     *
     * This should be run after all validation and error checks have been
     * performed on enrollment data.  This function will duplicate
     * enrollment data as shown in $this->crn_copymap array.
     *
     * @return bool always true.
     */
    private function process_crn_copymap() {
        // Skip when there is no crn copymap data. i.e. There are no courses being duplicated.
        if (is_null(CRN_COPYMAP_FILE) || empty($this->crn_copymap)) return true;

        foreach($this->data as $course=>$course_data) {
            // Is the course being duplicated?
            if (array_key_exists($course, $this->crn_copymap)) {
                foreach($course_data as $row) {
                    $section = $row[COLUMN_SECTION];
                    // What section(s) are being duplicated?
                    if (array_key_exists('all', $this->crn_copymap[$course])) {
                        $copymap_course = $this->crn_copymap[$course]['all']['course'];
                        $this->data[$copymap_course][] = $row;
                        $key = array_key_last($this->data[$copymap_course]);
                        // We are not duplicating the CRN data column.
                        $this->data[$copymap_course][$key][COLUMN_REG_ID] = "";
                    } elseif (array_key_exists($section, $this->crn_copymap[$course])) {
                        $copymap_course = $this->crn_copymap[$course][$section]['course'];
                        $copymap_section = $this->crn_copymap[$course][$section]['section'];
                        $this->data[$copymap_course][] = $row;
                        $key = array_key_last($this->data[$copymap_course]);
                        $this->data[$copymap_course][$key][COLUMN_SECTION] = $copymap_section;
                        // We are not duplicating the CRN data column.
                        $this->data[$copymap_course][$key][COLUMN_REG_ID] = "";
                    }
                }
            }
        }

        return true;
    }

    /**
     * Open a CSV file.
     *
     * Multiple files may be opened as a stack of file handles.
     *
     * @return boolean|int False when file couldn't be opened, or Int $key of the opened file handle.
     */
    private function open_data_csv() {
        $this->fh = fopen(CSV_FILE, "r");
        if ($this->fh === false) {
            $this->log_it(sprintf("Could not open file: %s", CSV_FILE));
            return false;
        }

        return true;
    }

    /** Close most recent opened CSV file */
    private function close_data_csv() {
        if (is_resource($this->fh) && get_resource_type($this->fh) === "stream") {
            fclose($this->fh);
        } else {
            $this->fh = null;
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
