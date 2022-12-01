#!/usr/bin/env php
<?php
require __DIR__ . "/config.php";

new csv_local();
exit(0);

/**
 * Validate and copy CSV data file via filesystem.
 *
 * This data source option has the CSV file provided to any filesystem the
 * autofeed's server can access (local or mounted).  LOCAL_SOURCE_CSV must
 * be defined in config.php, referencing the location of the CSV file upload.
 *
 * @author Peter Bailie, Rensselaer Polytechnic Institute
 */
class csv_local {
    /** @static @property string */
    private static $source_file = LOCAL_SOURCE_CSV;

    /** @static @property string */
    private static $dest_file = CSV_FILE;

    /** @static @property string */
    private static $err = "";

    public function __construct() {
        // Main process
        switch(false) {
        case $this->validate_csv():
        case $this->copy_csv():
            // If we wind up here, something went wrong in the main process.
            fprintf(STDERR, "%s", self::$err);
            exit(1);
        }
    }

    /**
     * Validate CSV file before copy.
     *
     * Check's for the file's existence and tries to check that the file was
     * provided/refreshed on the same day as the autofeed was run.  The day
     * check is to help prevent the auto feed from blindly running the same CSV
     * multiple days in a row and alert the sysadmin that an expected file
     * refresh did not happen.  $this->err is set with an error message when
     * validation fails.
     *
     * @return boolean true when CSV is validated, false otherwise.
     */
    private function validate_csv() {
        clearstatcache();

        if (!file_exists(self::$source_file)) {
            self::$err = sprintf("CSV upload missing: %s\n", self::$source_file);
            return false;
        }

        $file_modified = filemtime(self::$source_file);
        $today = time();
        // There are 86400 seconds in a day.
        if (intdiv($today, 86400) !== intdiv($file_modified, 86400)) {
            $today = date("m-d-Y", $today);
            $file_modified = date("m-d-Y", $file_modified);
            $hash = md5(file_get_contents(self::$source_file));
            self::$err = sprintf("CSV upload modified time mismatch.\nToday:         %s\nUploaded File: %s\nUploaded File Hash: %s\n", $today, $file_modified, $hash);
            return false;
        }

        return true;
    }

    /**
     * Copy CSV file.
     *
     * $this->err is set with an error message when file copy fails.
     *
     * @return boolean true when copy is successful, false otherwise.
     */
    private function copy_csv() {
        if (file_exists(self::$dest_file)) {
            unlink(self::$dest_file);
        }

        if (!copy(self::$source_file, self::$dest_file)) {
            self::$err = sprintf("Failed to copy file.\nSource: %s\nDest:   %s\n", self::$source_file, self::$dest_file);
            return false;
        }

        return true;
    }
}
