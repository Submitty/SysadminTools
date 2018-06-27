# Nightly Database Backup Python Script
Readme June 26, 2018

### db_backup.py

This script will read a course list, corresponding to a specific term, from
the 'master' Submitty database.  With a course list, the script will use
Postgresql's "pg_dump" tool to retrieve a SQL dump of the submitty 'master'
database and each registered course's Submitty database of a specific semester.
The script also has cleanup functionality to automatically remove older dumps.

*db_backup.py is written in Python 3, and tested with Python 3.4.*

---

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
  - **s** is for Spring (Jan - May)
  - **u** is for Summer (Jun - Jul)
  - **f** is for Fall (Aug-Dec)
- YY is the two digit year
- e.g. April 15, 2018 will correspond to "s18" (Spring 2018).

`-t` and `-g` are mutually exclusive.

---

Each dump has a date stamp in its name following the format of "YYMMD",
followed by the semester code, then the course code.

e.g. '180423_s18_cs100.dbdump' is a dump taken on April 23, 2018 of the Spring
2018 semester for course CS-100.

Older dumps can be automatically purged with the command line option "-e".

For example:

`python3 ./db_backup.py -t f17 -e 7`

will purge any dumps with a stamp seven days or older.  Only dumps of the
term being processed will be purged, in this example, 'f17'.

The default expiration value is 0 (no expiration -- no files are purged) should
this argument be ommitted.

---

Submitty databases can be restored from a dump using the pg_restore tool.
q.v. [https://www.postgresql.org/docs/9.5/static/app-pgrestore.html](https://www.postgresql.org/docs/9.5/static/app-pgrestore.html)

This is script intended to be run as a cronjob by 'root' on the same server
machine as the Submitty system.  *Running this script on another server other
than Submitty has not been tested.*

---

Please configure options near the top of the code.

DB_HOST: Hostname of the Submitty databases.  You may use 'localhost' if
Postgresql is on the same machine as the Submitty system.

DB_USER: The username that interacts with Submitty databases.  Typically
'hsdbu'.

DB_PASS: The password for Submitty's database account (e.g. account 'hsdbu').
**Do NOT use the placeholder value of 'DB.p4ssw0rd'**

DUMP_PATH: The folder path to store the database dumps.  Course folders will
be created from this path, and the dumps stored in their respective course
folders, grouped by semester.

---

WARNING:  Database dumps can contain student information that is protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult with your school's IT dept. for advice on data security policies
and practices.

---

db_backup.py is tested to run on Python 3.4 or higher.

NOTE: Some modification of code may be necessary to work with your school's
information systems.
