# Preferred Name Logging

This script will help track when user preferred names are changed.  It attempts
to log who was authenticated for the change, and what change occurred.

It works by first having Postgresql log the required information as the data is
updated in Submitty's databases.  Then the sysadmin tool in this folder will
scrape the Postgresql logfile and record only those entries that showed
preferred name change.

This is setup and cofnigured by Submitty during system installation and should
automatically operate daily at 2:05AM.

## FERPA

Data processed and logged by this tool may be protected by
[FERPA (20 U.S.C. § 1232g)](https://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html).
Please consult and abide by your institute's data protection policies.

## Logs

Submitty's installation scripts will configure postgresql to write its logs to
`/var/local/submitty/logs/psql/`, rotated on a daily basis.

This script will scrape the previous day's Postgresql log for any logged
changes to any user's preferred names.  It will then create a daily log of
*preferred name changes* within `/var/local/submitty/logs/preferred_names/`.

This script will also remove postgresql logs older than 2 days as postgresql's
own log rotation system will not selectively remove outdated logs.

## postgresql.conf

The following configuration will be applied to postgresql:
```
log_destination = 'csvlog'
logging_collector = on
log_directory = '/var/log/postgresql'
log_filename = 'postgresql_%Y-%m-%d-%H%M%S.log'
log_file_mode = 0640
log_rotation_age = 1d
log_rotation_size = 0
log_min_messages = log
log_min_duration_statement = 0
log_line_prefix = '%t '
```

## preferred_names.json

A sysadmin may optionally create a json file to configure a couple of options
for preferred name logging.  If this json is not created, the script will
assume default settings, instead.

To set these options, first create an empty text file in
`usr/local/submitty/config/preferred_names.json`

Next, following the json format, you may set the following options (anything
else in the file will be ignored).

* `log_emails`
  This is either a singular email address or a list of email addresses.  The
  script will send error messages to the email address(es) listed.

  If this is a list, key values are ignored.  But you could set key values to
  document who owns a particular email address.

  Set to `null` to turn this off.  Default setting is `null`.

* `log_file_retention`
  A whole number representing how many days of preferred name change logs to
  keep.  *This does not affect postgresql's logs.*  Default setting is 7.

  Example json:
```json
{
    "log_emails":
    {
        "Ada_Lovelace": "alovelace@submitty.com",
        "Charles_Babbage": "cbabbage@submitty.com",
        "Sysadmin_Mailing_List": "sysadmins@lists.submitty.com"
    },

    "log_file_retention": 30
}
```
