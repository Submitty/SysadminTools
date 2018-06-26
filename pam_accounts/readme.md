# PAM Authentication Accounts Script
Readme June 26, 2018

### accounts.php
This is a command line script that will read the submitty 'master' database and
create PAM authentication accounts, password checked by Kerberos, for all
Submitty users.  *This script is not used with database authentication.*

---

The semester must be either manually specified as command line argument
`-t`, or guessed by calendar month and year with command line argument `-g`.

For example:

`php ./accounts.php -t s18`

Will run the accounts script for the "s18" (Spring 2018) term.

`php ./accounts.php -g`

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
