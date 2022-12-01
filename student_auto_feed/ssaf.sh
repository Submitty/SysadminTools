#!/usr/bin/env bash

# Use this bash script to run a data sourcing script before
# submitty_student_auto_feed.php.  This is intended to be used with cron.
#
# Author: Peter Bailie, Rensselaer Polytechnic Institute

display_usage() {
    cat << EOM
usage: ssaf.sh (data_source) (term) [DB_auth]

data_source: csv_local|imap_remote|json_remote
             Which data sourcing script to run first: csv_local.php,
             imap_remote.php, or json_remote.php (required)
term:        Term code to pass to submitty_student_auto_feed.php  (required)
DB_auth:     DB auth string for submitty_student_auto_feed.php    [optional]
EOM
    exit 1
}

if [ $# -ne 2 ] && [ $# -ne 3 ]; then
    display_usage
fi

CWD=$(dirname "$0")
if [ "$1" = "csv_local" ] || [ "$1" = "imap_remote" ] || [ "$1" = "json_remote" ]; then
    SOURCE="${CWD}/${1}.php"
else
    display_usage
fi

if $SOURCE; then
    if [ "$3" != "" ]; then
        DASH_A="-a$3"
    fi

    DASH_T="-t$2"
    "$CWD"/submitty_student_auto_feed.php "$DASH_T" "$DASH_A"
else
    echo "${1}.php exited $?.  Auto feed not run."
fi
