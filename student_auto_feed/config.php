<?php

/* HEADING ---------------------------------------------------------------------
 *
 * config.php script used by submitty_student_auto_feed
 * By Peter Bailie, Systems Programmer (RPI dept of computer science)
 *
 * Requires minimum PHP version 7.3 with pgsql extension.
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

//Properties for database access.  ***THESE NEED TO BE SET.
define('DB_HOST',     'submitty.cs.myuniversity.edu');
define('DB_LOGIN',    'my_database_user');     //DO NOT USE IN PRODUCTION
define('DB_PASSWORD', 'my_database_password'); //DO NOT USE IN PRODUCTION

// CSV_FILE is the full path of the student auto feed file, regardless if it is
// accessed locally or remotely.
define('CSV_FILE', '/path/to/datafile.csv');

/* STUDENT REGISTRATION CODES --------------------------------------------------
 *
 * Student registration status is important, as data dumps can contain students
 * who have dropped a course either before the semester starts or during the
 * semester.  Be sure that all codes are set as an array, even when only one
 * code is found in the CSV data.  Set to NULL when there are no codes for
 * either student auditing a course or students late-withdrawn from a course.
 *
 * IMPORTANT: Consult with your University's IT administrator and/or registrar
 *            for the pertinant student registration codes that can be found in
 *            your CSV data dump.
 *
 * -------------------------------------------------------------------------- */

// These codes are for students who are registered to take a course for a grade.
// EXAMPLE: 'RA' may mean "registered by advisor" and 'RW' may mean
// "registered via web".  Do not set to NULL.
define('STUDENT_REGISTERED_CODES', array('RA', 'RW'));

// These codes are for students auditing a course.  These students will not be
// given a grade.
// Set this to NULL if your CSV data does not provide this information.
define('STUDENT_AUDIT_CODES', array('AU'));

// These codes are for students who have dropped their course after the drop
// deadline and are given a late-drop or withdrawn code on their transcript.
// Set this to NULL if your CSV data does not provide this information.
define('STUDENT_LATEDROP_CODES', array('W'));

//An exceptionally small file size can indicate a problem with the feed, and
//therefore the feed should not be processed to preserve data integrity of the
//users table.  Value is in bytes.  You should pick a reasonable minimum
//threshold based on the expected student enrollment (this could vary a lot by
//university and courses taught).
define('VALIDATE_MIN_FILESIZE', 65536);

//How many columns the CSV feed has (this includes any extraneous columns in the
//CSV that are not needed by submitty_student_auto_feed).
define('VALIDATE_NUM_FIELDS', 10);

//What ratio of dropped student enrollments is suspiciously too high, which may
//indicate a problem with the CSV data file -- between 0 and 1.  Set to NULL to
//disable this check.
define('VALIDATE_DROP_RATIO', 0.5);

//Define what character is delimiting each field.  ***THIS NEEDS TO BE SET.
//EXAMPLE: chr(9) is the tab character.
define('CSV_DELIM_CHAR', chr(9));

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
define('COLUMN_REG_ID',        12); //Course and Section registration ID

//Validate term code.  Set to null to disable this check.
define('EXPECTED_TERM_CODE', '201705');

//Header row, if it exists, must be discarded during processing.
define('HEADER_ROW_EXISTS', true);

//Set to true, if Submitty is using SAML for authentication.
define('PROCESS_SAML', true);

/* DATA SOURCING --------------------------------------------------------------
 * The Student Autofeed provides helper scripts to retrieve the CSV file for
 * processing.  Shell script ssaf.sh is used to invoke one of the helper
 * scripts and then execute the autofeed.  Current options are csv_local.php,
 * imap_remote.php, and json_remote.php
 * ------------------------------------------------------------------------- */

//Local CSV
//This is used by csv_local.php to reference where the CSV file is provided.
define('LOCAL_SOURCE_CSV', '/path/to/csv');

//Remote IMAP
//This is used by imap_remote.php to login and retrieve a student enrollment
//datasheet, should datasheets be provided via an IMAP email box.  This also
//works with exchange servers (local network and cloud) with IMAP and basic
//authentication enabled.
//Note that this does NOT work should exchange require OAuth2.
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

/* ADD/DROP REPORTING ------------------------------------------------------ */

// Where to email reports.  Set to null to disable sending email.
// Sendmail (or equivalent) needs to be installed on the server and configured
// in php.ini.  Reports are sent "unauthenticated".
define('ADD_DROP_TO_EMAIL', "admin@cs.myuniversity.edu");

// Where emailed reports are sent from.
// Doesn't actually have to be an account on the server running this script, so
// a common email address or mailing list for sysadmins is fine.
define('ADD_DROP_FROM_EMAIL', "sysadmins@lists.myuniversity.edu");

// Base dir where reports are written.  They will be further sorted to sub dirs
// 'tmp' or the current semester code.
define('ADD_DROP_FILES_PATH', "path/to/reports/");

/* CRN Copymap ------------------------------------------------------------- */

// Where is the crn copymap CSV located.  Set to NULL is this is not used.
define('CRN_COPYMAP_FILE', "path/to/csv");

//EOF
?>
