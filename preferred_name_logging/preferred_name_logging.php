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
    private static $config = array(
        'timezone'                 => "America/New_York",
        'postgresql_logfile_path'  => "/var/log/postgresql/",
        'submitty_logfile_path'    => "/var/log/submitty/",
        'postgresql_logfile'       => "postgresql",
        'submitty_logfile'         => "submitty_preferred_names",
        'submitty_log_retention'   => 7
    );

    private const POSTGRESQL_LOG_ROW_COUNT = 23;
    private const PSQL_VALIDATION_UPDATE   = 7;
    private const PSQL_VALIDATION_LOG      = 11;
    private const PSQL_VALIDATION_PFN      = 13;
    private const PSQL_DATA_DATE           = 8;
    private const PSQL_DATA_AUTH           = 19;
    private const PSQL_DATA_PFN            = 14;

    /** @var Not a config option.  Do NOT manually change. */
    private static $psql_log_time_offset = -86400;
    /** @var Not a config option.  Do NOT manually change. */
    private static $daemon_user = array(
        'name' => "root",
        'uid'  => 0,
        'gid'  => 0
    );

    /**
     * Method to invoke to run this program: main::run()
     *
     * @access public
     * @static
     */
    public static function run() {
        //make sure we are running from cli
        if (PHP_SAPI !== 'cli') {
            fprintf(STDERR, "This is a command line script.%s", PHP_EOL);
            exit(1);
        }

        //check for which server to run on.
        $args = cli_args::parse_args();

        //must be run as root or submitty_daemon (submitty/dev only).
        //submitty/dev mode needs to lookup submitty_daemon user info.
        switch($args) {
        case "submitty":
        case "dev":
            if (self::get_daemon_user() === false) {
                fprintf(STDERR, "Could not retrieve Submitty daemon uuid%s.  Aborting.%s", PHP_EOL, PHP_EOL);
                exit(1);
            }
        }

        if (posix_getuid() !== 0 && posix_getuid() !== self::$daemon_user['uid']) {
            fprintf(STDERR, "Execution denied.%s", PHP_EOL);
            exit(1);
        }

        //Prfocessing may continue.  Submitty/vagrant systems need to override
        //config with submitty.json.
        switch($args) {
        case "submitty":
            self::override_config();
            break;
        case "dev":
            self::override_config();
            //dev should read today's psql CSV.
            self::$psql_log_time_offset = 0;
            break;
        }

        date_default_timezone_set(self::$config['timezone']);
        self::parse_and_write_logs();
        self::log_retention_and_deletion();

        exit(0);
    }

    /**
     * submitty/vagrant servers permit submitty_daemon to execute this script.
     *
     * self::$daemon_user by default mirrors root so by default only root has
     * execution privilege.  This function will attempt to lookup
     * submitty_daemon in submitty_users.json, and when successful, copy that
     * information to self::$daemon_user, therefore permitting submitty_daemon
     * execution privilege.  Submitty_daemon will also need group ownership and
     * chmod g+rx privilege.
     *
     * @access private
     * @static
     */
    private static function get_daemon_user() {
        $json = file_get_contents("../config/submitty_users.json");
        if ($json === false) {
            return false;
        } else {
            $json = json_decode($json, true);
            self::$daemon_user = array(
                'name' => $json['daemon_user'],
                'uid'  => $json['daemon_uid'],
                'gid'  => $json['daemon_gid']
            );

            return true;
        }
    }

    /**
     * Override self::$config with a few elements from submitty.json
     *
     * This function assumes that the script is running from submitty/sbin.
     *
     * @access private
     * @static
     */
    private static function override_config() {
        $json['config'] = file_get_contents("../config/submitty.json");
        if ($json['config'] === false) {
            fprintf(STDERR, "Cannot open config/submitty.json%s", PHP_EOL);
            exit(1);
        }

        $json['config'] = json_decode($json['config'], true);
        self::$config['timezone'] = $json['config']['timezone'];
        self::$config['postgresql_logfile_path'] = $json['config']['site_log_path'] . "/psql/";
        self::$config['submitty_logfile_path'] = $json['config']['site_log_path'] . "/preferred_names/";
    }

    /**
     * Process method
     *
     * @access private
     * @static
     */
    private static function parse_and_write_logs() {
        //Check to make sure Submitty log directory path exists.  Create it if needed.
        if (file_exists(self::$config['submitty_logfile_path']) === false) {
            if (mkdir(self::$config['submitty_logfile_path'], 0700) === false) {
                self::log("Submitty log folder needed, mkdir failed.");
                exit(1);
            }
        }

        //Prepare submitty preferred name change log file for today.
        $submitty_logfile = sprintf("%s%s_%s.log", self::$config['submitty_logfile_path'], self::$config['submitty_logfile'], date("Y-m-d"));
        $submitty_fh = fopen($submitty_logfile, 'w');
        if ($submitty_fh === false) {
            self::log("Cannot create Submitty logfile.");
            exit(1);
        } else {
            fwrite($submitty_fh, "Log opened." . PHP_EOL);
        }

        //There can be multiple psql log files that need to be read.
        //But we want the ones dated one day prior (hence subtract 86400 seconds which is one day)
        $preg_str = sprintf("~^%s\-%s_\d{6}\.csv$~", self::$config['postgresql_logfile'], preg_quote(date("Y-m-d", time() + self::$psql_log_time_offset)));
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
                    self::log(sprintf("NOTICE: PSQL log row %d had %d columns.  %d columns expected.  Row ignored.%s", $psql_row_num, count($psql_row), self::$config['postgresql_log_row_count']));
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
                if (preg_match("~/\* AUTH: \"[\w\-]+\" \*/~", $psql_row[19]) === 1) {
                    $key['start'] = strpos($psql_row[self::PSQL_DATA_AUTH], "/* AUTH: ") + 3;
                    $key['end'] = strpos($psql_row[self::PSQL_DATA_AUTH], " */");
                    $auth = " | " . substr($psql_row[self::PSQL_DATA_AUTH], $key['start'], $key['end'] - $key['start']);
                } else {
                    $auth = " | AUTH NOT LOGGED ";
                    //Anything sent to STDERR gets emailed by cron.
                    fprintf(STDERR, "WARNING: AUTH NOT LOGGED%s%s", PHP_EOL, var_export($psql_row, true));
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
                    //Anything sent to STDERR gets emailed by cron.
                    fprintf(STDERR, "WARNING: USER ID NOT LOGGED%s%s", PHP_EOL, var_export($psql_row, true));
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
                $submitty_log = $date . $auth . $user_id;
                if (isset($preferred_name['first'])) {
                    $submitty_log .= " | PREFERRED_FIRSTNAME OLD: {$preferred_name['first']['old']} NEW: {$preferred_name['first']['new']}";
                } else {
                    $submitty_log .= " | PREFERRED_FIRSTNAME UNCHANGED";
                }

                if (isset($preferred_name['last'])) {
                    $submitty_log .= " | PREFERRED_LASTNAME OLD: {$preferred_name['last']['old']} NEW: {$preferred_name['last']['new']}";
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
        $preg_str = sprintf("~^%s_%s.log$~", self::$config['submitty_logfile'], preg_quote(date("m-d-Y")));
        $logfiles = preg_grep($preg_str, scandir(self::$config['submitty_logfile_path']));
        $expiration_epoch = (int)(strtotime(date('Y-m-d')) / 86400) - self::$config['submitty_log_retention'];

        foreach($logfiles as $logfile) {
            $datestamp = substr($logfile, strpos($logfile, "_") + 1, 10);
            $datestamp_epoch = (int)(strtotime($datestamp) / 86400);
            if ($datestamp_epoch < $expiration_epoch) {
                if (unlink(self::$config['submitty_logfile_path'] . $logfile) === false) {
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
} //END class main

/**
 * class to parse command line arguments
 *
 * @static
 */
class cli_args {

    /** @var string usage help message */
    private static $help_usage      = <<<HELP
CLI options: [-h | --help] (-m | --mode (psql | submitty | dev))


HELP;

    /** @var string short description help message */
    private static $help_short_desc =<<<HELP
Scrape Postgresql CSV log and report changes in preferred name data.


HELP;

    /** @var string argument list help message */
    private static $help_args_list  = <<<HELP
Arguments
-h --help    Show this help message.
-m --mode    Required.  Use 'submitty' if postgresql operates on the same server
             or instance as the Submitty system.  Use 'psql' when postgresql
             operates on a different server or instance as the Submitty system
             (this will also require manual changes to postgresl.conf).
             Use 'dev' to run the script immediately on the most recent psql
             log, such as in a development environment (vagrant, etc.).  'dev'
             assumes that postgresql exists locally with Submitty.


HELP;

    /**
     * Parse command line arguments
     *
     * Called with 'cli_args::parse_args()'.  If cli arguments are invalid,
     * script will print usage help and exit code 1.
     *
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
            case "psql":
            case "submitty":
            case "dev":
                return $args['m'];
            }
        // --mode
        case array_key_exists('mode', $args):
            switch($args['mode']) {
            case "psql":
            case "submitty":
            case "dev":
                return $args['mode'];
            }
        }

        //If we reach here, invalid CLI arguments were given.
        fprintf(STDERR, self::$help_usage);
        exit(1);
    }
} //END class parse_args


//Start processing.
main::run();

// EOF
?>
