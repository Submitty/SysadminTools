# Submitty Student Auto Feed Script
Readme last updated Sept 1, 2023

This is a code example for any University to use as a basis to have Submitty course's enrollment data added or updated on an automated schedule with a student enrollment CSV datasheet.

__WARNING: Student enrollment CSV files may contain private student
information that is protected by [FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please contact your school's IT dept. for advice on your school's data security
policies and practices.__

Detailed instructions can be found at [http://submitty.org/sysadmin/student\_auto\_feed](http://submitty.org/sysadmin/student_auto_feed)

Requires the pgsql extension.  `imap_remote.php` also requires the imap extension.
This system is intended to be platform agnostic, but has been developed and tested
with Ubuntu Linux.

## submitty\_student\_auto\_feed.php
A command line executable script to read a student enrollment data CSV file and
"upsert" (update/insert) student enrollment for all registered courses in Submitty.

This script assumes that all student enrollments for all courses are in a single
CSV file.  Extra courses can exist in the data (such as a department wide CSV),
and any enrollments for courses not registered in Submitty are ignored.

Conceptually, a University's registrar and/or data warehouse will provide a
regularly scheduled data dump, uploaded somewhere as a CSV file.  A sysadmin
should setup a cron job to regularly trigger this script to run when the CSV
file is available.

Alternatively, the helper scripts `imap_remote.php` and `json_remote.php` can be
used to retrieve student enrollment data from an imap email account (as a file
attachment) or from another server as JSON data read via an SSH session.  More
on this, below.

The auto feed script does not need to be run specifically on the Submitty
server, but it will need access to the Submitty "master" database and the
enrollment CSV data file.

### Command Line Arguments

`-t` Specify the term code for the currently active term.  Required.

For example:

`$ php ./submitty_student_auto_feed.php -t s18`

Will run the auto feed script for the "s18" (Spring 2018) term.

`-a` Specify database authentication on the command line as `user:password@server`
This overrides database authentication set in config.php.  Optional.

`-l` Test log reporting.  This can be used to test that logs are being
sent/delivered by email.  This does not process a data CSV.  Optional.

## config.php
A series of `define` statements that is used to configure the auto feed script.
Code comments will help explain usage.  This file must exist in the same directory
as `submitty_student_auto_feed.php`.

## Class files
`ssaf_cli.php`
`ssaf_db.php`
`ssaf_sql.php`
`ssaf_validate.php`

These are class files that are required to run the submitty student auto feed
script.  They must exist in the same directory as `submitty_student_auto_feed.php`.

## imap\_remote.php

This is a helper script that will retrieve a CSV data sheet from an imap email
account.  The data retrieved is written to the same path and file used by
`submitty_student_auto_feed.php`.  Therefore, run `imap_remote.php` first
and the data should be available to `submitty_student_auto_feed.php` for
processing.

Configuration is read from `config.php`.  No command line options.

__Requires the PHP imap extension.__

## json\_remote.php

This helper script is meant to retrieve student enrollment, as JSON data, from
another server via SSH session and write that data as a CSV file usable by
`submitty_student_auto_feed.php`.  This script is highly proprietary and is
very likely to require code changes to be useful at another university.

Configuration is read from `config.php`.  No command line options.  Requires the
PHP ssh2 extension.

## ssaf\_remote.sh

Bash shell script that is used to run either `imap_remote.php` or `json_remote.php`
followed by `submitty_student_auto_feed.php`.  This allows one entry in cron
to first retrieve data from either imap email or a json file from a remote server,
and then process the data with the auto feed.

### Command Line Arguments

Command line arguments are not specified by switches.  Instead, the first
argument is required and must be either `imap` or `json` to specify which helper
script is used to retrieve student enrollment data.  The second argument is
required and dictates the current academic term code.  The third argument is
optional, and if present, is to specify database authentication on the command
line.  These options are then passed to the auto feed when the auto feed is
executed.

For Example:

`$ ./ssaf.sh imap s18 dbuser:dbpassword@dbserver.edu`

This will first run `imap_remote.php` to retrieve student enrollment data, then
run `submitty_student_auto_feed.php` with command line arguments `-t s18`
and `-a dbuser:dbpassword@dbserver.edu`.

## add_drop_report.php

Script used to compile reports on how many students have dropped among all
courses registered in Submitty.

This script should be run before the autofeed and again after the autofeed.
The first run will read the database and write a temporary file of course
enrollment numbers.  The second run will read the temporary file and compare
it with the enrollment numbers in the database -- which may have changed.

The enrollment report will be saved as a text file.  Optionally, this report
can be emailed.  Note that the email function requires `sendmail` or equivalent,
and the emails will be sent unauthenticated.

### Command Line Parameters

The first cli parameter must be either `1` or `2` to designate whether this is
the first (prior to autofeed) or second (after auto feed) run.

Second cli parameter is the term code.

For example:
```
$ ./add_drop_report.php 1 f21
```
Will invoke the _first_ run to cache enrollment values to a temporary file for
the Fall 2021 term.
```
$ ./add_drop_report.php 2 f21
```
Will invoke the _second_ run to create the report of student enrollments for the
Fall 2021 term.

## crn_copymap.php

Create a mapping of CRNs (course, term) that are to be duplicated.  This is
useful if a professor wishes to have a course enrollment, by section,
duplicated to another course.  Particularly when the duplicated course has
no enrollment data provided by IT.

Sections can be a comma separated list, a range denoted by a hyphen, or the
word "all" for all sections.  Note that "all" sections will copy sections
respectively.  i.e. section 1 is copied as section 1, section 2 is copied as
section 2, etc.

### Usage
```bash
$ crn_copymap.php (term) (original_course) (original_sections) (copied_course) (copied_sections)
```
For example:
Copy enrollments of term "f23" (fall 2023) of course CSCI 1000,
sections 1, 3, and 5 through 9 to course CSCI 2000 as sections 2, 4, and 6 through 10
respectively.
```bash
$ crn_copymap.php f23 csci1000 1,3,5-9 csci2000 2,4,6-10
```

EOF
