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
 * You may specify the term on the command line with "-t".
 * "-g" can be used to guess the term by the server's calendar month and year.
 * "-s" will process accounts based on status and user role.
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
make_accounts::main();
exit(0);

class make_accounts {

	private static $db_conn;
	private static $auth_account_list = array();
	private static $db_queries = array(
		'term'   => "SELECT DISTINCT user_id FROM courses_users WHERE semester=$1",
		'active' => "SELECT DISTINCT user_id FROM courses_users WHERE user_group=1 OR (user_group<>1 AND status=1)",
		'clean'  => "SELECT DISTINCT user_id FROM courses_users WHERE user_group<>1 AND status<>1"
	);
	private static $cli = array(
		'term'   => function($user) {
			system("/usr/sbin/adduser --quiet --home /tmp --gecos 'auth only account' --no-create-home --disabled-password --shell /usr/sbin/nologin {$user} > /dev/null 2>&1");
		},
		'active' => function($user) {
			system("/usr/sbin/adduser --quiet --home /tmp --gecos 'auth only account' --no-create-home --disabled-password --shell /usr/sbin/nologin {$user} > /dev/null 2>&1");
		},
		'clean'  => function($user) {
			if (self::check_auth_account($user)) {
				system("/usr/sbin/deluser --quiet {$user} > /dev/null 2>&1");
			}
		}
	);

	public static function main() {
		//IMPORTANT: This script needs to be run as root!
		if (posix_getuid() !== 0) {
			exit("This script must be run as root." . PHP_EOL);
		}

		//Check CLI args for instructions.  Quit if set to false.
		if (($cli_args = cli_args::parse_args()) === false) {
			exit(1);
		}
		$command = $cli_args[0];
		$db_params = $cli_args[1];

		//Connect to database.  Quit on failure.
		if (self::db_conn() === false) {
			exit(1);
		}

		//Get user list based on command.  Quit on failure.
		if (($result = pg_query_params(self::$db_conn, self::$db_queries[$command], $db_params)) === false) {
			self::log_it("Submitty Auto Account Creation: Cannot read user list from {$db_name}.");
			exit(1);
		}

		while (($user = pg_fetch_result($result, null, 0)) !== false) {
			$cli[$command]($user);
		}
	}

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

		return true;
	}

	private function check_auth_account($user) {
		// This is a validation check for an empty list before searching, so
		// might as well wrap in the data loader for when list is empty.
		if (empty(self::$auth_account_list)) {
			if (($fh = fopen('/etc/passwd', 'r')) === false) {
				self::logit("Cannot open '/etc/passwd' to check for auth only accounts.");
				exit(1);
			}

			while (($row = fgetcsv($fh, 0, ':')) !== false) {
				array_push(self::$auth_only_accounts, array('id' => $row[0], 'gecos' => $row[4]));
			}

			fclose($fh);
		}

		$index = array_search($user, array_column(self::$auth_only_accounts, 'id'));
		return (self::$auth_only_accounts[$index]['gecos'] === 'auth only account');
	}

	/**
	 * Log message to email and text files
	 *
	 * @param string $msg
	 */
	private static function log_it($msg) {
		$msg = date('m/d/y H:i:s : ', time()) . $msg . PHP_EOL;
		error_log($msg, 3, ERROR_LOG_FILE);

		if (!is_null(ERROR_EMAIL)) {
			error_log($msg, 1, ERROR_EMAIL);
		}
	}
} //END class make_accounts

/** @static class to parse command line arguments */
class cli_args {

    /** @var array holds all CLI argument flags and their values */
	private static $args;
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
		self::$args = getopt('t:agrh', array('help'));

		switch(true) {
		case array_key_exists('h', self::$args):
		case array_key_exists('help', self::$args):
			self::print_help();
			return false;
		case array_key_exists('a', self::$args):
			return array("active", null);
		case array_key_exists('t', self::$args):
			return ("term", self::$args['t']);
		case array_key_exists('g', self::$args):
			//Guess current term
			//(s)pring is month <= 5, (f)all is month >= 8, s(u)mmer are months 6 and 7.
			//if ($month <= 5) {...} else if ($month >= 8) {...} else {...}
			$month = intval(date("m", time()));
			$year  = date("y", time());
			return ($month <= 5) ? array("term", "s{$year}") : (($month >= 8) ? array("term", "f{$year}") : array("term", "u{$year}"));
		case array_key_exists('r', self::$args):
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
