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
     * Operating config determined by main::get_config().
     *
     * @staticvar array
     * @access private
     */
    private static $config = array();

    //Do not change these.
    private const POSTGRESQL_LOG_ROW_COUNT = 23;
    private const PSQL_VALIDATION_UPDATE   = 7;
    private const PSQL_VALIDATION_LOG      = 11;
    private const PSQL_VALIDATION_PFN      = 13;
    private const PSQL_DATA_DATE           = 8;
    private const PSQL_DATA_AUTH           = 19;
    private const PSQL_DATA_PFN            = 14;
    private const POSTGRESQL_LOGDIR        = '/psql/';
    private const POSTGRESQL_LOGFILE       = 'postgresql';
    private const PREFERRED_NAMES_LOGDIR   = '/preferred_names/';
    private const PREFERRED_NAMES_LOGFILE  = 'preferred-names';
    private const ERROR_LOGFILE            = 'errors.log';

    /**
     * Main process.  Invoke with main::run()
     *
     * @static
     * @access public
     */
    public static function run() {
        //make sure we are running from cli
        if (PHP_SAPI !== 'cli') {
            fwrite(STDERR, "This is a command line script." . PHP_EOL);
            exit(1);
        }

        ini_set('display_errors', '0');
        self::get_config();
        self::parse_and_write_logs();
        self::log_retention_and_deletion();

        exit(0);
    }

     /**
     * Read JSON config files to build self::$config array.
     *
     * This function assumes that the script is running from submitty/sbin.
     *
     * @static
     * @access private
     */
    private static function get_config() {

        self::$config['mode'] = cli_args::parse_args();
        //'prod' reads yesterday's CSV.  'dev' read's most current CSV.
        self::$config['psql_log_time_offset'] = (self::$config['mode'] === 'prod' ? -86400 : 0);

        if (file_exists("../config/submitty.json")) {
            $json = file_get_contents("../config/submitty.json");
            $json = json_decode($json, true);
        } else {
            // Error logging not configured yet.
            fwrite(STDERR, print_r(error_get_last(), true));
            exit(1);
        }

        date_default_timezone_set($json['timezone']);
        self::$config['postgresql_logfile_path'] = $json['site_log_path'] . self::POSTGRESQL_LOGDIR;
        self::$config['pfn_logfile_path'] = $json['site_log_path'] . self::PREFERRED_NAMES_LOGDIR;

        if (file_exists("../config/preferred_name_logging.json")) {
            $json = file_get_contents("../config/preferred_name_logging.json");
            $json = json_decode($json, true);
        } else {
            $json = array();
        }

        if (array_key_exists('log_emails', $json)) {
            if (is_array($json['log_emails'])) {
                self::$config['log_emails'] = $json['log_emails'];
            } else {
                self::$config['log_emails'] = array($json['log_emails']);
            }
        } else {
            self::$config['log_emails'] = null;
        }

        if (array_key_exists('log_file_retention', $json)) {
            self::$config['log_file_retention'] = (int)$json['log_file_retention'];
        } else {
            self::$config['log_file_retention'] = 7;
        }
    }

    /**
     * Scrape psql log and write preferred_names log
     *
     * @static
     * @access private
     */
    private static function parse_and_write_logs() {
        //Check to make sure Submitty log directory path exists.
        if (file_exists(self::$config['pfn_logfile_path']) === false) {
            self::log("Submitty log folder missing.");
            exit(1);
        }

        //Prepare submitty preferred name change log file for today.
        $pfn_log_file = sprintf("%s%s_%s.log", self::$config['pfn_logfile_path'], self::PREFERRED_NAMES_LOGFILE, date("Y-m-d"));
        $pfn_fh = fopen($pfn_log_file, 'w');
        if ($pfn_fh === false) {
            self::log("Cannot create Submitty logfile.");
            exit(1);
        } else {
            fwrite($pfn_fh, "Log opened." . PHP_EOL);
        }

        //There can be multiple psql log files that need to be read.
        $preg_str = sprintf("~^%s_%sT\d{6}\.csv$~", self::POSTGRESQL_LOGFILE, preg_quote(date("Y-m-d", time() + self::$config['psql_log_time_offset'])));
        $logfiles = preg_grep($preg_str, scandir(self::$config['postgresql_logfile_path']));

        foreach ($logfiles as $logfile) {
            $psql_fh = fopen(self::$config['postgresql_logfile_path'] . $logfile, 'r');
            if ($psql_fh === false) {
                self::log("Cannot open {$logfile}.");
                continue;
            }

            $psql_row = fgetcsv($psql_fh);
            $psql_row_num = 1;
            while($psql_row !== false) {
                //Validation.  Any case is true, validation fails and row is ignored.
                switch(true) {
                case count($psql_row) !== self::POSTGRESQL_LOG_ROW_COUNT;
                    self::log(sprintf("NOTICE: PSQL log row %d had %d columns.  %d columns expected.  Row ignored.", $psql_row_num, count($psql_row), self::$config['postgresql_log_row_count']));
                case $psql_row[self::PSQL_VALIDATION_UPDATE] !== "UPDATE":
                case $psql_row[self::PSQL_VALIDATION_LOG] !== "LOG":
                case $psql_row[self::PSQL_VALIDATION_PFN] !== "PREFERRED_NAME DATA UPDATE":
                    $psql_row = fgetcsv($psql_fh);
                    $psql_row_num++;
                    continue 2;
                }

                //Validation successful, process row.
                //Trim all values in the row
                $psql_row = array_map('trim', $psql_row);

                //Get date token.
                $date = $psql_row[self::PSQL_DATA_DATE];

                //Get "AUTH" token (who logged the change).
                $key = array();
                if (preg_match("~/\* AUTH: \"[\w\-]+\" \*/~", $psql_row[self::PSQL_DATA_AUTH]) === 1) {
                    $key['start'] = strpos($psql_row[self::PSQL_DATA_AUTH], "/* AUTH: ") + 3;
                    $key['end'] = strpos($psql_row[self::PSQL_DATA_AUTH], " */");
                    $auth = " | " . substr($psql_row[self::PSQL_DATA_AUTH], $key['start'], $key['end'] - $key['start']);
                } else {
                    $auth = " | AUTH NOT LOGGED ";
                }

                //Get user_id and preferred name change tokens.
                $preferred_name = array();
                $preferred_names_data = explode(" ", $psql_row[self::PSQL_DATA_PFN]);

                //user_id token
                $key = array_search("USER_ID:", $preferred_names_data);
                if ($key !== false) {
                    $user_id = " | USER_ID: {$preferred_names_data[$key+1]} ";
                } else {
                    $user_id = " | USER_ID NOT LOGGED ";
                }

                $key = array_search("PREFERRED_FIRSTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['first']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['first']['new'] = $preferred_names_data[$key+4];
                }
                // It is possible that no Preferred Firstname was logged, in which we can ignore an move on.

                $key = array_search("PREFERRED_LASTNAME", $preferred_names_data);
                if ($key !== false) {
                    $preferred_name['last']['old'] = $preferred_names_data[$key+2];
                    $preferred_name['last']['new'] = $preferred_names_data[$key+4];
                }
                // It is possible that no Preferred Lastname was logged, in which we can ignore an move on.

                //Build preferred name change log entry.
                $pfn_log_row = $date . $auth . $user_id;
                if (isset($preferred_name['first'])) {
                    $pfn_log_row .= " | PREFERRED_FIRSTNAME OLD: {$preferred_name['first']['old']} NEW: {$preferred_name['first']['new']}";
                } else {
                    $pfn_log_row .= " | PREFERRED_FIRSTNAME UNCHANGED";
                }

                if (isset($preferred_name['last'])) {
                    $pfn_log_row .= " | PREFERRED_LASTNAME OLD: {$preferred_name['last']['old']} NEW: {$preferred_name['last']['new']}";
                } else {
                    $pfn_log_row .= " | PREFERRED_LASTNAME UNCHANGED";
                }

                //Write log entry and go to next row.
                fwrite($pfn_fh, $pfn_log_row . PHP_EOL);
                $psql_row = fgetcsv($psql_fh);
                $psql_row_num++;
            }

            fclose($psql_fh);
        }

        fwrite($pfn_fh, "Log closed." . PHP_EOL);
        fclose($pfn_fh);
    }

    /**
     * Automatically remove expired postgresql and preferred name change logs.
     *
     * Note that there is 86400 seconds in a day.
     *
     * @static
     * @access private
     */
    private static function log_retention_and_deletion() {
        $remove_logfiles = function($path, $logfiles, $expiration_epoch) {
            foreach($logfiles as $logfile) {
                $datestamp = substr($logfile, strpos($logfile, "_") + 1, 10);
                $datestamp_epoch = intdiv(strtotime($datestamp), 86400);
                if ($datestamp_epoch < $expiration_epoch) {
                    if (unlink($path . $logfile) === false) {
                        self::log("Unable to delete expired log {$logfile}");
                    }
                }
            }
        };

        // Remove expired postgresql logs
        $regex_pattern = sprintf("~^%s_\d{4}\-\d{2}\-\d{2}\-\d{6}\.csv|log$~", self::POSTGRESQL_LOGFILE);
        $logfiles = preg_grep($regex_pattern, scandir(self::$config['postgresql_logfile_path']));
        $expiration_epoch = intdiv(time(), 86400) - 2;
        $remove_logfiles(self::$config['postgresql_logfile_path'], $logfiles, $expiration_epoch);

        // Remove expired preferred name change logs
        $regex_pattern = sprintf("~^%s_\d{4}\-\d{2}\-\d{2}\.log$~", self::PREFERRED_NAMES_LOGFILE);
        $logfiles = preg_grep($regex_pattern, scandir(self::$config['pfn_logfile_path']));
        $expiration_epoch = intdiv(time(), 86400) - self::$config['log_file_retention'];
        $remove_logfiles(self::$config['pfn_logfile_path'], $logfiles, $expiration_epoch);
    }

    /**
     * Log messages to error log.  Also log to STDERR in 'dev' mode.
     *
     * @static
     * @access private
     */
    private static function log(string $msg) {
        $msg = sprintf("%s %s%sDetails: %s%s", date("m-d-Y H:i:s"), $msg, PHP_EOL, print_r(error_get_last(), true), PHP_EOL);
        error_log($msg, 3, self::$config['pfn_logfile_path'] . self::ERROR_LOGFILE);

        if (self::$config['mode'] === 'dev') {
            fwrite(STDERR, $msg);
        }

        if (!is_null(self::$config['log_emails'])) {
            $send_msg = "Error log from Submitty preferred name logging." . PHP_EOL . $msg;
            $send_msg = wordwrap($send_msg, 70);
            foreach(self::$config['log_emails'] as $email) {
                error_log($send_msg, 1, $email);
            }
        }
    }
} //END class main

/** Class to parse command line arguments */
class cli_args {

    /** @staticvar string usage help message */
    private static $help_usage      = <<<HELP
CLI options: [-h | --help] (-m | --mode (prod | dev))


HELP;

    /** @staticvar string short description help message */
    private static $help_short_desc =<<<HELP
Scrape Postgresql CSV log and report changes in preferred name data.


HELP;

    /** @staticvar string argument list help message */
    private static $help_args_list  = <<<HELP
Arguments
-h --help    Show this help message.
-m --mode    Required.  Use 'prod' when running in a production environment.
             The script will be assumed to run on a cron schedule, and will
             scrape from psql's log from a day ago.   Use 'dev' to run the
             script immediately on the most recent psql log, such as in a
             development environment (vagrant, etc.).


HELP;

    /**
     * Parse command line arguments
     *
     * Called with 'cli_args::parse_args()'.  If cli arguments are invalid,
     * script will print usage help and exit code 1.
     *
     * @static
     * @access public
     * @return string command process.
     */
    public static function parse_args() {
        $args = getopt('m:h', array('mode:','help'));

        switch(true) {
        // -h or --help
        case array_key_exists('h', $args):
        case array_key_exists('help', $args):
            print self::$help_short_desc;
            print self::$help_usage;
            print self::$help_args_list;
            exit(0);
        // -m
        case array_key_exists('m', $args):
            switch($args['m']) {
            case "prod":
            case "dev":
                return $args['m'];
            }
        // --mode
        case array_key_exists('mode', $args):
            switch($args['mode']) {
            case "prod":
            case "dev":
                return $args['mode'];
            }
        }

        //If we reach here, invalid CLI arguments were given.
        fwrite(STDERR, self::$help_usage);
        exit(1);
    }
} //END class parse_args

//Start processing.
main::run();

//EOF
?>
