#!/usr/bin/env php
<?php

/**
 * Sysadmin tool to correct out of sync database issue with registration
 * sections.  Github issue #2690.
 *
 * Run this script to resync registration sections for all courses registered
 * in every term specified on cli.
 *
 * e.g. ./registration_section_resync.php u18 f18
 *
 * resync regustration sections for all courses registered in the u18 and f18
 * terms.
 *
 * @author Peter Bailie (systems programmer, RPI dept. of computer science)
 */

error_reporting(E_ERROR);
ini_set('display_errors', 'stderr');
define ('DB_CONFIG_PATH', '/usr/local/submitty/config/database.json');

//Run script
new registration_section_resync();
exit(0);

class registration_section_resync {

	private static $db_conn = array('master' => null, 'course' => null);
	private static $db_host;
	private static $db_user;
	private static $db_password;
	private static $academic_terms;

	public function __construct() {
		$this->init();
		$this->process();
		printf("Re-sync process finished.%s", PHP_EOL);
	}

	public function __destruct() {
		$this->close_db_conn('course');
		$this->close_db_conn('master');
	}

	/** Setup properties and open master DB connection */
	private function init() {
		//This script should be run as root.
		if (posix_getuid() !== 0) {
			exit(sprintf("This script must be run as root.%s", PHP_EOL));
		}

		//This script cannot be run as a webpage.
		if (PHP_SAPI !== 'cli') {
			exit(sprintf("This script must be run from the command line.%s", PHP_EOL));
		}

		//Get all academic terms to be processed (as cli arguments).
		if ($_SERVER['argc'] < 2) {
			exit(sprintf("Please specify at least one academic term code as a command line argument.%s", PHP_EOL));
		}
		self::$academic_terms = array_splice($_SERVER['argv'], 1);

		//Get DB connection config from Submitty
		$json_data = file_get_contents(DB_CONFIG_PATH);
		if ($json_data === false) {
			exit(sprintf("Could not read database configuration at %s%s", DB_CONFIG_PATH, PHP_EOL));
		}

		$json_data = json_decode($json_data, true);
		self::$db_host     = $json_data['database_host'];
		self::$db_user     = $json_data['database_user'];
		self::$db_password = $json_data['database_password'];

		//Connect to master DB.
		self::$db_conn['master'] = pg_connect(sprintf("dbname=submitty host=%s user=%s password=%s sslmode=prefer", self::$db_host, self::$db_user, self::$db_password));
		if (pg_connection_status(self::$db_conn['master']) !== PGSQL_CONNECTION_OK) {
			exit(sprintf("ERROR: Could not establish connection to Submitty Master DB%sCheck configuration at %s%s", PHP_EOL, DB_CONFIG_PATH, PHP_EOL));
		}
	}

	/** Loop through academic terms and then loop through each course to sync registration sections */
	private function process() {
		//Loop through academic terms
		foreach (self::$academic_terms as $term) {
			//Get courses list for $term
			$res = pg_query_params(self::$db_conn['master'], "SELECT course FROM courses WHERE term = $1", array($term));
			if ($res === false) {
				exit(sprintf("Error reading course list for %s.%s", $term, PHP_EOL));
			}

			$courses = pg_fetch_all_columns($res, 0);
			if (empty($courses)) {
				fprintf(STDERR, "No courses registered for %s.%s", $term, PHP_EOL);
				continue;
			}

			//Now loop through all courses
			foreach($courses as $course) {
				//We need to compare registration sections in both master and course DBs
				//Connect to course DB
				$db_name = sprintf("submitty_%s_%s", $term, $course);
				self::$db_conn['course'] = pg_connect(sprintf("dbname=%s host=%s user=%s password=%s sslmode=prefer", $db_name, self::$db_host, self::$db_user, self::$db_password));
				if (pg_connection_status(self::$db_conn['course']) !== PGSQL_CONNECTION_OK) {
					fprintf(STDERR, "ERROR: Could not establish connection to Submitty Course DB %s.%sSkipping %s %s.%s", $db_name, PHP_EOL, $term, $course, PHP_EOL);
					continue;
				}

				//First retrieve registration sections in master DB
				$res = pg_query_params(self::$db_conn['master'], "SELECT registration_section_id FROM courses_registration_sections WHERE term=$1 AND course=$2", array($term, $course));
				if ($res === false) {
					fprintf(STDERR, "Error reading registration sections from master DB: %s %s.%sSkipping %s %s.%s", $term, $course, PHP_EOL, $term, $course, PHP_EOL);
					$this->close_db_conn('course');
					continue;
				}
				$master_registration_sections = pg_fetch_all_columns($res, 0);

				//Next retrieve registration sections in course DB
				$res = pg_query(self::$db_conn['course'], "SELECT sections_registration_id FROM sections_registration");
				if ($res === false) {
					fprintf(STDERR, "Error reading registration sections from course DB: %s.%sSkipping %s %s.%s", $dbname, PHP_EOL, $term, $course, PHP_EOL);
					$this->close_db_conn('course');
					continue;
				}
				$course_registration_sections = pg_fetch_all_columns($res, 0);

				//Get the differences of both lists (a registration section in either list, but not the other).
				$sync_list = array_diff($course_registration_sections, $master_registration_sections);
				$sync_list = array_merge($sync_list, array_diff($master_registration_sections, $course_registration_sections));
				if (empty($sync_list)) {
					printf("No sync required for %s %s.%s", $term, $course, PHP_EOL);
					$this->close_db_conn('course');
					continue;
				}

				//INSERT $sync_list to master DB, ON CONFLICT DO NOTHING (prevents potential PK violations).  We're using DB schema trigger to complete resync.
				foreach($sync_list as $section) {
					$res = pg_query_params(self::$db_conn['master'], "INSERT INTO courses_registration_sections (term, course, registration_section_id) VALUES ($1, $2, $3) ON CONFLICT ON CONSTRAINT courses_registration_sections_pkey DO UPDATE SET term=$1, course=$2, registration_section_id=$3", array($term, $course, $section));
					if ($res === false) {
						fprintf(STDERR, "Error during re-sync procedure: %s %s section %s.%s.This section could not be synced.%s", $term, $course, $section, PHP_EOL, PHP_EOL);
						$this->close_db_conn('course');
						continue;
					}
				}
				printf("Sync complete for %s %s.%s", $term, $course, PHP_EOL);
				$this->close_db_conn('course');
			}
		}
	}

	/**
	 *  Close a given DB connection.
	 *
	 *  @param string DB connection to close.
	 */
	private function close_db_conn($conn) {
		if (pg_connection_status(self::$db_conn[$conn]) === PGSQL_CONNECTION_OK) {
			pg_close(self::$db_conn[$conn]);
		}
	}
}
