
<?php
include "config.php":

$proc = new deduplicate_by_sections();
$proc->go();
exit;

class deduplicate_by_sections {

    private $db;
    private $csv_r_file;
    private $csv_r_fh;
    private $csv_r_lock;
    private $csv_w_file;
    private $csv_w_fh;
    private $csv_w_lock;
    private $ldap_members;
    private $ldap_group;

    public function __construct() {
        // Files are not locked... yet.
        $this->csv_r_lock = false;
        $this->csv_w_lock = false;
    }

    public function __destruct() {
        // Cleanup
        close_files();
        close_db();
    }

    public function go() {
        // Any operation that returns false has failed in some way.
        switch (false) {
        case $this->open_db():
            fprintf(STDERR, "Error connecting to Submitty DB");
            exit 1;
        case $this->backup_csv():
            fprintf(STDERR, "Error backing up original CSV");
            exit 1;
        case $this->open_files():
            fprintf(STDERR, "Error opening work files.\n");
            exit 1;
        case $this->copy_header():
            fprintf(STDERR, "Error copying CSV header.\n");
            exit 1;
        case $this->deduplicate():
            fprintf(STDERR, "Error during depuplication.\n");
            exit 1;
        case $this->swap_files():
            fprintf(STDERR, "Error swapping over deduplicated datasheet for reading.\n");
            exit 1;
        }

        // All done
    }

    private function open_db() : bool {
        // Cleanup open DB connections (shouldn't be any, but just in case...)
        $this->close_db();

        // from config.php
        $user = DB_LOGIN;
        $host = DB_HOST;
        $password = DB_PASSWORD;

        $this->db = pg_connect("host={$host} dbname=submitty user={$user} password={$password} sslmode=prefer");
        return pg_connection_status($this->db) === PGSQL_CONNECTION_OK;
    }

    private function close_db() {
        if (pg_connection_status($this->db) === PGSQL_CONNECTION_OK)
            pg_close($this->db);
    }

    private function backup_csv() : bool {
        $this->close_files();
        $backup = "{$this->csv_r_file}.bak";
        return copy($this->csv_r_file, $backup);
    }

    private function open_files() : bool {
        // Cleanup open/locked files (shouldn't be any, but just in case...)
        $this->close_files();

        $this->csv_r_file = CSV_FILE;  // from config.php
        $md5 = md5(time());
        $this->csv_w_file = preg_replace("/\/[^\/]+$/", "/{$md5}.tmp", $this->csv_r_file);

        $this->csv_r_fh = fopen($csv_r_file, "r");
        if (flock($this->csv_r_fh, LOCK_SH)) $this->csv_r_lock = true;

        $this->csv_w_fh = fopen($csv_w_file, "w");
        if (flock($this->csv_w_fh, LOCK_EX)) $this->csv_w_lock = true;

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

    private function close_files() {
        // closing file handlers automatically unlocks the files.
        if (get_resource_type($this->csv_r_fh) === "stream") {
            fclose($this->csv_r_fh);
            $this->csv_r_lock = false;
        }

        if (get_resource_type($this->csv_w_fh) === "stream") {
            fclose($this->csv_w_fh);
            $this->csv_w_lock = false;
        }
    }

    private function copy_header() : bool {
        $header_row_exists = HEADER_ROW_EXISTS; // from config.php;
        $delim_char = CSV_DELIM_CHAR; // from config.php
        if (!$header_row_exists) return true; // nothing to do.  no error.

        $row = fgetcsv($this->cvs_r_fh, 0, $delim_char);
        if ($row === false) return false;

        $res = fputcsv($this->cvs_w_fh, $row, $delim_char);
        if ($res === false) return false;

        return true;
    }

    private function deduplicate() : bool {
    }

    private function swap_files() : bool {
        $this->close_files();

        switch (false) {
        case unlink($this->csv_r_file):
        case rename($this->csv_w_file, $this->csv_r_file):
            return false;
        }

        return true;
    }

    private function ldap_lookup($group) : bool {
        // Group needs to be all caps and have an underscore separating alpha and numerics.
        // e.g. csci1000 -> CSCI_1000
        $re_callback = function($matches) {
            $matches[1] = strtoupper($matches[1]);
            return "{$matches[1]}_{$matches[2]}";
        };
        $group = preg_replace_callback("/([a-z]{4})[ _]?(\d{4})/i", $re_callback, $group);

        if ($group === $this->ldap_group && !empty($this->ldap_members)) {
            return true;
        }
        $this->ldap_group = $group;

        /* ------------------------------------------------------------------ */

        $uri = LDAP_URI;
        $user = LDAP_USER;
        $password = LDAP_PASSWORD;
        $dn = preg_replace("/%COURSE%/", $group, LDAP_COURSE_DN);
        $arr_callback = function(&$val, $key) {
            // Isolate RCS ID from CN field, per member.
            $val = preg_replace("/^CN=([a-z]+[0-9]*),.+/i", "$1", $val);
        };

        $ldap = ldap_connect($uri);
        switch (false) {
        case is_resource($ldap) && get_resource_type($ldap) === "ldap link":  // May no longer be a resource in PHP 8.1+
        case ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3):
        case ldap_bind($ldap, $user, $password):
            return false;
        }

        $search=ldap_search($ldap, $dn, "member=*", array("member"));
        $info = ldap_get_entries($ldap, $search)[0]['member'];
        array_walk($info, $arr_callback);
        sort($info);
        $this->ldap_group = $info;
        ldap_unbind($ldap);
        return true;
}
// EOF
?>
