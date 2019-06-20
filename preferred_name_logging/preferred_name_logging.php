#!/usr/bin/env php
<?php
/**
 * Preferred Name Logging syadmin tool for Submitty
 *
 * This script is to be run on the same server as Postgresql.  When run, it
 * will parse Postgresql's CSV logs for the previous day and compile a new
 * log of any changes to a user's preferred first and last names.
 *
 * @author Peter Bailie
 */

/** Main class */
class main {

    /**
     * Config
     *
     * @var const array
     * @access private
     */
    private const CONFIG = array(
        'timezone'                 => "America/New_York",
        'postgresql_logfile_path'  => "/var/log/postgresql/",
        'submitty_logfile_path'    => "/var/log/postgresql/",
        'postgresql_logfile'       => "postgresql",
        'submitty_logfile'         => "submitty_preferred_names",
        'postgresql_log_row_count' => 23,
        'submitty_log_retention'   => 7
    );

    /**
     * Number of days of preferred_name_change logs to keep.
     *
     * @var int
     * @access private
     * @static
     */
    private static $log_retention_days;

    /**
     * Method to invoke to run this program: main::run()
     *
     * @access public
     * @static
     */
    public static function run() {
        // make sure we are running as root from cli
        switch (true) {
        case posix_getuid() !== 0:
        case PHP_SAPI !== 'cli':
            fprintf(STDERR, "This is a command line script.  Root required.%s", PHP_EOL);
            exit(1);
        }

        date_default_timezone_set(self::CONFIG['timezone']);

        self::parse_and_write_logs();
        self::log_retention_and_deletion();

        exit(0);
    }

    /**
     * Process method
     *
     * @access private
     * @static
     */
    private static function parse_and_write_logs() {
        //Prepare submitty preferred name change log file for today.
        $submitty_logfile = sprintf("%s%s_%s.log", self::CONFIG['submitty_logfile_path'], self::CONFIG['submitty_logfile'], date("Y-m-d"));
        $submitty_fh = fopen($submitty_logfile, 'w');
        if ($submitty_fh === false) {
            self::log("Cannot create Submitty logfile.");
            exit(1);
        } else {
            fwrite($submitty_fh, "Log opened." . PHP_EOL);
        }

        //There can be multiple psql log files that need to be read.
        $preg_str = sprintf("~^%s\-%s_\d{6}\.csv$~", self::CONFIG['postgresql_logfile'], preg_quote(date("Y-m-d", time() - 86400 )));
        $logfiles = preg_grep($preg_str, scandir(self::CONFIG['postgresql_logfile_path']));

        foreach ($logfiles as $logfile) {
            $psql_fh = fopen(self::CONFIG['postgresql_logfile_path'] . $logfile, 'r');
            if ($psql_fh === false) {
                self::log("Cannot open {$logfile}.");
                continue;
            }

            $psql_row = fgetcsv($psql_fh);
            $psql_row_num = 1;
            while($psql_row !== false) {
                //Validation.  Any case is true, validation fails and row is ignored.
                switch(true) {
                case count($psql_row) !== self::CONFIG['postgresql_log_row_count']:
                    self::log(sprintf("NOTICE: PSQL log row %d had %d columns.  %d columns expected.  Row ignored.%s", $psql_row_num, count($psql_row), self::CONFIG['postgresql_log_row_count']));
                case $psql_row[7] !== "UPDATE":
                case $psql_row[11] !== "LOG":
                case $psql_row[13] !== "PREFERRED_NAME DATA UPDATE":
                    $psql_row = fgetcsv($psql_fh);
                    $psql_row_num++;
                    continue;
                }

                //Validation successful, process row.
                //Get date token.
                $date = $psql_row[8] . "  ";

                //Get "AUTH" token (who logged the change).
                if (preg_match("~/\* AUTH: [\w\-]+ \*/~", $psql_row[18]) === 1) {
                    $key['start'] = strpos($psql_row[18], "/* AUTH: ") + 3;
                    $key['end'] = strpos($psql_row[18], " */");
                    $auth = " | " . substr($psql_row[18], $key['start'], $key['end'] - $key['start']);
                } else {
                    $auth = " | AUTH NOT LOGGED";
                }

                //Get preferred name change tokens.
                $preferred_name = array();
                $preferred_names_data = explode(" ", $psql_row[14]);
                $key = array_search("PREFERRED_FIRSTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['first']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['first']['new'] = $preferred_names_data[$key+4];
                }

                $key = array_search("PREFERRED_LASTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['last']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['last']['new'] = $preferred_names_data[$key+4];
                }

                //Build preferred name change log entry.
                $submitty_log = $date . $auth;
                if (isset($preferred_name['first'])) {
                    $submitty_log .= " | PREFERRED_FIRSTNAME OLD: {$preferred_name['first']['old']} -> NEW: {$preferred_name['first']['new']}";
                } else {
                    $submitty_log .= " | PREFERRED_FIRSTNAME UNCHANGED";
                }

                if (isset($preferred_name['last'])) {
                    $submitty_log .= " | PREFERRED_LASTNAME OLD: {$preferred_name['last']['old']} -> NEW: {$preferred_name['last']['new']}";
                } else {
                    $submitty_log .= " | PREFERRED_LASTNAME UNCHANGED";
                }

                //Write log entry and go to next row.
                fwrite($submitty_fh, $submitty_log . PHP_EOL);
                $psql_row = fgetcsv($psql_fh);
                $psql_row_num++;
            }

            fclose($psql_fh);
        }

        fwrite($submitty_fh, "Log closed." . PHP_EOL);
        fclose($submitty_fh);
    }

    /**
     * Automatically remove old logs
     *
     * @access private
     * @static
     */
    private static function log_retention_and_deletion() {
        $preg_str = sprintf("~^%s_%s.log$~", self::CONFIG['submitty_logfile'], preg_quote(date("m-d-Y")));
        $logfiles = preg_grep($preg_str, scandir(self::CONFIG['submitty_logfile_path']));
        $expiration_epoch = (int)(strtotime(date(Y-m-d)) / 86400) - self::CONFIG['submitty_log_retention'];

        foreach($logfiles as $logfile) {
            $datestamp = substr($logfile, strpos($logfile, "_") + 1, 10);
            $datestamp_epoch = (int)(strtotime($datestamp) / 86400);
            if ($datestamp_epoch < $expiration_epoch) {
                if (unlink(self::CONFIG['submitty_logfile_path'] . $logfile) === false) {
                    self::log("Could not delete old logfile: {$logfile}");
                }
            }
        }
    }

    /**
     * Log messages to error log and STDERR.
     *
     * @access private
     * @static
     */
    private static function log(string $msg) {
        $datestamp = date("m-d-Y");
        error_log(sprintf("%s %s", $datestamp, $msg), 0);
        fprintf(STDERR, "%s%s", $msg, PHP_EOL);
    }
}

// Start processing.
main::run();

// EOF
?>
