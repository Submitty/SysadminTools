#!/usr/bin/env perl

# File: ugly_password.pl
# Original Date: August 7, 2018
# Updated: October 25, 2018
# Author: Peter Bailie
#
# This will output to STDOUT a randomly generated ugly password using the
# printable ASCII character range of 33 - 126.  Passwords are based on
# /dev/random to ensure reliable pseudo-random entropy.
#
# /dev/random is blocking, so there may be a (possibly lengthy) delay in
# output when the seed pool needs to be refreshed.

use strict;
use warnings;
use autodie;

my $PW_LENGTH = 32;  # length of the ugly password in characters.
my ($fh, $byte, $val, $output);

# These chars not allowed to help prevent some edge cases for copy/pasting to
# psql, cli, and PHP.  Indexed by ASCII value.
my %chars_not_allowed = (34, "\"", 37, "%", 39, "'", 61, "=", 92, "\\", 96, "`");

open $fh, '<:raw', '/dev/random';
$output = "";  # password output
while (length $output < $PW_LENGTH) {
	# Read a random byte and scale it to range 33 - 126.
	read $fh, $byte, 1;
	$val = (unpack 'C', $byte) % 94 + 33;

	# Make sure random $val qualifies as a char (not in the "not allowed" hash)
	if  (!exists $chars_not_allowed{$val}) {
		# Character qualifies, append it to password.
		$output .= chr $val;
	}
}
close $fh;
print STDOUT $output . "\n";
