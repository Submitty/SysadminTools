# PAM Authentication Accounts Script
Readme August 7, 2018

### accounts.php
This is a command line script that will read the submitty 'master' database and
create authentication accounts for all Submitty users for PAM.

**This script is not used with Submitty database authentication.**

---

One of the following command line parameters is required.  They are listed
in order of precedence.  e.g. `-a` overrides `-t`.

- `-a` Create authentication accounts for all instructor users of all courses
and student, grader, and mentor users in all *active* courses, regardless of
term.
- `-t [term code]` Specify which term to create authentication accounts.
- `-g` Guess the term code, based on calendar month and year, to create
authentication accounts.
- `-r` Remove student, grader, and mentor authentication accounts for all
*inactive* courses, regardless of term code.  Instructor authentication accounts
are not removed.

For example:

`php ./accounts.php -t s18`

Will run the accounts script for the "s18" (Spring 2018) term.

`php ./accounts.php -g`

Will guess the term code based on the calendar month and year.  The term code
will follow the pattern of TYY, where

- T is the term
  - **s** is for Spring (Jan - May)
  - **u** is for Summer (Jun - Jul)
  - **f** is for Fall (Aug - Dec)
- YY is the two digit year
- e.g. April 15, 2018 will correspond to "s18" (Spring 2018).

`-g` and `-t` are mutually exclusive.

---

accounts.php is intended to be run as a cron job.

- Must be run on the Submitty server as root.  Consult a sysadmin for help.
- This script works with the student auto script.  However, because professors
can manually add users, accounts.php needs to be run more frequently than the
student auto feed script.
- Recommendation: if this script is run every hour by cronjob, professors can
advise students who are manually added that they "will have access to Submitty
within an hour."

---

Requires at least PHP 5.6 with the pgsql extension.

NOTE: Some modification of code may be necessary to work with your school's
information systems.

q.v. Submitty Student Auto Feed script
