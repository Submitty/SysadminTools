
<?php
include "config.php";

$proc = new deduplicate_by_sections($argv);
$proc->go();
exit;

class deduplicate_by_sections {

    private $db;
    private $db_courses_list;
    private $csv_r_file;
    private $csv_r_fh;
    private $csv_r_lock;
    private $csv_w_file;
    private $csv_w_fh;
    private $csv_w_lock;
    private $ldap_members;
    private $ldap_group;
    private $term;

    public function __construct($argv) {
        // Get term code from command line parameter
        if (!array_key_exists(1, $argv)) {
            fprintf(STDERR, "Missing term code.\n");
            exit(1);
        }
        $this->term = $argv[1];

        // Files are not locked... yet.
        $this->csv_r_lock = false;
        $this->csv_w_lock = false;
    }

    public function __destruct() {
        // Cleanup
        $this->close_files();
        $this->close_db();
    }

    /**
     * Process flow.  Error when any function returns false.
     *
     * @access public
     */
    public function go() {
        // Any operation that returns false has failed in some way.
        switch (false) {
        case $this->open_db():
            fprintf(STDERR, "Error connecting to Submitty DB.\n");
            exit(1);
        case $this->get_courses_list():
            fprintf(STDERR, "Error retrieving course list from DB.\n");
            exit(1);
        case $this->backup_csv():
            fprintf(STDERR, "Error backing up original CSV.\n");
            exit(1);
        case $this->open_files():
            fprintf(STDERR, "Error opening work files.\n");
            exit(1);
        case $this->copy_header():
            fprintf(STDERR, "Error copying CSV header.\n");
            exit(1);
        case $this->sanitize_csv():
            fprintf(STDERR, "Error during depuplication.\n");
            exit(1);
        case $this->swap_csv():
            fprintf(STDERR, "Error swapping over sanitized datasheet for reading.\n");
            exit(1);
        }

        // All done.
    }

    /**
     * Open database connection
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function open_db() : bool {
        // Cleanup open DB connections (shouldn't be any, but just in case...)
        $this->close_db();

        // from config.php
        $user = DB_LOGIN;
        $host = DB_HOST;
        $password = DB_PASSWORD;

        $this->db = pg_connect("host={$host} dbname=submitty user={$user} password={$password} sslmode=prefer");
        if (pg_connection_status($this->db) !== PGSQL_CONNECTION_OK)
            return false;

        return true;
    }

    /**
     * Set registered course list for this term to $this->db_courses_list
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function get_courses_list() : bool {
        if (!is_resource($this->db) || get_resource_type($this->db) !== "pgsql link")
            return false;

        $res = pg_query_params($this->db, "SELECT course FROM courses WHERE semester=$1 AND status=1", array($this->term));
        if ($res === false)
            return false;

        $this->db_courses_list = pg_fetch_all_columns($res, 0);
        return true;
    }

    /**
     * Get student's registration section from DB
     *
     * @acces private
     * @return mixed registration section on success, FALSE on failure.
     */
    private function student_section_lookup($course, $user_id) {
        if (!is_resource($this->db) && get_resource_type($this->db) !== "pgsql link")
            return false;

        $course = format_group_code($course, "db");
        $params = array($this->term, $course, $user_id);
        $res = pg_query_params("SELECT registration_section FROM courses_users WHERE user_id=$1", array($user_id));
        if ($res === false)
            return false;

        return pg_fetch_result($res, 0, 0);
    }

    /**
     * Close database connection, if it is open.
     *
     * @access private
     */
    private function close_db() {
        if (is_resource($this->db) && get_resource_type($this->db) === "pgsql link")
            pg_close($this->db);
    }

    /**
     * Copy CSV read file to backup.
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function backup_csv() : bool {
        $this->close_files();
        $backup = "{$this->csv_r_file}.bak";
        return copy($this->csv_r_file, $backup);
    }

    /**
     * Open both read and write CSV file handles.  Set file locks.
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function open_files() : bool {
        // Cleanup open/locked files (shouldn't be any, but just in case...)
        $this->close_files();

        $this->csv_r_file = CSV_FILE;  // from config.php
        $md5 = md5(time());
        $this->csv_w_file = preg_replace("/\/[^\/]+$/", "/{$md5}.tmp", $this->csv_r_file);

        $this->csv_r_fh = fopen($csv_r_file, "r");
        if (flock($this->csv_r_fh, LOCK_SH))
            $this->csv_r_lock = true;

        $this->csv_w_fh = fopen($csv_w_file, "w");
        if (flock($this->csv_w_fh, LOCK_EX))
            $this->csv_w_lock = true;

        // validate that everything was opened and locked.
        switch (false) {
        case get_resource_type($this->csv_r_fh) === "stream":
        case get_resource_type($this->csv_w_fh) === "stream":
        case $this->csv_r_lock:
        case $this->csv_w_lock:
            $this->close_files();
            return false;
        }

        return true;
    }

    /**
     * Close CSV read and write file handles.  Unlock files.
     *
     * @access private
     */
    private function close_files() {
        // closing file handlers automatically unlocks the files.
        if (is_resource($this->csv_r_fh) && get_resource_type($this->csv_r_fh) === "stream") {
            fclose($this->csv_r_fh);
            $this->csv_r_lock = false;
        }

        if (is_resource($this->csv_w_fh) && get_resource_type($this->csv_w_fh) === "stream") {
            fclose($this->csv_w_fh);
            $this->csv_w_lock = false;
        }
    }

    /**
     * Copy CSV header to sanitized CSV file, if header exists.
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function copy_header() : bool {
        $header_row_exists = HEADER_ROW_EXISTS; // from config.php;
        $delim_char = CSV_DELIM_CHAR; // from config.php
        if (!$header_row_exists)
            return true; // nothing to do.  no error.

        $row = fgetcsv($this->cvs_r_fh, 0, $delim_char);
        if ($row === false)
            return false;

        $res = fputcsv($this->cvs_w_fh, $row, $delim_char);
        if ($res === false)
            return false;

        return true;
    }

    private function sanitize_csv() : bool {
        $delim = CSV_DELIM_CHAR;
        $prefix = COLUMN_COURSE_PREFIX;
        $number = COLUMN_COURSE_NUMBER;
        $user_id = COLUMN_USER_ID;

        // Read in all lines.
        $rows[] = fgetcsv($this->csv_r_fh, 0, $delim);
        while(!feof($this->cvs_r_fh)) {
            $rows[] = fgetcsv($this->csv_r_fh, 0, $delim);
        }

        // sort by course
        usort($rows, function($a, $b) use ($prefix, $number) {
            $course_a = "{$a[$prefix]}{$a[$number]}";
            $course_b = "{$b[$prefix]}{$b[$number]}";
            return $course_a <=> $course_b;
        });

        foreach ($rows as $row) {
            $course = "{$row[$prefix]}{$row[$number]}";

            // Is course registered?  No -- discard
            if (array_search($course, $this->db_course_list) === false)
                continue;

            // Is user still enrolled?  No -- discard
            $this->ldap_lookup($course);
            if (array_search($row[$user_id], $this->ldap_members) === false)
                continue;

            // Read user's section.  No section on record?  Add to section 1 and log.
        }
    }

    /**
     * Delete original CSV and rename sanitized CSV to original file's name.
     *
     * @access private
     * @return bool indicates success (true) or failure (false).
     */
    private function swap_csv() : bool {
        $this->close_files();

        switch (false) {
        case unlink($this->csv_r_file):
        case rename($this->csv_w_file, $this->csv_r_file):
            return false;
        }

        return true;
    }

    private function format_group_code($group, $style="ldap") {
        switch ($style) {
        case "ldap":
            // Prefix is all caps, underscore delimiter.  e.g. csci1000 -> CSCI_1000
            $re_callback = function($matches) {
                $matches[1] = strtoupper($matches[1]);
                return "{$matches[1]}_{$matches[2]}";
            };
            break;
        case "db":
            // Prefix is lowercase, no delimiter.  e.g. CSCI_1000 -> csci1000
            $re_callback = function($matches) {
                $matches[1] = strtolower($matches[1]);
                return "{$matches[1]}{$matches[2]}";
            };
            break;
        default:
            return false;
        }

        $group = preg_replace_callback("/([a-z]{4})[ _\-]?(\d{4})/i", $re_callback, $group, -1, $num_matches);
        return $num_matches === 1 ? $group : false;
    }

    /**
     * Lookup class list from LDAP.  Copy to $this->ldap_members.
     *
     * @access private
     * @return bool
     */
    private function ldap_lookup($group) : bool {
        // Do not lookup again, when the same group data has already been read.
        $group = $this->format_group_code($group, "ldap");
        if ($group === false)
            return false;

        if ($group === $this->ldap_group && !empty($this->ldap_members)) {
            return true;
        }
        $this->ldap_group = $group;

        /* ------------------------------------------------------------------ */

        $uri = LDAP_URI;
        $user = LDAP_USER;
        $password = LDAP_PASSWORD;
        $dn = preg_replace("/%COURSE%/", $group, LDAP_COURSE_DN);
        $re_callback = function($matches) {
            return strtolower($matches[1]);
        };
        $aw_callback = function(&$val, $key) use ($re_callback) {
            // Isolate RCS ID from CN field, per member.
            $val = preg_replace_callback("/^CN=([a-z]+[0-9]*),.+/i", $re_callback, $val);
        };

        $ldap = ldap_connect($uri);
        switch (false) {
        case is_resource($ldap) && get_resource_type($ldap) === "ldap link":  // May no longer be a resource in PHP 8.1+
        case ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3):
        case ldap_bind($ldap, $user, $password):
            return false;
        }

        $search = ldap_search($ldap, $dn, "member=*", array("member"));
        $info = ldap_get_entries($ldap, $search)[0]['member'];
        array_walk($info, $aw_callback);
        sort($info);
        $this->ldap_members = $info;
        ldap_unbind($ldap);
        return true;
    }
}
// EOF
?>
