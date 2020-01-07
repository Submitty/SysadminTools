#!/usr/bin/env php
<?php

/**
 * submitty_student_auto_feed.php
 *
 * This script will read a student enrollment CSV feed provided by the campus
 * registrar or data warehouse and "upsert" (insert/update) the feed into
 * Submitty's course databases.
 *
 * Process flow code exists in the constructor, so all that is needed is to
 * (1) include "config.php" so that constants are defined.
 *     (and make sure that constants are properly configured)
 * (2) instantiate this class to process a data feed.
 *
 * You must specify the term on the command line with "-t".
 * For example:
 *
 * ./submitty_student_auto_feed.php -t s18
 *
 * Will run the auto feed for the Spring 2018 semester.
 *
 * Requires minimum PHP version 7.0 with pgsql, and iconv extensions.
 *
 * @author Peter Bailie, Systems Programmer (RPI research computing)
 */

require "config.php";
new submitty_student_auto_feed();

/** primary process class */
class submitty_student_auto_feed {
    /** @staticvar string $semester semester code */
    private static $semester;

    /** @staticvar array $course_list list of courses registered in Submitty */
    private static $course_list;

    /** @staticvar array $course_mappings list that describes courses mapped from one to another */
    private static $course_mappings;

    /** @staticvar resource $db "master" Submitty database connection */
    private static $db;

    /** @staticvar resource $fh file handle to read CSV */
    private static $fh = false;

    /** @staticvar boolean $fh_locked set to true when CSV file attains a lock */
    private static $fh_locked = false;

    /** @staticvar array $data all CSV data to be upserted */
    private static $data = array('users' => array(), 'courses_users' => array());

    /** @staticvar string $log_msg_queue ongoing string of messages to write to logfile */
    private static $log_msg_queue = "";

    public function __construct() {

        //Important: Make sure we are running from CLI
        if (PHP_SAPI !== "cli") {
            die("This is a command line tool.");
        }

        //Get semester from CLI arguments.
        self::$semester = cli_args::parse_args();

        //Connect to "master" submitty DB.
        $db_host     = DB_HOST;
        $db_user     = DB_LOGIN;
        $db_password = DB_PASSWORD;
        self::$db = pg_connect("host={$db_host} dbname=submitty user={$db_user} password={$db_password} sslmode=require");

        //Make sure there's a DB connection to Submitty.
        if (pg_connection_status(self::$db) !== PGSQL_CONNECTION_OK) {
            $this->log_it("Error: Cannot connect to submitty DB");
        } else {
            //Get course list
            self::$course_list = $this->get_participating_course_list();

            //Create arrays to hold enrollment data by course.
            foreach (self::$course_list as $course) {
                self::$data['courses_users'][$course] = array();
            }

            //Get mapped_courses list (when one class is merged into another)
            self::$course_mappings = $this->get_course_mappings();

            //Auto-run class processes by executing them in constructor.
            //Halts when FALSE is returned by a method.
            switch(false) {
            //Load CSV data
            case $this->open_csv():
                $this->log_it("Student CSV data could not be read.");
                exit(1);
            //Validate CSV data (anything pertinent is stored in self::$data property)
            case $this->validate_csv():
                $this->log_it("Student CSV data failed validation.  No data upsert performed.");
                exit(1);
            //Data upsert
            case $this->upsert_psql():
                $this->log_it("Error during upsert of data.");
                exit(1);
            }
        }

        //END EXECUTION
        exit(0);
    }

    public function __destruct() {

        //Graceful cleanup.

        //Close DB connection, if it exists.
        if (pg_connection_status(self::$db) === PGSQL_CONNECTION_OK) {
            pg_close(self::$db);
        }

        //Unlock CSV, if it is locked.
        if (self::$fh_locked) {
            flock(self::$fh, LOCK_UN);
        }

        //Close CSV file, if it is open.
        if (self::$fh !== false) {
            fclose(self::$fh);
        }

        //Output logs, if any.
        if (!empty(self::$log_msg_queue)) {
            if (!is_null(ERROR_EMAIL)) {
                error_log(self::$log_msg_queue, 1, ERROR_EMAIL);  //to email
            }

            error_log(self::$log_msg_queue, 3, ERROR_LOG_FILE);  //to file
        }
    }

    /**
     * Run some error checks and copy file data to class property.
     *
     * @access private
     * @return boolean  indicates success that CSV data passes validation tests
     */
    private function validate_csv() {

        if (self::$fh === false) {
            $this->log_it("CSV file handle invalid when starting CSV data validation.");
            return false;
        }

        //Consume and discard header row, if it exists, and init $row_number.
        if (HEADER_ROW_EXISTS) {
            fgets(self::$fh);
            $row_number = 1;
        } else {
            $row_number = 0;
        }

        //Prepare validation
        //$validation_flag will invalidate the entire CSV when set to false.
        //A log of all failing rows is desired, so we do not bail out of this process at the first sign of invalidation.
        $validation_flag = true;
        $validate_num_fields = VALIDATE_NUM_FIELDS;
        $rpi_found_non_empty_row = false;  //RPI edge case flag where top row(s) of CSV might have empty data.

        while (($row = fgetcsv(self::$fh, 0, CSV_DELIM_CHAR)) !== false) {
            //Current row number (needed for error logging).
            $row_number++;

            //Trim whitespace from all fields in $row
            array_walk($row, 'trim');

            //BEGIN VALIDATION
            //Invalidate any row that doesn't have requisite number of fields.  Do this, first.
            //Invalidation will disqualify the data file to protect DB data integrity.
            $num_fields = count($row);
            if ($num_fields !== $validate_num_fields) {
                $this->log_it("Row {$row_number} has {$num_fields} columns.  {$validate_num_fields} expected.  CSV disqualified.");
                $validation_flag = false;
                continue;
            } else if (empty(array_filter($row, function($field) { return !empty($field); }))) {
                //RPI edge case to skip a correctly sized row of all empty fields — at the top of a data file, before proper data is read — without invalidating the whole data file.
                if (!$rpi_found_non_empty_row) {
                    $this->log_it("Row {$row_number} is correct size ({$validate_num_fields}), but all columns are empty — at top of CSV.  Ignoring row.");
                    continue;
                } else {
                    //Correctly sized empty row below data row(s) — invalidate data file.
                    $this->log_it("Row {$row_number} is correct size ({$validate_num_fields}), but all columns are empty — below a non-empty data row.  CSV disqualified.");
                    $validation_flag = false;
                    continue;
                }
            }

            $rpi_found_non_empty_row = true;
            $course = strtolower($row[COLUMN_COURSE_PREFIX]) . $row[COLUMN_COURSE_NUMBER];
            // Remove any leading zeroes from "integer" registration sections.
            $section = (ctype_digit($row[COLUMN_SECTION])) ? ltrim($row[COLUMN_SECTION], "0") : $row[COLUMN_SECTION];

            //Row validation filters.  If any prove false, row is discarded.
            switch(false) {
            //Check to see if course is participating in Submitty or a mapped course.
            case (in_array($course, self::$course_list) || array_key_exists($course, self::$course_mappings)):
                continue 2;
            //Check that row shows student is registered.
            case (in_array($row[COLUMN_REGISTRATION], STUDENT_REGISTERED_CODES)):
                continue 2;
            }

            //Row is OK, next validate row columns.
            //If any column is invalid, the row is skipped and the entire data file is disqualified.
            switch(false) {
            //Check term code (skips when set to null).
            case ((is_null(EXPECTED_TERM_CODE)) ? true : ($row[COLUMN_TERM_CODE] === EXPECTED_TERM_CODE)):
                $this->log_it("Row {$row_number} failed validation for mismatched term code.");
                $validation_flag = false;
                continue 2;
            //User ID must contain only lowercase alpha, numbers, underscore, and hyphen
            case boolval((preg_match("~^[a-z0-9_\-]+$~", $row[COLUMN_USER_ID]))):
                $this->log_it("Row {$row_number} failed user ID validation ({$row[COLUMN_USER_ID]}).");
                $validation_flag = false;
                continue 2;
            //First name must be alpha characters, white-space, or certain punctuation.
            case boolval((preg_match("~^[a-zA-Z'`\-\. ]+$~", $row[COLUMN_FIRSTNAME]))):
                $this->log_it("Row {$row_number} failed validation for student first name ({$row[COLUMN_FIRSTNAME]}).");
                $validation_flag = false;
                continue 2;
            //Last name must be alpha characters, white-space, or certain punctuation.
            case boolval((preg_match("~^[a-zA-Z'`\-\. ]+$~", $row[COLUMN_LASTNAME]))):
                $this->log_it("Row {$row_number} failed validation for student last name ({$row[COLUMN_LASTNAME]}).");
                $validation_flag = false;
                continue 2;
            //Student registration section must be alphanumeric, '_', or '-'.
            case boolval((preg_match("~^[a-zA-Z0-9_\-]+$~", $row[COLUMN_SECTION]))):
                $this->log_it("Row {$row_number} failed validation for student section ({$section}).");
                $validation_flag = false;
                continue 2;
            //Check email address for appropriate format. e.g. "student@university.edu", "student@cs.university.edu", etc.
            case boolval((preg_match("~^[^(),:;<>@\\\"\[\]]+@(?!\-)[a-zA-Z0-9\-]+(?<!\-)(\.[a-zA-Z0-9]+)+$~", $row[COLUMN_EMAIL]))):
                $this->log_it("Row {$row_number} failed validation for student email ({$row[COLUMN_EMAIL]}).");
                $validation_flag = false;
                continue 2;
            }

            /* -----------------------------------------------------------------
             * $row successfully validated.  Include it.
             * NOTE: Most cases, $row is associated EITHER as a registered
             *       course or as a mapped course, but it is possible $row is
             *       associated as BOTH a registered course and a mapped course.
             * -------------------------------------------------------------- */

            //Include $row in self::$data as a registered course, if applicable.
            if (in_array($course, self::$course_list)) {
                $this->include_row($row, $course, $section);
            }

            //Include $row in self::$data as a mapped course, if applicable.
            if (array_key_exists($course, self::$course_mappings)) {
                if (array_key_exists($section, self::$course_mappings[$course])) {
                    $tmp_course  = $course;
                    $tmp_section = $section;
                    $course = self::$course_mappings[$tmp_course][$tmp_section]['mapped_course'];
                    $section = self::$course_mappings[$tmp_course][$tmp_section]['mapped_section'];
                    $this->include_row($row, $course, $section);
                } else {
                    //Course mapping is needed, but section is not correctly entered in DB.
                    //Invalidate data file so that upsert is not performed as a safety precaution for system data integrity.
                    $this->log_it("Row {$index}: {$course} has been mapped.  Section {$section} is in feed, but not mapped.");
                    $validation_flag = false;
                }
            }
        } //END iterating over CSV data.

        //Bulk of proccesing time is during database upsert, so we might as well
        //release the CSV now that we are done reading it.
        if (self::$fh_locked && flock(self::$fh, LOCK_UN)) {
            self::$fh_locked = false;
        }

        if (self::$fh !== false && fclose(self::$fh)) {
            self::$fh = false;
        }

        /* ---------------------------------------------------------------------
         * In the event that a course is registered with Submitty, but that
         * course is NOT in the CSV data, that course needs to be removed
         * (filtered out) from self::$data or else all of its student enrollment
         * will be moved to the NULL section during upsert.  This is determined
         * when a course has zero rows of student enrollment.
         * ------------------------------------------------------------------ */

        self::$data['courses_users'] = array_filter(self::$data['courses_users'], function($course) { return !empty($course); }, 0);

        /* ---------------------------------------------------------------------
         * Individual students can be listed on multiple rows if they are
         * enrolled in two or more courses.  'users' table needs to be
         * deduplicated.  Deduplication will be keyed by 'user_id' since that is
         * also the table's primary key.  Note that 'courses_users' should NOT
         * be deduplicated.
         * ------------------------------------------------------------------ */

        if ($this->deduplicate('users', 'user_id') === false) {

            //Deduplication didn't work.  We can't proceed (set validation flag to false).
            $this->log_it("Users data deduplication encountered a problem.  Aborting.");
            $validation_flag = false;
        }

        //TRUE:  Data validation passed and validated data set will have at least 1 row per table.
        //FALSE: Either data validation failed or at least one table is an empty set.
        return ($validation_flag && count(self::$data['users']) > 0 && count(self::$data['courses_users']) > 0);
    }

    /**
     * Add $row to self::$data.
     *
     * This should only be called AFTER $row is successfully validated.
     *
     * @param array $row data row to include
     * @param string $course course associated with data row
     * @param string $section section associated with data row
     */
    private function include_row($row, $course, $section) {

        self::$data['users'][] = array('user_id'            => $row[COLUMN_USER_ID],
                                       'user_numeric_id'    => $row[COLUMN_NUMERIC_ID],
                                       'user_firstname'     => $row[COLUMN_FIRSTNAME],
                                       'user_preferredname' => $row[COLUMN_PREFERREDNAME],
                                       'user_lastname'      => $row[COLUMN_LASTNAME],
                                       'user_email'         => $row[COLUMN_EMAIL]);

        //Group 'courses_users' data by individual courses, so
        //upserts can be transacted per course.  This helps prevent
        //FK violations blocking upserts for other courses.
        self::$data['courses_users'][$course][] = array('semester'             => self::$semester,
                                                        'course'               => $course,
                                                        'user_id'              => $row[COLUMN_USER_ID],
                                                        'user_group'           => 4,
                                                        'registration_section' => $section,
                                                        'manual_registration'  => 'FALSE');
    }

    /**
     * Retrieves a list of participating courses.
     *
     * Submitty can handle multiple courses.  This function retrieves a list of
     * participating courses from the database.
     *
     * @access private
     * @return array  list of courses registered in Submitty
     */
    private function get_participating_course_list() {

        //EXPECTED: self::$db has an active/open Postgres connection.
        if (pg_connection_status(self::$db) !== PGSQL_CONNECTION_OK) {
            $this->log_it("Error: not connected to Submitty DB when retrieving active course list.");
            return false;
        }

        //SQL query code to get course list.
        $sql = <<<SQL
SELECT course
FROM courses
WHERE semester=$1
AND status=1
SQL;

        //Get all courses listed in DB.
        $res = pg_query_params(self::$db, $sql, array(self::$semester));

        //Error check
        if ($res === false) {
            $this->log_it("RETRIEVE PARTICIPATING COURSES : " . pg_last_error(self::$db));
            return false;
        }

        //Return course list.
        return pg_fetch_all_columns($res, 0);
    }

    /**
     * Merge mapped courses into one
     *
     * Sometimes a course is combined with undergrads/grad students, or a course
     * is crosslisted, but meets collectively.  Course mappings will "merge"
     * courses into a single master course.  This can also be used to duplicate
     * course enrollment from one course to another (e.g. an intro course may
     * duplicate enrollment to an optional extra-lessons pseudo-course).
     *
     * @access private
     * @return array  a list of "course mappings" (where one course is merged into another)
     */
    private function get_course_mappings() {

        //EXPECTED: self::$db has an active/open Postgres connection.
        if (pg_connection_status(self::$db) !== PGSQL_CONNECTION_OK) {
            $this->log_it("Error: not connected to Submitty DB when retrieving course mappings list.");
            return false;
        }

        //SQL query code to retrieve course mappinsg
        $sql = <<<SQL
SELECT course, registration_section, mapped_course, mapped_section
FROM mapped_courses
WHERE semester=$1
SQL;

        //Get all mappings from DB.
        $res = pg_query_params(self::$db, $sql, array(self::$semester));

        //Error check
        if ($res === false) {
            $this->log_it("RETRIEVE MAPPED COURSES : " . pg_last_error(self::$db));
            return false;
        }

        //Check for no mappings returned.
        $results = pg_fetch_all($res);
        if (empty($results)) {
            return array();
        }

        //Describe how auto-feed data is translated by mappings.
        $mappings = array();
        foreach ($results as $row) {
            $course = $row['course'];
            $registration_section = $row['registration_section'];
            $mapped_course = $row['mapped_course'];
            $mapped_section = $row['mapped_section'];
            $mappings[$course][$registration_section] = array('mapped_course'  => $mapped_course,
                                                              'mapped_section' => $mapped_section);
        }

        return $mappings;
    }

    /**
     * Open auto feed CSV data file.
     *
     * @access private
     * @return boolean  indicates success/failure of opening and locking CSV file.
     */
    private function open_csv() {

        self::$fh = fopen(CSV_FILE, "r");
        if (self::$fh !== false) {
            if (flock(self::$fh, LOCK_SH, $wouldblock)) {
                self::$fh_locked = true;
                return true;
            } else if ($wouldblock === 1) {
                $this->logit("Another process has locked the CSV.");
                return false;
            } else {
                $this->logit("CSV not blocked, but still could not attain lock for reading.");
                return false;
            }
        } else {
            $this->log_it("Could not open CSV file.  Check config.");
            return false;
        }
    }

    /**
     * deduplicate data set by a specific column
     *
     * Users table in "Submitty" database must have a unique student per row.
     * per row.  Students in multiple courses may have multiple entries where
     * where deduplication is necessary.
     *
     * @access private
     * @param array  $subset  data subset to be deduplicated
     * @param mixed  $key  column by which rows are deduplicated
     * @return boolean TRUE when deduplication is completed.  FALSE when sorting fails.
     */
    private function deduplicate($subset = 'users', $key = 'user_id') {

        // First, sort data subset.  On success, remove duplicate rows identified by $key.
        if (usort(self::$data[$subset], function($a, $b) use ($key) { return strcmp($a[$key], $b[$key]); })) {
            $count = count(self::$data[$subset]);
            for ($i = 1; $i < $count; $i++) {
                if (self::$data[$subset][$i][$key] === self::$data[$subset][$i-1][$key]) {
                    unset(self::$data[$subset][$i-1]);
                }
            }

            //Indicate that deduplication is done.
            return true;
        }

        //Something went wrong during sort.  Abort and indicate failure.
        return false;
    }

    /**
     * "Update/Insert" data into the database.  Code works via "batch" upserts.
     *
     * Vars assigned NULL are 'inactive' placeholders for readability.
     *
     * @access private
     * @return boolean  true when upsert is complete
     */
    private function upsert_psql() {
        $sql = array('begin'    => 'BEGIN',
                     'commit'   => 'COMMIT',
                     'rollback' => 'ROLLBACK');

        //TEMPORARY tables to hold all new values that will be "upserted"
        $sql['users']['temp_table'] = <<<SQL
CREATE TEMPORARY TABLE upsert_users (
    user_id                  VARCHAR,
    user_numeric_id          VARCHAR,
    user_firstname           VARCHAR,
    user_preferred_firstname VARCHAR,
    user_lastname            VARCHAR,
    user_email               VARCHAR
) ON COMMIT DROP
SQL;

        $sql['registration_section']['temp_table'] = <<<SQL
CREATE TEMPORARY TABLE upsert_courses_registration_sections (
    semester                VARCHAR(255),
    course                  VARCHAR(255),
    registration_section_id VARCHAR(255)
) ON COMMIT DROP
SQL;

        $sql['courses_users']['temp_table'] = <<<SQL
CREATE TEMPORARY TABLE upsert_courses_users (
    semester             VARCHAR(255),
    course               VARCHAR(255),
    user_id              VARCHAR,
    user_group           INTEGER,
    registration_section VARCHAR(255),
    manual_registration  BOOLEAN
) ON COMMIT DROP
SQL;

        //INSERT new data into temporary tables -- prepares all data to be
        //upserted in a single DB transaction.
        $sql['registration_section']['data'] = <<<SQL
INSERT INTO upsert_courses_registration_sections VALUES ($1,$2,$3)
SQL;

        $sql['users']['data'] = <<<SQL
INSERT INTO upsert_users VALUES ($1,$2,$3,$4,$5,$6);
SQL;

        $sql['courses_users']['data'] = <<<SQL
INSERT INTO upsert_courses_users VALUES ($1,$2,$3,$4,$5,$6)
SQL;

        //LOCK will prevent sharing collisions while upsert is in process.
        $sql['users']['lock'] = <<<SQL
LOCK TABLE users IN EXCLUSIVE MODE
SQL;

        $sql['registration_section']['lock'] =  <<<SQL
LOCK TABLE courses_registration_sections IN EXCLUSIVE MODE
SQL;
        $sql['courses_users']['lock'] = <<<SQL
LOCK TABLE courses_users IN EXCLUSIVE MODE
SQL;

        //UPDATE queries
        //CASE WHEN clause checks user/instructor_updated flags for permission to change
        //user_preferred_firstname column.
        $sql['users']['update'] = <<<SQL
UPDATE users
SET
    user_numeric_id=upsert_users.user_numeric_id,
    user_firstname=upsert_users.user_firstname,
    user_lastname=upsert_users.user_lastname,
    user_preferred_firstname=
        CASE WHEN user_updated=FALSE AND instructor_updated=FALSE
        THEN upsert_users.user_preferred_firstname
        ELSE users.user_preferred_firstname END,
    user_email=upsert_users.user_email
FROM upsert_users
WHERE users.user_id=upsert_users.user_id
/* AUTH: "AUTO_FEED" */
SQL;

        $sql['registration_section']['update'] = null;

        $sql['courses_users']['update'] = <<<SQL
UPDATE courses_users
SET
    semester=upsert_courses_users.semester,
    course=upsert_courses_users.course,
    user_id=upsert_courses_users.user_id,
    user_group=upsert_courses_users.user_group,
    registration_section=upsert_courses_users.registration_section,
    manual_registration=upsert_courses_users.manual_registration
FROM upsert_courses_users
WHERE courses_users.user_id=upsert_courses_users.user_id
AND courses_users.course=upsert_courses_users.course
AND courses_users.semester=upsert_courses_users.semester
AND courses_users.user_group=4
AND courses_users.manual_registration=FALSE
SQL;

        //INSERT queries
        $sql['users']['insert'] = <<<SQL
INSERT INTO users (
    user_id,
    user_numeric_id,
    user_firstname,
    user_lastname,
    user_preferred_firstname,
    user_email
) SELECT
    upsert_users.user_id,
    upsert_users.user_numeric_id,
    upsert_users.user_firstname,
    upsert_users.user_lastname,
    upsert_users.user_preferred_firstname,
    upsert_users.user_email
FROM upsert_users
LEFT OUTER JOIN users
    ON users.user_id=upsert_users.user_id
WHERE users.user_id IS NULL
SQL;

        $sql['registration_section']['insert'] = <<<SQL
INSERT INTO courses_registration_sections (
    semester,
    course,
    registration_section_id
) SELECT DISTINCT
    upsert_courses_registration_sections.semester,
    upsert_courses_registration_sections.course,
    upsert_courses_registration_sections.registration_section_id
FROM upsert_courses_registration_sections
ON CONFLICT DO NOTHING
SQL;

        $sql['courses_users']['insert'] = <<<SQL
INSERT INTO courses_users (
    semester,
    course,
    user_id,
    user_group,
    registration_section,
    manual_registration
) SELECT
    upsert_courses_users.semester,
    upsert_courses_users.course,
    upsert_courses_users.user_id,
    upsert_courses_users.user_group,
    upsert_courses_users.registration_section,
    upsert_courses_users.manual_registration
FROM upsert_courses_users
LEFT OUTER JOIN courses_users
    ON upsert_courses_users.user_id=courses_users.user_id
    AND upsert_courses_users.course=courses_users.course
    AND upsert_courses_users.semester=courses_users.semester
WHERE courses_users.user_id IS NULL
AND courses_users.course IS NULL
AND courses_users.semester IS NULL
SQL;

        //We also need to move students no longer in auto feed to the NULL registered section
        //Make sure this only affects students (AND users.user_group=$1)

        //Nothing to update in courses_registration_sections and users table.
        $sql['users']['dropped_students'] = null;
        $sql['registration_section']['dropped_students'] = null;
        $sql['courses_users']['dropped_students'] = <<<SQL
UPDATE courses_users
SET registration_section=NULL
FROM (
    SELECT
        courses_users.user_id,
        courses_users.course,
        courses_users.semester
    FROM courses_users
    LEFT OUTER JOIN upsert_courses_users
        ON courses_users.user_id=upsert_courses_users.user_id
    WHERE upsert_courses_users.user_id IS NULL
    AND courses_users.course=$1
    AND courses_users.semester=$2
    AND courses_users.user_group=4
) AS dropped
WHERE courses_users.user_id=dropped.user_id
AND courses_users.course=dropped.course
AND courses_users.semester=dropped.semester
AND courses_users.manual_registration=FALSE
SQL;

        //Transactions
        //'users' table
        pg_query(self::$db, $sql['begin']);
        pg_query(self::$db, $sql['users']['temp_table']);
        //fills temp table with batch upsert data.
        foreach(self::$data['users'] as $row) {
            pg_query_params(self::$db, $sql['users']['data'], $row);
        }
        pg_query(self::$db, $sql['users']['lock']);
        switch (false) {
        case pg_query(self::$db, $sql['users']['update']):
            $this->log_it("USERS (UPDATE) : " . pg_last_error(self::$db));
            pg_query(self::$db, $sql['rollback']);
            break;
        case pg_query(self::$db, $sql['users']['insert']):
            $this->log_it("USERS (INSERT) : " . pg_last_error(self::$db));
            pg_query(self::$db, $sql['rollback']);
            break;
        default:
            pg_query(self::$db, $sql['commit']);
            break;
        }

        //'courses_registration_sections' table
        //'SELECT semesters' MUST be processed before 'courses_users'
        //in order to satisfy database referential integrity.
        foreach(self::$data['courses_users'] as $course_name => $course_data) {
            pg_query(self::$db, $sql['begin']);
            pg_query(self::$db, $sql['registration_section']['temp_table']);
            //fills temp table with batch upsert data.
            foreach ($course_data as $row) {
                pg_query_params(self::$db, $sql['registration_section']['data'], array($row['semester'], $row['course'], $row['registration_section']));
            }
            pg_query(self::$db, $sql['registration_section']['lock']);
            switch (false) {
            case pg_query(self::$db, $sql['registration_section']['insert']):
                $this->log_it("REGISTRATION SECTION IDs (INSERT) : " . pg_last_error(self::$db));
                pg_query(self::$db, $sql['rollback']);
                break;
            default:
                pg_query(self::$db, $sql['commit']);
                break;
            }
        }

        //Process 'courses_users' tables (per course).
        foreach(self::$data['courses_users'] as $course_name => $course_data) {
            pg_query(self::$db, $sql['begin']);
            pg_query(self::$db, $sql['courses_users']['temp_table']);
            pg_query(self::$db, $sql['registration_section']['temp_table']);
            //fills registration_section temp table with batch upsert data.
            //fills courses_users temp table with batch upsert data.
            foreach($course_data as $row) {
                pg_query_params(self::$db, $sql['registration_section']['data'], array($row['semester'], $row['course'], $row['registration_section']));
                pg_query_params(self::$db, $sql['courses_users']['data'], $row);
            }
            pg_query(self::$db, $sql['courses_users']['lock']);
            switch (false) {
            case pg_query(self::$db, $sql['registration_section']['insert']):
                pg_query(self::$db, $sql['rollback']);
                break;
            case pg_query(self::$db, $sql['courses_users']['update']):
                $this->log_it(strtoupper($course_name) . " (UPDATE) : " . pg_last_error(self::$db));
                pg_query(self::$db, $sql['rollback']);
                break;
            case pg_query(self::$db, $sql['courses_users']['insert']):
                $this->log_it(strtoupper($course_name) . " (INSERT) : " . pg_last_error(self::$db));
                pg_query(self::$db, $sql['rollback']);
                break;
             case pg_query_params(self::$db, $sql['courses_users']['dropped_students'], array($course_name, self::$semester)):
                $this->log_it(strtoupper($course_name) . " (DROPPED STUDENTS) : " . pg_last_error(self::$db));
                pg_query(self::$db, $sql['rollback']);
                break;
            default:
                pg_query(self::$db, $sql['commit']);
            }
        }

        //indicate success.
        return true;
    }

    /**
     * log msg queue holds messages intended for email and text logs.
     *
     * @access private
     * @param string  $msg  message to write to log file
     */
    private function log_it($msg) {

        if (!empty($msg)) {
            self::$log_msg_queue .= date('m/d/y H:i:s : ', time()) . $msg . PHP_EOL;
        }
    }
} //END class submitty_student_auto_feed


/** @static class to read CSV from imap as file attachment */
class imap {

    /** @staticvar resource */
    private static $imap_conn;

    public static function imap_connect() {
        $hostname = IMAP_HOSTNAME;
        $port     = IMAP_PORT;
        $usermame = IMAP_USERNAME;
        $password = IMAP_PASSWORD;
        $inbox    = IMAP_INBOX;
        $options  = "/" . implode("/", IMAP_OPTIONS);
        $auth = "{{$hostname}:{$port}{$options}}{$inbox}";

        self::$imap_conn = imap_open($auth, $username, $password);
        return bool(self::$imap_conn);
    }
}

/** @static class to parse command line arguments */
class cli_args {

    /** @var array holds all CLI argument flags and their values */
    private static $args            = array();
    /** @var string usage help message */
    private static $help_usage      = "Usage: submitty_student_auto_feed.php [-h | --help] (-t term code)" . PHP_EOL;
    /** @var string short description help message */
    private static $help_short_desc = "Read student enrollment CSV and upsert to Submitty database." . PHP_EOL;
    /** @var string argument list help message */
    private static $help_args_list  = <<<HELP
Arguments:
-h, --help    Show this help message.
-t term code  Term code associated with current student enrollment.  Required.

HELP;

    /**
     * Parse command line arguments
     *
     * Called with 'cli_args::parse_args()'
     *
     * @access public
     * @return mixed term code as string or boolean false when no term code is present.
     */
    public static function parse_args() {

        self::$args = getopt('ht:', array('help'));

        switch(true) {
        case array_key_exists('h', self::$args):
        case array_key_exists('help', self::$args):
            print self::$help_usage . PHP_EOL;
            print self::$help_short_desc . PHP_EOL;
            print self::$help_args_list . PHP_EOL;
            exit(0);
        case array_key_exists('t', self::$args):
            return self::$args['t'];
        default:
            print self::$help_usage . PHP_EOL;
            exit(1);
        }
    }
} //END class cli_args

/* EOF ====================================================================== */
?>
