# Submitty Student Auto Feed Script
Readme June 26, 2018

This is a code example for any University to use as a basis to have student
enrollment data added or updated on an automated schedule.

Instructions can be found at [http://submitty.org/sysadmin/student\_auto\_feed](http://submitty.org/sysadmin/student_auto_feed)

### config.php
A series of define statements that is used to configure the auto feed script.
Code comments will help explain usage.

### submitty\_student\_auto\_feed.php
A command line executable script that is a code class to read a student
enrollment data form in CSV format and "upsert" (update/insert) student
enrollment for all registered courses in Submitty.

This code assumes that all student enrollments for all courses are in a single
CSV file.  Extra courses can exist in the data (such as a department wide CSV),
and any enrollments for courses not registered in Submitty are ignored.

Conceptually, a University's registrar and/or data warehouse will provide a
regularly scheduled data dump, uploaded somewhere as a CSV file.  A sysadmin
should setup a cron job to regularly trigger this script to run when the CSV
file is available.

The auto feed script does not need to be run specifically on the Submitty
server, but it will need access to the Submitty "master" database and the
enrollment CSV data file.

---

The semester must be either manually specified as command line argument
`-t`, or guessed by calendar month and year with command line argument `-g`.

For example:

`php ./submitty_student_auto_feed.php -t s18`

Will run the accounts script for the "s18" (Spring 2018) term.

`php ./submitty_student_auto_feed.php -g`

Will guess the term code based on the calendar month and year.  The term code
will follow the pattern of TYY, where

- T is the term
  - **s** is for Spring (Jan - May)
  - **u** is for Summer (Jun - Jul)
  - **f** is for Fall (Aug-Dec)
- YY is the two digit year
- e.g. April 15, 2018 will correspond to "s18" (Spring 2018).

`-g` and `-t` are mutually exclusive.

---

WARNING:  Student enrollment CSV files may contain private student
information that is protected by [FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please contact your school's IT dept. for advice on your school's data security
policies and practices.

---

Requires at least PHP 5.6 with pgsql, iconv, and ssh2 extensions.

NOTE: Some modification of code may be necessary to work with your school's
information systems.

q.v. PAM Authentication Accounts script
