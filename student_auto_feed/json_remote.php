#!/usr/bin/env php
<?php
/**
 * Helper script for using JSON data set of student enrolment for Submitty.
 *
 * The student auto feed script is designed to read a CSV export of student
 * enrollment.  Another enrollment data set is being provided as JSON for RPI.
 * This script will read the JSON data and write out a compatible CSV for the
 * auto feed script.  Run this script as a cronjob with MAILTO set and STDERR
 * will be emailed rather than printed to the console.  This helper script was
 * created at the request of bmcutler@github and is provided as is.
 *
 * @author Peter Bailie, Rensselaer Polytechnic Institute
 */
require "config.php";

new json_remote();
exit(0);

/** Class to read json files from remote server and combine/convert data to CSV */
class json_remote {
    /** @staticvar resource secure shell connection. */
    private static $ssh2_conn = null;

    /** @staticvar resource file handle to write CSV */
    private static $csv_fh = null;

    public function __construct() {
        //Main
        switch(false) {
        case $this->remote_connect():
            exit(1);
        case $this->convert_json_to_csv():
            exit(1);
        }
    }

    public function __destruct() {
        $this->close_csv();
        $this->remote_disconnect();
    }

    /**
     * Establish secure shell session to remote server.
     *
     * @access private
     * @return boolean true on success, false otherwise.
     */
    private function remote_connect() {
        //Ensure any existing connection is elegantly closed.
        $this->remote_disconnect();

        //Connect.
        self::$ssh2_conn = ssh2_connect(JSON_REMOTE_HOSTNAME, JSON_REMOTE_PORT);

        //Validate connection.
        if (!$this->validate_remote_connect()) {
            fprintf(STDERR, "Cannot connect to remote server %s\n", self::hostname);
            return false;
        }

        //Validate fingerprint to prevent communicating with an imposter server.
        $remote_fingerprint = ssh2_fingerprint(self::$ssh2_conn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
        if ($remote_fingerprint !== JSON_REMOTE_FINGERPRINT) {
            fprintf(STDERR, "Remote server fingerprint does not match.\nExpected: %s\nActual: %s\n", JSON_REMOTE_FINGERPRINT, $remote_fingerprint);
            $this->remote_disconnect();
            return false;
        }

        //Authenticate session.
        if (ssh2_auth_password(self::$ssh2_conn, JSON_REMOTE_USERNAME, JSON_REMOTE_PASSWORD) === false) {
            fprintf(STDERR, "Could not authenticate %s@%s.\n", JSON_REMOTE_USERNAME, JSON_REMOTE_HOSTNAME);
            return false;
        }

        //Connection established, server verified, and session authenticated.
        return true;
    }

    /**
     * Validate secure shell connection.
     *
     * @access private
     * @return boolean true when connected, false otherwise.
     */
    private function validate_remote_connect() {
        if (is_resource(self::$ssh2_conn) && get_resource_type(self::$ssh2_conn) === "SSH2 Session") {
            return true;
        }

        return false;
    }
    /**
     * Disconnect secure shell from remote server.
     *
     * @access private
     */

    private function remote_disconnect() {
        if ($this->validate_remote_connect()) {
            ssh2_disconnect(self::$ssh2_conn);
        }
    }

    /**
     * Create/Open CSV file for writing.  Any old data is wiped.
     *
     * @access private
     */
    private function open_csv() {
        self::$csv_fh = fopen(JSON_LOCAL_CSV_FILE, "w");
    }

    /**
     * Close CSV file.
     *
     * @access private
     */
    private function close_csv() {
        if (is_resource(self::$csv_fh) && get_resource_type(self::$csv_fh) === "stream") {
            fclose(self::$csv_fh);
        }
    }

    /**
     * Convert JSON to CSV
     *
     * Read remote JSON data via secure shell.  Convert and push that data to
     * CSV file.
     *
     * @return boolean true on success, false on failure.
     */
    private function convert_json_to_csv() {
        if (!$this->validate_remote_connect()) {
            fwrite(STDERR, "NOT connected to remote server when CSV conversion called.\n");
            return false;
        }

        //Get a list of JSON files to read.
        $command = sprintf("/bin/ls %s", JSON_REMOTE_PATH);
        $ssh2_stream = ssh2_exec(self::$ssh2_conn, $command);
        stream_set_blocking($ssh2_stream, true);
        $files = stream_get_contents($ssh2_stream);
        $files = explode("\n", $files);
        $files = preg_grep("~\.json$~", $files);
        if (empty($files)) {
            fwrite(STDERR, "No remote JSON files found.\n");
            return false;
        }

        $this->open_csv();

        //Read json data from each file.
        foreach ($files as $file) {
            $data_file = JSON_REMOTE_PATH . $file;
            $ssh2_stream = ssh2_exec(self::$ssh2_conn, "/bin/cat {$data_file}");
            stream_set_blocking($ssh2_stream, true);
            $json_data = stream_get_contents($ssh2_stream);
            $decoded_data = json_decode($json_data, true, 512, JSON_OBJECT_AS_ARRAY);

            //Write out CSV data by rows.
            foreach ($decoded_data as $row) {
                //Validation.  Ignore row should any data point be empty.
                switch(true) {
                case empty($row['first_name']):
                case empty($row['last_name']):
                case empty($row['email']):
                case empty($row['rcs']):
                case empty($row['rin']):
                case empty($row['course_prefix']):
                case empty($row['course_number']):
                case empty($row['course_section']):
                    fprintf(STDERR, "Row Discarded:\n%s", var_export($row, true));
                    continue 2;
                }

                $csv_row = array_fill(0, VALIDATE_NUM_FIELDS, null);
                $csv_row[COLUMN_FIRSTNAME]     = $row['first_name'];
                $csv_row[COLUMN_LASTNAME]      = $row['last_name'];
                $csv_row[COLUMN_EMAIL]         = $row['email'];
                $csv_row[COLUMN_USER_ID]       = $row['rcs'];
                $csv_row[COLUMN_NUMERIC_ID]    = $row['rin'];
                $csv_row[COLUMN_REGISTRATION]  = STUDENT_REGISTERED_CODES[0];
                $csv_row[COLUMN_COURSE_PREFIX] = $row['course_prefix'];
                $csv_row[COLUMN_COURSE_NUMBER] = $row['course_number'];
                $csv_row[COLUMN_SECTION]       = $row['course_section'];
                $csv_row[COLUMN_TERM_CODE]     = EXPECTED_TERM_CODE;
                fputcsv(self::$csv_fh, $csv_row, CSV_DELIM_CHAR, '"', "\\");
            }
        }

        return true;
    }
}

//EOF
?>
