#!/usr/bin/env perl

# Read Postgresql log and compile preferred name change report.

# Invoke with -y or --yesterday to compile with yesterday's timestamps.
# Useful if you run this script overnight starting after 12AM.

# Author: Peter Bailie, RPI Research Computing
# Date:   April 29, 2022

use strict;
use warnings;
use autodie;
use v5.30.0;
use POSIX qw(strftime);

# CONFIG -- denotes full path and file
my $PSQL_LOG = "/var/log/postgresql/postgresql-12-main.log";
my $PNC_LOG  = "preferred_name_change_report.log";

# Main
print STDERR "Root required.\n" and exit 1 if ($> != 0);

my $epoch_offset = 0;
$epoch_offset = -86400 if (scalar @ARGV > 0 && ($ARGV[0] eq "-y" || $ARGV[0] eq "--yesterday"));
my $datestamp = strftime "%Y-%m-%d", gmtime(time + $epoch_offset);

open my $psql_fh, "<:encoding(UTF-8)", $PSQL_LOG;
open my $pnc_fh, ">>:encoding(UTF-8)", $PNC_LOG;

my ($timestamp, $userid, $auth) = ("", "", "");
my ($oldfn, $newfn, $oldln, $newln, $line1, $line2);
while (<$psql_fh>) {
    ($timestamp, $userid, $oldfn, $newfn, $oldln, $newln) = ($1, $2, $3, $4, $5, $6) if (/^${datestamp} (\d{2}:\d{2}:\d{2}\.\d{3} [A-Z]{3}).+DETAIL:  USER_ID: "(.+?)" (?:PREFERRED_FIRSTNAME OLD: "(.*?)" NEW: "(.*?)" )?(?:PREFERRED_LASTNAME OLD: "(.*?)" NEW: "(.*?)")?$/);
    $auth = $1 if (/\/\* AUTH: "(.+)" \*\//);
    # $auth is always on a different line than $timestamp, $userid.
    # But all three having data will indicate we have collected pref name change logs.
    if ($timestamp ne "" && $userid ne "" && $auth ne "") {
        # $oldfn, $newfn, $oldln, $oldfn -- some may be undefined.
        # This happens when either the firstname or lastname change wasn't recorded in PSQL logs (because no change occured).
        # Undefined vars need to be defined to prevent 'concatenation by undefned var' warning.
        foreach ($oldfn, $newfn, $oldln, $newln) {
             $_ = "" if (!defined $_);
        }

        # If both old and new firstnames are blank, no change was logged.
        if ($oldfn ne "" || $newfn ne "") {
            ($oldfn, $newfn) = rpad(19, $oldfn, $newfn);
            $line1 = "  OLD PREF FIRSTNAME: ${oldfn}";
            $line2 = "  NEW PREF FIRSTNAME: ${newfn}";
        } else {
            ($line1, $line2) = ("", "");
            ($line1, $line2) = rpad(41, $line1, $line2);
        }

        # If both old and new lastnames are blank, no change was logged.
        if ($oldln ne "" || $newln ne "") {
            ($oldln, $oldfn) = rpad(19, $oldln, $oldfn);
            $line1 .= " OLD PREF LASTNAME: ${oldln}\n";
            $line2 .= " NEW PREF LASTNAME: ${newln}\n";
        } else {
            $line1 .= "\n";
            $line2 .= "\n";
        }

        ($userid) = rpad(9, $userid);
        print $pnc_fh "${datestamp} ${timestamp}  USER: ${userid}  CHANGED BY: ${auth}\n";
        print $pnc_fh $line1;
        print $pnc_fh $line2;
        ($timestamp, $userid, $auth) = ("", "", "");
    }
}

close ($pnc_fh);
close ($psql_fh);
exit 0;

# Right-pad string(s) with whitespaces.
# expected parameters: (1) padding value, (2...n) strings to pad
# return: list of padded strings
sub rpad {
    my $numpadding = shift;
    $_ = sprintf("%-${numpadding}s", $_) foreach @_;
    return @_;
}
