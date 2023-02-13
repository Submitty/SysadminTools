# Preferred Name Change Logging

In the interests of diversity, Submitty provides for users to set a preferred name should it be different from their legal name.  This feature can be abused, so changes to a user's preferred name is recorded into Postgresql's log for review.  To make it easier to locate these logged messages, a sysadmin tools script, `pnc_compile.pl`, is provided to fetch the preferred name change logs from Postgresql and compile them into a human readable report.

**IMPORTANT:** `pnc_compile.pl` needs to operate on a host that can directly access Postgresql's log.  Typically, this means the script must be setup on the same server as Postgresql.

1. Make sure your host has Perl 5.30.0 or later.
  * Ubuntu 20.04 includes Perl 5.30.0.
2. Retrieve `pnc_compile.pl` from [Github](https://raw.githubusercontent.com/Submitty/SysadminTools/main/preferred_name_logging/pnc_compile.pl) (right click link and choose "Save Link As...")
3. Edit code file to setup its configuration.
  * Locate the two lines shown below.  They are near the top of the file.  These lines dictate where to look for Postgresql's log and where to write the script's compiled log.
  * `$PSQL_LOG` dictates where Postgresql's log is located. `$PNC_LOG` dictates where this script will record and append its report.
  * The default for `$PSQL_LOG` is set for Postgresql 12 running in Ubuntu 20.04.  The default for `$PNC_LOG` will write the script's report to the same directory as the script file.
  *  Change these values to match your host's setup.
  ```perl
  my $PSQL_LOG = "/var/log/postgresql/postgresql-12-main.log";
  my $PNC_LOG  = "preferred_name_change_report.log";
  ```
4. Setup a cron schedule to run the script.
  * Postgresql's log is typically owned by `root`, so it is mandatory to run the script as `root`.
  * Be sure to set execute permission on the script.
  * The script will parse Postgresql's log *by the current day's datestamp*, so it is intended that the script is run once per day.
  * Alternatively, if you wish to schedule the crontab for overnight after 12AM, you can set the `-y` or `--yesterday` argument so the script will intentionally parse Postgresql's log by the *previous* day's datestamp.  e.g. `/path/to/pnc_compile.pl -y`

# FERPA

Data processed and logged by this tool may be protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult and abide by your institute's data protection policies.
