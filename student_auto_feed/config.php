<?php

/* HEADING ---------------------------------------------------------------------
 *
 * config.php script used by submitty_student_auto_feed
 * By Peter Bailie, Systems Programmer (RPI dept of computer science)
 *
 * Requires minimum PHP version 7.0 with pgsql and iconv extensions.
 *
 * Configuration of submitty_student_auto_feed is structured through a series
 * of named constants.
 *
 * THIS SOFTWARE IS PROVIDED AS IS AND HAS NO GUARANTEE THAT IT IS SAFE OR
 * COMPATIBLE WITH YOUR UNIVERSITY'S INFORMATION SYSTEMS.  THIS IS ONLY A CODE
 * EXAMPLE FOR YOUR UNIVERSITY'S SYSYTEM'S PROGRAMMER TO PROVIDE AN
 * IMPLEMENTATION.  IT MAY REQUIRE SOME ADDITIONAL MODIFICATION TO SAFELY WORK
 * WITH YOUR UNIVERSITY'S AND/OR DEPARTMENT'S INFORMATION SYSTEMS.
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
date_default_timezone_set('Etc/UTC');


/* Definitions for error logging -------------------------------------------- */
// While not recommended, email reports of errors may be disabled by setting
// 'ERROR_EMAIL' to null.  Please ensure the server running this script has
// sendmail (or equivalent) installed.  Email is sent "unauthenticated".
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
define('DB_LOGIN',    'my_database_user');     //DO NOT USE IN PRODUCTION
define('DB_PASSWORD', 'my_database_password'); //DO NOT USE IN PRODUCTION

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

//Remote IMAP
//This is used by imap_remote.php to login and retrieve a student enrollment
//datasheet, should datasheets be provided via an IMAP email box.  This also
//works with exchange servers with IMAP enabled.
//IMAP_FOLDER is the folder where the data sheets can be found.
//IMAP_OPTIONS: q.v. "Optional flags for names" at https://www.php.net/manual/en/function.imap-open.php
//IMAP_FROM is for validation.  Make sure it matches the identity of who sends the data sheets
//IMAP_SUBJECT is for validation.  Make sure it matches the subject line of the messages containing the data sheet.
//IMAP_ATTACHMENT is for validation.  Make sure it matches the file name of the attached data sheets.
define('IMAP_HOSTNAME',   'imap.cs.myuniversity.edu');
define('IMAP_PORT',       '993');
define('IMAP_USERNAME',   'imap_user');     //DO NOT USE IN PRODUCTION
define('IMAP_PASSWORD',   'imap_password'); //DO NOT USE IN PRODUCTION
define('IMAP_FOLDER',     'INBOX');
define('IMAP_OPTIONS',    array('imap', 'ssl'));
define('IMAP_FROM',       'Data Warehouse');
define('IMAP_SUBJECT',    'Your daily CSV');
define('IMAP_ATTACHMENT', 'submitty_enrollments.csv');

//Remote JSON
//This is used by json_remote.php to read JSON data from another server via
//an SSH session.  The JSON data is then written to a CSV file usable by the
//auto feed.
//JSON_REMOTE_FINGERPRINT must match the SSH fingerprint of the server being
//accessed.  This is to help ensure you are not connecting to an imposter server,
//such as with a man-in-the-middle attack.
//JSON_REMOTE_PATH is the remote path to the JSON data file(s).
define('JSON_REMOTE_HOSTNAME',    'server.cs.myuniversity.edu');
define('JSON_REMOTE_PORT',        22);
define('JSON_REMOTE_FINGERPRINT', '00112233445566778899AABBCCDDEEFF00112233');
define('JSON_REMOTE_USERNAME',    'json_user');     //DO NOT USE IN PRODUCTION
define('JSON_REMOTE_PASSWORD',    'json_password'); //DO NOT USE IN PRODUCTION
define('JSON_REMOTE_PATH',        '/path/to/files/');

//Sometimes data feeds are generated by Windows systems, in which case the data
//file probably needs to be converted from Windows-1252 (aka CP-1252) to UTF-8.
//Set to true to convert data feed file from Windows char encoding to UTF-8.
//Set to false if data feed is already provided in UTF-8.
define('CONVERT_CP1252', true);

//Allows "\r" EOL encoding.  This is rare but exists (e.g. Excel for Macintosh).
ini_set('auto_detect_line_endings', true);

//EOF
?>
