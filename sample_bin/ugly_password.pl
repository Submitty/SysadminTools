#!/usr/bin/env perl -l

# File: ugly_password.pl
# Date: August 6, 2018
# Author: Peter Bailie
#
# This will output to STDOUT a randomly generated ugly password using the
# printable ASCII character range of 33 - 126.  Passwords are based on
# /dev/random to ensure reliable pseudo-random entropy.
#
# /dev/random is blocking, so there may be a delay in output when the seed pool
# needs to be refreshed.

use strict;
use warnings;
use autodie;

my $PW_LENGTH = 32;  # length of the ugly password in characters.
my ($fh, $byte, $val, $output);

$output = "";  # password output
open $fh, '<:raw', '/dev/random';
build_ugly_password();
close $fh;
print STDOUT $output;
exit 0;

# Recursive subroutine to generate a random char and build the ugly password.
sub build_ugly_password {
	# Read a random byte and scale it to range 33 - 126.
	read $fh, $byte, 1;
	$val = (unpack 'C', $byte) % 94 + 33;

	# Single quote, double quote, and backtick chars are disqualified.
	# Prevents some edge cases for copy/pasting ugly passwords to psql or cli.
	if ($val != 34 && $val != 39 && $val != 96) {
		# Character qualifies, append it to password.
		$output .= chr $val;
	}

	# Go again, if needed.
	if (length $output < $PW_LENGTH) {
		build_ugly_password();
	}
}
