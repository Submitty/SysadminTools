# Nightly Database Backup Python Script
Readme August 31, 2018

## db_backup.py

This script will read a course list, corresponding to a specific term, from
the 'master' Submitty database.  With a course list, the script will use
Postgresql's "pg_dump" tool to retrieve a SQL dump of the submitty 'master'
database and each registered course's Submitty database of a specific semester.
The script also has cleanup functionality to automatically remove older dumps.

*db_backup.py is written in Python 3, and tested with Python 3.6.*

NOTE: Some modification of code may be necessary to work with your school's
information systems.

### FERPA Warning

WARNING:  Database dumps can contain student information that is protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult with your school's IT dept. for advice on data security policies
and practices.

### Term Code

The term code can be specified as a command line argument as option `-t`.

For example:

`python3 ./db_backup.py -t f17`

will dump the submitty 'master' database and all courses registered with term
code `f17` (Fall 2017).  This option is useful to dump course databases of
previous term, or to dump course databases that have a non-standard term code.

Alternatively, the `-g` option will have the term code guessed by using the
current month and year of the server's date.

The term code will follow the pattern of TYY, where
- T is the term
  - `s` is for Spring (Jan - May)
  - `u` is for Summer (Jun - Jul)
  - `f` is for Fall (Aug-Dec)
- `YY` is the two digit year
- e.g. April 15, 2018 will correspond to "s18" (Spring 2018).

`-t` and `-g` are mutually exclusive, but one is required.

### Date Stamp

Each dump has a date stamp in its name following the format of `YYYY-MM-DD`,
followed by the semester code, then the course code.

e.g. `2018-04-23_s18_cs100.dbdump` is a dump taken on April 23, 2018 of the
Spring 2018 semester for course CS-100.

### Cleanup Schedule

Older dumps can be automatically purged with the command line option `-e`.

For example:

`python3 ./db_backup.py -t f17 -e 7`

will purge any dumps with a stamp seven days or older.  Only dumps of the
term being processed will be purged, in this example, `f17`.

The default expiration value is 0 (no expiration -- no files are purged) should
this argument be ommitted.

### Monthly Retention

Command line option `-m` will set a monthly retention date.  Dumps taken on that
date will not be purged.  In the case the retention date is past the 28th, end
of month dumps will still be retained.

e.g. `-m 30` will retain any dump on the 30th of the month.  In the case of
February, dumps on the 28th, or 29th on a leap year, are also retained.  Dumps
on the 31st of another month are not retained (as they were retained on the
30th).

For clarification: `-m 31` will retain dumps taken on February 28/29;
April, June, September, November 30; and January, March, May, July, August,
October, December 31.

No monthly retention occurs if `-m` is omitted or set `-m 0`.

### Restore a Dump

Submitty databases can be restored from a dump using the pg_restore tool.
q.v. [https://www.postgresql.org/docs/10/static/app-pgrestore.html](https://www.postgresql.org/docs/10/static/app-pgrestore.html)

### Cron

This is script intended to be run as a cronjob by 'root' on the same server
machine as the Submitty system.  *Running this script on another server other
than Submitty has not been tested.*

### Options At The Top Of The Code

`DB_CONFIG_PATH` looks for Submitty's `database.json` file that contains
database authentication information.  Leaving this at the default is usually OK.

`DUMP_PATH` indicates where dump files are stored.  Only change this if the
default location is undesirable for your server.
