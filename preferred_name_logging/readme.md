# Preferred Name Logging

## pnc_compile.pl

This script will help track when user preferred names are changed.  It attempts
to compile a report of who made a preferred name change, and what change had
occurred.

Submitty provides the preferred name change information in the Postgresql log.
This scipt is used to parse the Postgresql log and create its own report
of preferred name changes that is much more human readable.

This script will parse logs based on a specific datestamp.  Since this script
acquires its datestamp based on GMT, it might work best when the postgresql logs
are timestamped in UTC.

This is intended to be run on the postgresql server on at least a daily basis.
Invoke the script with `-y` or `--yesterday` to parse logs with yesterday's
datestamp.  That is useful to run the script overnight after 12AM.

## FERPA

Data processed and logged by this tool may be protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult and abide by your institute's data protection policies.
