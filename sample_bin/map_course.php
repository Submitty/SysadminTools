#!/usr/bin/env php
<?php

/**
 * Sysadmin tool to map a course to appear as a section of another
 *
 * Should a Submitty course have multiple entries, possibly for cross
 * registration or undergrad/grad versions, this tool will make a DB entry
 * to map one course to another.  The course being mapped will appear in the
 * course it was mapped to as another section.
 *
 * @author Peter Bailie (systems programmer, RPI dept. of computer science)
 */

error_reporting(E_ERROR);
ini_set('display_errors', 'stderr');
define ('DB_CONFIG_PATH', '/usr/local/submitty/config/database.json');

//This script should be run as root.
if (posix_getuid() !== 0) {
	exit(sprintf("This script must be run as root.%s", PHP_EOL));
}

//This script cannot be run as a webpage.
if (PHP_SAPI !== 'cli') {
	exit(sprintf("This script must be run from the command line.%s", PHP_EOL));
}

//Print usage if there are not five CLI arguments
if ($argc !== 6) {
	exit(sprintf("Usage:  %s  semester_code  course  section  mapped_course  mapped_section%s", $argv[0], PHP_EOL));
}

//Get DB connection config from Submitty
$json_str = file_get_contents(DB_CONFIG_PATH);
$db_config = json_decode($json_str, true);

//Connect to master DB.
$db_conn = pg_connect("dbname=submitty host={$db_config['database_host']} user={$db_config['database_user']} password={$db_config['database_password']}");
if (pg_connection_status($db_conn) !== PGSQL_CONNECTION_OK) {
	exit(sprintf("ERROR: Could not establish connection to Submitty Master DB%sCheck configuration at %s%s", PHP_EOL, DB_CONFIG_PATH, PHP_EOL));
}

//Register pg_close() to occur on shutdown in case script quits on error.
register_shutdown_function(function() use ($db_conn) {
	pg_close($db_conn);
});

//$argv[1]: semester_code
//$argv[2]: course
//$argv[3]: section
//$argv[4]: mapped_course
//$argc[5]: mapped_section
list($semester, $course, $section, $mapped_course, $mapped_section) = array_slice($argv, 1, 5);

//INSERT new (mapped) registration section
$query = <<<SQL
INSERT INTO courses_registration_sections
	VALUES ($1, $2, $3)
ON CONFLICT ON CONSTRAINT courses_registration_sections_pkey
	DO NOTHING
SQL;

$res = pg_query_params($db_conn, $query, array($semester, $mapped_course, $mapped_section));
if ($res === false) {
	exit(sprintf("DB error when INSERTing registration section %s%s%s%s", $mapped_section, PHP_EOL, pg_last_error($db_conn), PHP_EOL));
}

//INSERT new mapped course
$query = <<<SQL
INSERT INTO mapped_courses
	VALUES ($1, $2, $3, $4, $5)
ON CONFLICT ON CONSTRAINT mapped_courses_pkey
	DO UPDATE SET mapped_course=EXCLUDED.mapped_course, mapped_section=EXCLUDED.mapped_section
SQL;

$res = pg_query_params($db_conn, $query, array($semester, $course, $section, $mapped_course, $mapped_section));
if ($res === false) {
	exit(sprintf("DB error when INSERTing mapped course %s%s%s%s", $mapped_course, PHP_EOL, pg_last_error($db_conn), PHP_EOL));
}

//Complete
printf("Mapped %s section %s to %s section %s.%s", $course, $section, $mapped_course, $mapped_section, PHP_EOL);
exit(0);

?>
