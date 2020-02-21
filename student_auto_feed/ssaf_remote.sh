#!/usr/bin/env bash

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

case $1 in
imap)
    ./imap_remote.php
    ;;
json)
    ./json_remote.php
    ;;
*)
    display_usage
    ;;
esac

if [ $? -eq 0 ]; then
    if [ $# -eq 2 ]; then
        ./submitty_student_auto_feed -t $2
    else
        ./submitty_student_auto_feed -t $2 -a $3
    fi
else
    echo "${1}_remote.php exited $?.  Auto feed not run."
fi
