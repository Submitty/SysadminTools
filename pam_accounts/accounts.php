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
 * You may specify the term on the command line:
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

error_reporting(0);
ini_set('display_errors', 0);

//Database access
define('DB_LOGIN',  'hsdbu');
define('DB_PASSWD', 'hsdbu_pa55w0rd');
define('DB_HOST',   'localhost');
define('DB_NAME',   'submitty');

//Location of accounts creation error log file
define('ERROR_LOG_FILE', '/var/local/submitty/bin/accounts_errors.log');

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

/** class constructor manages script workflow */
class make_accounts {

	/** @var resource pgsql database connection */
	private $db_conn;
	/** @var string what $workflow to process */
	private $workflow;
	/** @var string when the db_query has a paramter, null otherwise */
	private $db_params;
	/** @var string DB query to be run */
	private $db_queries;
	/** @var closure logic to process $workflow */
	private $system_call;
	/** @var array user_id list to be checked against /etc/passwd (only certain workflows) */
	private $auth_account_list;

	public function __construct() {
		//IMPORTANT: This script needs to be run as root!
		if (posix_getuid() !== 0) {
			exit("This script must be run as root." . PHP_EOL);
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

	/**
	 * Initialize class properties, based on $this->workflow
	 *
	 * @access private
	 * @return boolean TRUE on success, FALSE when there is a problem.
	 */
	private function init() {
		//Check CLI args.
		if (($cli_args = cli_args::parse_args()) === false) {
			return false;
		}
		$this->workflow  = $cli_args[0];
		$this->db_params = $cli_args[1];

		//Define database query based on workflow.
		switch($this->workflow) {
		case 'term':
			$this->db_query = <<<SQL
SELECT DISTINCT user_id FROM courses_users
WHERE semester=$1
SQL;
            break;
        case 'active':
        	$this->db_query = <<<SQL
SELECT DISTINCT user_id
FROM courses_users as cu
LEFT OUTER JOIN courses as c ON cu.course=c.course AND cu.semester=c.semester
WHERE cu.user_group=1 OR (cu.user_group<>1 AND c.status=1)
SQL;
			break;
		case 'clean':
			$this->db_query = <<<SQL
SELECT DISTINCT user_id
FROM courses_users as cu
LEFT OUTER JOIN courses as c ON cu.course=c.course AND cu.semester=c.semseter
WHERE cu.user_group<>1 AND u.status<>1
SQL;
			break;
		default:
			$this->log_it("Define $this->db_query: Invalid $this->workflow");
			return false;
		}

		//Define system call based on workflow.
		switch($this->workflow) {
		case 'term':
			$this->system_call = function($user) {
				system("/usr/sbin/adduser --quiet --home /tmp --gecos 'auth only account' --no-create-home --disabled-password --shell /usr/sbin/nologin {$user} > /dev/null 2>&1");
			};
			break;
		case 'active':
			$this->system_call = function($user) {
				system("/usr/sbin/adduser --quiet --home /tmp --gecos 'auth only account' --no-create-home --disabled-password --shell /usr/sbin/nologin {$user} > /dev/null 2>&1");
			};
			break;
		case 'clean':
			$this->system_call = function($user) {
				if ($this->check_auth_account($user)) {
					system("/usr/sbin/deluser --quiet {$user} > /dev/null 2>&1");
				}
			};
			break;
		default:
			$this_>log_it("Define $this->system_call: Invalid $this->workflow");
			return false;
		}

		//Certain workflows require the contents of the passwd file.
		switch($this->workflow) {
		case 'clean':
			$this->auth_only_accounts = array();
			if (($fh = fopen('/etc/passwd', 'r')) === false) {
				$this->logit("Cannot open '/etc/passwd' to check for auth only accounts.");
				return false;
			}

			while (($row = fgetcsv($fh, 0, ':')) !== false) {
				array_push($this->auth_only_accounts, array('id' => $row[0], 'gecos' => $row[4]));
			}

			fclose($fh);
			break;
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
		if ($this->db_conn() === false) {
			return false;
		}

		//Get user list based on command.  Quit on failure.
		if (($result = pg_query_params($this->db_conn, $this->db_query, $this->db_params)) === false) {
			$this->log_it("Submitty Auto Account Creation: Cannot read user list from {$db_name}.");
			return false;
		}

		//Do workflow (iterate through each user returned by database)
		while (($user = pg_fetch_result($result, null, 0)) !== false) {
			$this->system_call($user);
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
	private static function db_conn() {
		$db_user = DB_LOGIN;
		$db_pass = DB_PASSWD;
		$db_host = DB_HOST;
		$db_name = DB_NAME;
		self::$db_conn = pg_connect("host={$db_host} dbname={$db_name} user={$db_user} password={$db_pass}");
		if (self::$db_conn === false) {
			self::log_it("Submitty Auto Account Creation: Cannot connect to DB {$db_name}.");
			return false;
		}

		register_shutdown_function(function() {
			pg_close(self::$db_conn);
		});

		//Signal success
		return true;
	}

	/**
	 * Verify that $user is an "auth only account"
	 *
	 * @access private
	 * @return boolean TRUE when $user is an "auth only account", FALSE otherwise.
	 */
	private function check_auth_account($user) {
		$index = array_search($user, array_column($this->auth_only_accounts, 'id'));
		return ($this->auth_only_accounts[$index]['gecos'] === 'auth only account');
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
	private static $help_usage      = "Usage: accounts.php [-h | --help] (-a | -t [term code] | -g)" . PHP_EOL;
    /** @var string short description help message */
	private static $help_short_desc = "Read student enrollment from Submitty DB and create accounts for PAM auth." . PHP_EOL;
    /** @var string argument list help message */
	private static $help_args_list  = <<<HELP
Arguments
-h --help       Show this help message.
-a              Process all active courses (irrespective of term code).
-r              Remove unused accounts (based on active courses)
-t [term code]  Process by specified term code.
-g              Process by guessed term code, based on calendar month and year.

NOTE: argument precedence order is -a, -t, -g, -r.  One is required.

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
