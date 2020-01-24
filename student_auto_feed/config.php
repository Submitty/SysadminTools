<?php

/* HEADING ---------------------------------------------------------------------
 *
 * config.php script used by submitty_student_auto_feed
 * By Peter Bailie, Systems Programmer (RPI dept of computer science)
 *
 * Requires minimum PHP version 7.0 with pgsql and iconv extensions.
 *
 * Configuration of submitty_student_auto_feed is structured through defined
 * constants.  Expanded instructions can be found at
 * http://submitty.org/sysadmin/student_auto_feed
 *
 * -------------------------------------------------------------------------- */

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

// Univeristy campus's timezone.  ***THIS NEEDS TO BE SET.
date_default_timezone_set('America/New_York');


/* Definitions for error logging -------------------------------------------- */
// While not recommended, email reports of errors may be disabled by setting
// 'ERROR_EMAIL' to null.
define('ERROR_EMAIL',    'sysadmins@lists.myuniversity.edu');
define('ERROR_LOG_FILE', '/var/local/submitty/bin/auto_feed_error.log');


//Student registration status is important, as data dumps can contain students
//who have dropped a course either before the semester starts or during the
//semester.  This serialized array will contain all valid registered-student
//codes can be expected in the data dump.
//***THIS NEEDS TO BE SET as a serialized array.
//
//IMPORTANT: Consult with your University's IT administrator and/or registrar to
//           add all pertinant student-is-registered codes that can be found in
//           your CSV data dump.  EXAMPLE: 'RA' may mean "registered by advisor"
//           and 'RW' may mean "registered via web"
define('STUDENT_REGISTERED_CODES', array('RA', 'RW'));

//An exceptionally small file size can indicate a problem with the feed, and
//therefore the feed should not be processed to preserve data integrity of the
//users table.  Value is in bytes.  You should pick a reasonable minimum
//threshold based on the expected student enrollment (this could vary a lot by
//university and courses taught).
define('VALIDATE_MIN_FILESIZE', 65536);

//How many columns the CSV feed has (this includes any extraneous columns in the
//CSV that are not needed by submitty_student_auto_feed).
define('VALIDATE_NUM_FIELDS', 10);

// The following constants are used to read the CSV auto feed file provided by
// the registrar / data warehouse.  ***THESE NEED TO BE SET.
//
// CSV_FILE is the full path of the student auto feed file, regardless if it is
//          accessed locally or remotely.
define('CSV_FILE', '/path/to/datafile.csv');

//Define what character is delimiting each field.  ***THIS NEEDS TO BE SET.
//EXAMPLE: chr(9) is the tab character.
define('CSV_DELIM_CHAR', chr(9));

//Properties for database access.  ***THESE NEED TO BE SET.
//If multiple instances of Submitty are being supported, these may be defined as
//parrallel arrays.
define('DB_HOST',     'submitty.cs.myuniversity.edu');
define('DB_LOGIN',    'hsdbu');
define('DB_PASSWORD', 'DB.p4ssw0rd');

/* The following constants identify what columns to read in the CSV dump. --- */
//these properties are used to group data by individual course and student.
//NOTE: If your University does not support "Student's Preferred Name" in its
//      students' registration data -- define COLUMN_PREFERREDNAME as null.
define('COLUMN_COURSE_PREFIX', 8);  //Course prefix
define('COLUMN_COURSE_NUMBER', 9);  //Course number
define('COLUMN_REGISTRATION',  7);  //Student enrollment status
define('COLUMN_SECTION',       10); //Section student is enrolled
define('COLUMN_USER_ID',       5);  //Student's computer systems ID
define('COLUMN_NUMERIC_ID',    6);  //Alternate ID Number (e.g. campus ID number)
define('COLUMN_FIRSTNAME',     2);  //Student's First Name
define('COLUMN_LASTNAME',      1);  //Student's Last Name
define('COLUMN_PREFERREDNAME', 3);  //Student's Preferred Name
define('COLUMN_EMAIL',         4);  //Student's Campus Email
define('COLUMN_TERM_CODE',     11); //Semester code used in data validation

//Validate term code.  Set to null to disable this check.
define('EXPECTED_TERM_CODE', '201705');

//Header row, if it exists, must be discarded during processing.
define('HEADER_ROW_EXISTS', true);

//IMAP
//To Do: Instructions
define('USE_IMAP',      true);
define('IMAP_HOSTNAME', 'localhost');
define('IMAP_PORT',     '993');
define('IMAP_USERNAME', 'user');          //DO NOT USE IN PRODUCTION
define('IMAP_PASSWORD', 'IMAP_P@ssW0rd'); //DO NOT USE IN PRODUCTION
define('IMAP_INBOX',    'INBOX');
define('IMAP_OPTIONS',  array('imap', 'ssl'));
define('IMAP_FROM',     'Data Warehouse');
define('IMAP_SUBJECT',  'Your daily CSV');

//Remote JSON
//To Do: Instructions
define('JSON_REMOTE_HOSTNAME',    'localhost');
define('JSON_REMOTE_PORT',        22);
define('JSON_REMOTE_FINGERPRINT', '00112233445566778899AABBCCDDEEFF00112233');
define('JSON_REMOTE_USERNAME',    'user');          //DO NOT USE IN PRODUCTION
define('JSON_REMOTE_PASSWORD',    'JSON_P@ssW0rd'); //DO NOT USE IN PRODUCTION
define('JSON_REMOTE_PATH',        '/path/to/files/');
define('JSON_LOCAL_PATH',         '/path/to/files/');
define('JSON_LOCAL_CSV_FILE',     '/path/to/new_csv_file.csv');

//Sometimes data feeds are generated by Windows systems, in which case the data
//file probably needs to be converted from Windows-1252 (aka CP-1252) to UTF-8.
//Set to true to convert data feed file from Windows char encoding to UTF-8.
//Set to false if data feed is already provided in UTF-8.
define('CONVERT_CP1252', true);

//Allows "\r" EOL encoding.  This is rare but exists (e.g. Excel for Macintosh).
ini_set('auto_detect_line_endings', true);

//EOF
?>
