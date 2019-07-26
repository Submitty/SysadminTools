# Preferred Name Logging

This tool will help track when user preferred names are changed.  It attempts to
log who was authenticated for the change, and what change occurred.

It works by first having Postgresql log the required information as the data is
updated in Submitty's databases.  Then the sysadmin tool in this folder will
scrape the Postgresql logfile and record only those entries that showed
preferred name change.

## FERPA

Data processed and logged by this tool may be protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult and abide by your institute's data protection policies.

## Postgresql

Submitty's website code and database schema will instruct Postgresql to log any
`UPDATE` query involving any user's preferred first or last name changes.

### Postgresql Configuration

Postgresql needs to be configured to produce the required logs as the necessary
logging is not enabled by default.

#### Config file

- Config file is `Postgresql.conf`
   - In Ubuntu 18.04, it is located in `/etc/postgresql/10/main/postgresql.conf`

#### Config options

You will find these options under "ERROR REPORTING AND LOGGING".  Please enable
(remove the '#' symbol preceding the line) and set these values:

- `log_destination = 'csvlog'`
  - The sysadmin tool will scrape a CSV file.
- `logging_collector = on`
  - Postgresql doesn't write logs without. this.
- `log_directory = '/var/log/postgresql'`
  - You can use a different folder, if needed.
- `log_filename = 'postgresql-%Y-%m-%d_%H%M%S.log'`
  - You can change the filename a little, but it **must** end with `-%Y-%m-%d_%H%M%S.log`
- `log_file_mode = 0600`
  - Limits access to this logfile.
- `log_rotation_age = 2d`
  - At least 2 days of logs will be needed since the sysadmin tool is intended
    to run the following day.
- `log_rotation_size = 10MB`
  - This doesn't need to be changed.  Any additional logfiles created for a
    single day will be picked up by the sysadmin tool.
- `log_min_messages = log`
  - Preferred name changes are logged at the `log` level.  You can set any
    higher level of detail, but not lower than `log`.
- `log_min_duration_statement = 0`
  - We want all log instances regardless of process time.
- `log_line_prefix = '%m [%p] %q%u@%d '`
  - This can be changed so long as the very first detail is a timestamp.  The
    sysadmin tool expects to find a timestamp at the very first column.

## Sysadmin Tool

The sysadmin tool needs to be run on a machine with local file access to the
Postgresql log file.  It is written in PHP.

### Sysdamin Tool Config

The configuration is defined as a class constant.  Near the top of the code is a
small block starting as `private const CONFIG = array(`.  Inside the block will
be a config element in single quotes, an arrow like `=>`, a value possibly
enclosed in double quotes, followed by a comma.  (Do not lose the comma!)

- `'timezone' => "America/New_York",`
  - Set this to your local timezone. q.v.
    [https://www.php.net/manual/en/timezones.php](https://www.php.net/manual/en/timezones.php)
    for more information.
- `'postgresql_logfile_path' => "/var/log/postgresql/",`
  - Set this to the same setting as `log_directory` in `postgresql.conf`.
- `'submitty_logfile_path' => "/var/log/submitty/",`
  - Where the sysadmin tool will write the preferred name logfile.
- `'postgresql_logfile' => "postgresql",`
  - The name of the logfile created by Postgresql.  Do not include the time
    stamp.  This only needs to be changed when `log_filename` in
    `postgresql.conf` is changed.
- `'submitty_logfile' => "submitty_preferred_names",`
  - Name of the preferred name change logfile.  You can leave this as is.
- `'submitty_log_retention' => 7`
  - How many days of preferred name change logs to keep.

### Running the Sysadmin Tool

This tool is meant to be executed on the command line and can be scheduled as a
cron job.  Errors will be outputted to `STDERR`, which in a crontab can either
be redirected to `/dev/null` or emailed to a sysadmin.  Running as `root` is
required, and there are no command line arguments.
