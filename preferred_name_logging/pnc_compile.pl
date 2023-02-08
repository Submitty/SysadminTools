#!/usr/bin/env perl

# Read Postgresql log and compile preferred name change report.

# Invoke with -y or --yesterday to compile with yesterday's timestamps.
# Useful if you run this script overnight starting after 12AM.

# Author:  Peter Bailie, RPI Research Computing
# Date:    April 29, 2022
# Updated: February 7, 2023

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

# fn = firstname/givenname, ln = lastname/familyname
my ($timestamp, $userid, $auth, $oldfn, $newfn, $oldln, $newln, $line1, $line2);
my $regex_check = 1;
while (<$psql_fh>) {
    if ($regex_check == 1) {
        if ($_ =~ m/^${datestamp} (\d{2}:\d{2}:\d{2}\.\d{3} [A-Z]{3}).+LOG:  PREFERRED_NAME DATA UPDATE$/) {
            $timestamp = $1;
            $regex_check = 2;
        }
    } elsif ($regex_check == 2) {
        if ($_ =~ m/DETAIL:  USER_ID: "(.+?)" (?:PREFERRED_GIVENNAME OLD: "(.*?)" NEW: "(.*?)" )?(?:PREFERRED_FAMILYNAME OLD: "(.*?)" NEW: "(.*?)")?/) {
            ($userid, $oldfn, $newfn, $oldln, $newln) = ($1, $2, $3, $4, $5);
            $regex_check = 3;
        }
    } elsif ($regex_check == 3) {
        if ($_ =~ m/\/\* AUTH: "(.+)" \*\//) {
            $auth = $1;

            # $oldfn, $newfn, $oldln, $oldfn -- some may be undefined.
            # This happens when either the givenname or familyname change wasn't recorded in PSQL logs (because no change occured).
            # Undefined vars need to be defined to prevent 'concatenation by undefned var' warning.
            foreach ($oldfn, $newfn, $oldln, $newln) {
                $_ = "" if (!defined $_);
            }

            # If both old and new given names are blank, no change was logged.
            if ($oldfn ne "" || $newfn ne "") {
                ($oldfn, $newfn) = rpad(19, $oldfn, $newfn);
                $line1 = "  OLD PREF GIVENNAME: ${oldfn}";
                $line2 = "  NEW PREF GIVENNAME: ${newfn}";
            } else {
                ($line1, $line2) = ("", "");
                ($line1, $line2) = rpad(41, $line1, $line2);
            }

            # If both old and new family names are blank, no change was logged.
            if ($oldln ne "" || $newln ne "") {
                ($oldln, $oldfn) = rpad(19, $oldln, $oldfn);
                $line1 .= " OLD PREF FAMILYNAME: ${oldln}\n";
                $line2 .= " NEW PREF FAMILYNAME: ${newln}\n";
            } else {
                $line1 .= "\n";
                $line2 .= "\n";
            }

            ($userid) = rpad(9, $userid);
            print $pnc_fh "${datestamp} ${timestamp}  USER: ${userid}  CHANGED BY: ${auth}\n";
            print $pnc_fh $line1;
            print $pnc_fh $line2;

            $regex_check = 1;
        }
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
