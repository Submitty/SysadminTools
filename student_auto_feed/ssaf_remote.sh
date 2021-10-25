#!/usr/bin/env bash

# Use this bash script to run either imap_remote.php or json_remote.php before
# submitty_student_auto_feed.php.  This is intended to be used with cron.
#
# Author: Peter Bailie, Rensselaer Polytechnic Institute

display_usage() {
    cat << EOM
usage: ssaf_remote.sh (imap|json) (term) [DB_auth]

imap|json:  first run either imap_remote.php or json_remote.php  (required)
term:       term code to pass to submitty_student_auto_feed.php  (required)
DB_auth:    DB auth string for submitty_student_auto_feed.php    [optional]
EOM
    exit 1
}

if [ $# -ne 2 ] && [ $# -ne 3 ]; then
    display_usage
fi

CWD=$(dirname "$0")
if [ "$1" = "imap" ] || [ "$1" = "json" ]; then
    REMOTE="${CWD}/${1}_remote.php"
else
    display_usage
fi

if $REMOTE; then
    if [ "$3" != "" ]; then
        DASH_A="-a$3"
    fi

    DASH_T="-t$2"
    "$CWD"/submitty_student_auto_feed.php "$DASH_T" "$DASH_A"
else
    echo "${1}_remote.php exited $?.  Auto feed not run."
fi
