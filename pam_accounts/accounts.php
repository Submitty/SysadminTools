#!/usr/bin/env php
<?php

/**
 * Gets users from "master" submitty DB and creates PAM authentication accounts.
 *
 * This script will read all user IDs of all active Submitty courses and create
 * PAM authentication accounts on the Submitty server.  This script is intended
 * to be run from the CLI as a scheduled cron job, and should not be executed as
 * part of a website.  This script is not needed when using database
 * authentication.
 *
 * Example Crontab that runs the script ever half hour on the hour
 * (e.g. 8:30, 9:30, 10:30, etc.)
 *
 * "30 * * * * /var/local/submitty/bin/accounts.php"
 *
 * You may specify on the command line:
 * "-t [term code]" make auth accounts for [term code].
 * "-g" can be used to guess the term by the server's calendar month and year.
 * "-a" will make auth accounts for all instructors and all active courses.
 * "-r" will remove grader and student auth accounts from inactive courses.
 * For example:
 *
 * ./accounts.php -t s18
 *
 * Will create PAM auth accounts for the Spring 2018 semester.
 *
 * @author Peter Bailie, Systems Programmer (RPI dept of computer science)
 */

error_reporting(null);
ini_set('display_errors', '0');

//Database access configuration from Submitty
define('DB_CONFIG_PATH', '/usr/local/submitty/config/database.json');

//Location of accounts creation error log file
define('ERROR_LOG_FILE', 'accounts_script_error.log');

//Where to email error messages so they can get more immediate attention.
//Set to null to not send email.
define('ERROR_EMAIL', 'sysadmins@lists.myuniversity.edu');


/* SUGGESTED SETTINGS FOR TIMEZONES IN USA -------------------------------------
 *
 * Eastern ........... America/New_York
 * Central ........... America/Chicago
 * Mountain .......... America/Denver
 * Mountain no DST ... America/Phoenix
 * Pacific ........... America/Los_Angeles
 * Alaska ............ America/Anchorage
 * Hawaii ............ America/Adak
 * Hawaii no DST ..... Pacific/Honolulu
 *
 * For complete list of timezones, view http://php.net/manual/en/timezones.php
 *
 * -------------------------------------------------------------------------- */

//University campus's timezone.
date_default_timezone_set('America/New_York');

//Start process
new make_accounts();

/** Class constructor manages script workflow */
class make_accounts {

	/** @static @var resource pgsql database connection */
	private static $db_conn;
	/** @static @var string what workflow to process */
	private static $workflow;
	/** @static @var array paramater list for DB query */
	private static $db_params;
	/** @static @var string DB query to be run */
	private static $db_query;
	/** @static @var string function to call to process $workflow */
	private static $workflow_function;
	/** @static @var array user_id list of 'auth only accounts', read from /etc/passwd */
	private static $auth_only_accounts;

	public function __construct() {
		//IMPORTANT: This script needs to be run as root!
		if (posix_getuid() !== 0) {
			exit("This script must be run as root." . PHP_EOL);
		}

		//This is run from the command line, not a webpage.
		if (PHP_SAPI !== 'cli') {
			exit("This script must be run from the command line." . PHP_EOL);
		}

		//Init class properties, quit on error.
		if ($this->init() === false) {
			exit(1);
		}

		//Do workflow, quit on error.
		if ($this->process() === false) {
			exit(1);
		}

		//All done.
		exit(0);
	}

	public function __destruct() {
        //Close DB connection, if it exists.
        if (pg_connection_status(self::$db_conn) === PGSQL_CONNECTION_OK) {
            pg_close(self::$db_conn);
        }
    }

	/**
	 * Initialize class properties, based on self::$workflow
	 *
	 * @access private
	 * @return boolean TRUE on success, FALSE when there is a problem.
	 */
	private function init() {
		//Check CLI args.
		if (($cli_args = cli_args::parse_args()) === false) {
			return false;
		}
		self::$workflow  = $cli_args[0];
		self::$db_params = is_null($cli_args[1]) ? array() : array($cli_args[1]);

		//Define database query AND system call based on workflow.
		switch(self::$workflow) {
		case 'term':
			self::$db_query = <<<SQL
SELECT DISTINCT user_id
FROM courses_users
WHERE term=$1
SQL;
			self::$workflow_function = 'add_user';
            break;

        case 'active':
        	self::$db_query = <<<SQL
SELECT DISTINCT cu.user_id
FROM courses_users as cu
LEFT OUTER JOIN courses as c ON cu.course=c.course AND cu.term=c.term
WHERE cu.user_group=1 OR (cu.user_group<>1 AND c.status=1)
SQL;
			self::$workflow_function = 'add_user';
			break;

		case 'clean':
			//'clean' workflow requires list of 'auth only accounts' from /etc/passwd.
			self::$auth_only_accounts = array();
			if (($fh = fopen('/etc/passwd', 'r')) === false) {
				$this->logit("Cannot open '/etc/passwd' to check for auth only accounts.");
				return false;
			}
			while (($row = fgetcsv($fh, 0, ':')) !== false) {
				if (strpos($row[4], 'auth only account') !== false) {
					self::$auth_only_accounts[] = $row[0];
				}
			}
			fclose($fh);
			self::$db_query = <<<SQL
SELECT DISTINCT cu.user_id
FROM courses_users as cu
LEFT OUTER JOIN courses as c ON cu.course=c.course AND cu.term=c.term
WHERE cu.user_group<>1 AND c.status<>1
SQL;
			self::$workflow_function = 'remove_user';
			break;

		default:
			$this->log_it("Invalid self::$workflow during init()");
			return false;
		}

		//Signal success
		return true;
	}

	/**
	 * Process workflow
	 *
	 * @access private
	 * @return boolean TRUE on success, FALSE when there is a problem.
	 */
	private function process() {
		//Connect to database.  Quit on failure.
		if ($this->db_connect() === false) {
			$this->log_it("Submitty Auto Account Creation: Cannot connect to DB {$db_name}.");
			return false;
		}

		//Get user list based on command.  Quit on failure.
		if (($result = pg_query_params(self::$db_conn, self::$db_query, self::$db_params)) === false) {
			$this->log_it("Submitty Auto Account Creation: Cannot read user list from {$db_name}.");
			return false;
		}

		$num_rows = pg_num_rows($result);
		for ($i = 0; $i < $num_rows; $i++) {
			$user = pg_fetch_result($result, $i, 'user_id');
			call_user_func(array($this, self::$workflow_function), $user);
		}

		//Signal success
		return true;
	}

	/**
	 * Establish connection to Submitty Database
	 *
	 * @access private
	 * @return boolean TRUE on success, FALSE when there is a problem.
	 */
	private function db_connect() {
		$json_str = file_get_contents(DB_CONFIG_PATH);
		$db_config = json_decode($json_str, true);
		$db_host = $db_config['database_host'];
		$db_user = $db_config['database_user'];
		$db_pass = $db_config['database_password'];

		self::$db_conn = pg_connect("dbname=submitty host={$db_host} user={$db_user} password={$db_pass} sslmode=prefer");
		if (pg_connection_status(self::$db_conn) !== PGSQL_CONNECTION_OK) {
			$this->log_it(pg_last_error(self::$db_conn));
			return false;
		}

		//Signal success
		return true;
	}

	/**
	 * Add a user for authentication with PAM.
	 *
	 * @access private
	 * @param string $user User ID to added.
	 */
	private function add_user($user) {
		system("/usr/sbin/adduser --quiet --home /tmp --gecos 'auth only account' --no-create-home --disabled-password --shell /usr/sbin/nologin {$user} > /dev/null 2>&1");
	}

	/**
	 * Remove an 'auth only user' from authenticating with PAM
	 *
	 * @access private
	 * @param string $user User ID to be checked/removed.
	 */
	private function remove_user($user) {
		//Make sure $user is an "auth only account" before removing.
		if (array_search($user, self::$auth_only_accounts) !== false) {
			system("/usr/sbin/deluser --quiet {$user} > /dev/null 2>&1");
		}
	}

	/**
	 * Log message to email and text files
	 *
	 * @access private
	 * @param string $msg
	 */
	private function log_it($msg) {
		$msg = date('m/d/y H:i:s : ', time()) . $msg . PHP_EOL;
		error_log($msg, 3, ERROR_LOG_FILE);

		if (!is_null(ERROR_EMAIL)) {
			error_log($msg, 1, ERROR_EMAIL);
		}
	}
} //END class make_accounts

/**
 * class to parse command line arguments
 *
 * @static
 */
class cli_args {

    /** @var string usage help message */
	private static $help_usage      = "Usage: accounts.php [-h | --help] (-a |  -t [term code] | -g | -r)" . PHP_EOL;
    /** @var string short description help message */
	private static $help_short_desc = "Read student enrollment from Submitty DB and create accounts for PAM auth." . PHP_EOL;
    /** @var string argument list help message */
	private static $help_args_list  = <<<HELP
Arguments
-h --help       Show this help message.
-a              Make auth accounts for all active courses.
-t [term code]  Make auth accounts for specified term code.
-g              Make auth accounts for guessed term code, based on calendar
                month and year.
-r              Remove auth accounts from inactive courses.

NOTE: Argument precedence order is -a, -t, -g, -r.  One is required.

HELP;

	/**
	 * Parse command line arguments
	 *
	 * Called with 'cli_args::parse_args()'
	 *
	 * @access public
	 * @return array consisting of process command (string) and possibly associated term code (string or null) or boolean false on error.
	 */
	public static function parse_args() {
		$args = getopt('t:agrh', array('help'));

		switch(true) {
		case array_key_exists('h', $args):
		case array_key_exists('help', $args):
			self::print_help();
			return false;
		case array_key_exists('a', $args):
			return array("active", null);
		case array_key_exists('t', $args):
			return array("term", $args['t']);
		case array_key_exists('g', $args):
			//Guess current term
			//(s)pring is month <= 5, (f)all is month >= 8, s(u)mmer are months 6 and 7.
			//if ($month <= 5) {...} else if ($month >= 8) {...} else {...}
			$month = intval(date("m", time()));
			$year  = date("y", time());
			return ($month <= 5) ? array("term", "s{$year}") : (($month >= 8) ? array("term", "f{$year}") : array("term", "u{$year}"));
		case array_key_exists('r', $args):
			return array("clean", null);
		default:
			print self::$help_usage . PHP_EOL;
			return false;
		}
	}

	/**
	 * Print extended help to console
	 *
	 * @access private
	 */
	private static function print_help() {

		//Usage
		print self::$help_usage . PHP_EOL;
		//Short description
		print self::$help_short_desc . PHP_EOL;
		//Arguments list
		print self::$help_args_list . PHP_EOL;
	}
} //END class parse_args

/* EOF ====================================================================== */
?>
