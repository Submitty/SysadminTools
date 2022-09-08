<?php
namespace ssaf;
require __DIR__ . "/ssaf_sql.php";

/**
 * Static database interaction class for Submitty Student Auto Feed
 *
 * SQL queries are found in ssaf_sql.php, which should be required, above.
 * self::$error holds any error messages should an error occur.
 */
class db {
    /** @var resource Database connection resource */
    private static $db;

    /** @var null|string Any generated error message. */
    public static $error = null;

    // DB CONNECT FUNCTIONS ----------------------------------------------------

    /**
     * Open database connection.  self::$db stores the database resource
     *
     * @param string $host Database host for authentication
     * @param string $user Database user for authentication
     * @param string $password Database password for authentication
     * @return bool TRUE on success, FALSE on error.
     */
    public static function open($host, $user, $password) {
        self::$db = pg_connect("host={$host} dbname=submitty user={$user} password={$password} sslmode=require");
        return self::check();
    }

    /** Close database connection */
    public static function close() {
        if (self::check()) {
            pg_close(self::$db);
        }
    }

    // PUBLIC STATIC FUNCTIONS -------------------------------------------------

    /**
     * Retrieve courses registered in Submitty for this $term.
     *
     * @param string $term Current term code.  e.g. "f20" means "Fall 2020".
     * @return array List of registered courses.  Does not include mapped courses.
     */
    public static function get_course_list($term) {
        self::$error = null;
        if (!self::check()) {
            return false;
        }

        $results = self::run_query(sql::GET_COURSES, $term);
        if ($results === false) {
            self::$error .= "Error while retrieving registered courses list.";
            return false;
        }

        // Condense $results to 1-dim array.
        array_walk($results, function(&$val, $key) { $val = $val['course']; });
        return $results;
    }

    /**
     * Retrieve mapped courses from Submitty master database for this $term.
     *
     * @param string $term Current term code.  e.g. "f20" means "Fall 2020".
     * @return array Course mappings indexed by course code.
     */
    public static function get_mapped_courses($term) {
        self::$error = null;
        if (!self::check()) {
            return false;
        }

        $results = self::run_query(sql::GET_MAPPED_COURSES, $term);
        if ($results === false) {
            self::$error .= "Error while retrieving mapped courses.";
            return false;
        }

        // Describe how auto-feed data is translated by mappings.
        // There are no mappings when $result is null.
        $mappings = array();
        if (!is_null($results)) {
            foreach ($results as $row) {
                $course = $row['course'];
                $section = $row['registration_section'];
                $mappings[$course][$section] = array(
                    'mapped_course'  => $row['mapped_course'],
                    'mapped_section' => $row['mapped_section']
                );
            }
        }

        return $mappings;
    }

    /**
     * Get student enrollment count for a specific semester and course.
     *
     * @param string $term
     * @param string $course
     * @return bool|string Enrollment count (as string) or FALSE on DB error.
     */
    public static function get_enrollment_count($semester, $course) {
        self::$error = null;
        if (!self::check()) {
            return false;
        }

        $results = self::run_query(sql::GET_COURSE_ENROLLMENT_COUNT, array($semester, $course));
        if ($results === false) {
            self::$error .= "Error while retrieving course enrollment counts.";
            return false;
        }

        return $results[0]['num_students'];
    }

    /**
     * Upsert $rows to Submitty master database.
     *
     * If an error occurs, this function returns FALSE.  self::run_query() will
     * set the error reported by Postgresql, and this function will concat more
     * context.
     * Switch/case blocks need to check each run_query() cases explicitely for
     * false as switch/case does not do strict comparisons, and run_query() can
     * return null (inferred as false) when a query has no errors and also
     * produces no results (mainly non-SELECT queries).
     *
     * @param string $semester Term course code.  e.g. "f20" for Fall 2020.
     * @param string $course Course code related to $rows.
     * @param array $rows Data rows read from CSV file to be UPSERTed to database
     * @return bool TRUE on success, FALSE on error.
     */
    public static function upsert($semester, $course, $rows) : bool {
        self::$error = null;
        if (!self::check()) {
            return false;
        }

        // Setup DB transaction.
        // If any query returns false, we need to bail out before upserting.
        switch(true) {
        case self::run_query(sql::CREATE_TMP_TABLE, null) === false:
            self::$error .= "\nError during CREATE tmp table, {$course}";
            return false;
        case self::run_query(sql::BEGIN, null) === false:
        self::$error .= "\nError during BEGIN transaction, {$course}";
            return false;
        case self::run_query(sql::LOCK_COURSES, null) === false:
            self::$error .= "\nError during LOCK courses table, {$course}";
            return false;
        case self::run_query(sql::LOCK_REG_SECTIONS, null) === false:
            self::$error .= "\nError during LOCK courses_registration_sections table, {$course}";
            return false;
        case self::run_query(sql::LOCK_COURSES_USERS, null) === false:
            self::$error .= "\nError during LOCK courses_users table, {$course}";
            return false;
        case self::run_query(sql::LOCK_SAML_MAPPED_USERS, null) === false:
            self::$error .= "\nError during LOCK saml_mapped_users table, {$course}";
            return false;
        }

        // Do upsert of course enrollment data.
        foreach($rows as $row) {
            $users_params = array(
                $row[COLUMN_USER_ID],
                $row[COLUMN_NUMERIC_ID],
                $row[COLUMN_FIRSTNAME],
                $row[COLUMN_LASTNAME],
                $row[COLUMN_PREFERREDNAME],
                $row[COLUMN_EMAIL]
            );

            // Determine registration type for courses_users table
            // Registration type code has already been validated by now.
            switch(true) {
            case in_array($row[COLUMN_REGISTRATION], STUDENT_REGISTERED_CODES):
                $registration_type = sql::RT_GRADED;
                break;
            case in_array($row[COLUMN_REGISTRATION], STUDENT_AUDIT_CODES):
                $registration_type = sql::RT_AUDIT;
                break;
            default:
                $registration_type = sql::RT_LATEDROP;
                break;
            }

            $courses_users_params = array(
                $semester,
                $course,
                $row[COLUMN_USER_ID],
                4,
                $row[COLUMN_SECTION],
                $registration_type,
                "FALSE"
            );

            $reg_sections_params = array($semester, $course, $row[COLUMN_SECTION]);
            $tmp_table_params = array($row[COLUMN_USER_ID]);
            $dropped_users_params = array($semester, $course);

            // Upsert queries
            // If any query returns false, we need to rollback and bail out.
            switch(true) {
            case self::run_query(sql::UPSERT_USERS, $users_params) === false:
                self::run_query(sql::ROLLBACK, null);
                self::$error .= "\nError during UPSERT users table, {$course}\n";
                return false;
            case self::run_query(sql::INSERT_REG_SECTION, $reg_sections_params) === false:
                self::run_query(sql::ROLLBACK, null);
                self::$error .= "\nError during INSERT courses_registration_sections table, {$course}\n";
                return false;
            case self::run_query(sql::UPSERT_COURSES_USERS, $courses_users_params) === false:
                self::run_query(sql::ROLLBACK, null);
                self::$error .= "\nError during UPSERT courses_users table, {$course}\n";
                return false;
            case self::run_query(sql::INSERT_TMP_TABLE, $tmp_table_params) === false:
                self::run_query(sql::ROLLBACK, null);
                self::$error .= "\nError during INSERT temp table (enrolled student who hasn't dropped), {$course}\n";
                return false;
            }
        } // END row by row processing.

        // Process students who dropped a course.
        if (self::run_query(sql::DROPPED_USERS, $dropped_users_params) === false) {
            self::run_query(sql::ROLLBACK, null);
            self::$error .= "\nError processing dropped students, {$course}\n";
            return false;
        }

        // Add students to SAML mappings when PROCESS_SAML is set to true in config.php.
        if (PROCESS_SAML) {
            if (self::run_query(sql::INSERT_SAML_MAP, null) === false) {
                self::run_query(sql::ROLLBACK, null);
                self::$error .= "\nError processing saml mappings, {$course}\n";
                return false;
            }
        }

        // All data has been upserted.  Complete transaction and return success or failure.
        return self::run_query(sql::COMMIT, null) !== false;
    }

    // PRIVATE STATIC FUNCTIONS ------------------------------------------------

    private static function check() : bool {
        if (!is_resource(self::$db) || pg_connection_status(self::$db) !== PGSQL_CONNECTION_OK) {
            self::$error = "No DB connection.";
            return false;
        }

        return true;
    }

    /**
     * Run SQL query with parameters
     *
     * Uses pg_query_params() to run the query to help ensure that pertinant
     * data is properly escaped.  Returns NULL when there are no results, such
     * as with a INSERT or UPDATE query.  Be careful that FALSE and NULL are
     * equivalent when loosely compared.
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return mixed FALSE on error.  Array of results or NULL on success.
     */
    private static function run_query($sql, $params = null) {
        if (!self::check()) {
            return false;
        }

        if (is_null($params)) $params = array();
        else if (!is_array($params)) $params = array($params);

        $res = pg_query_params(self::$db, $sql, $params);
        if ($res === false) {
            // pg_result_error() doesn't work here as $res is no longer a result resource.
            self::$error = pg_last_error(self::$db);
            return false;
        }

        $result = pg_fetch_all($res, PGSQL_ASSOC);
        return $result !== false ? $result : null;
    }
}

// EOF
?>
