#!/usr/bin/env perl -l

# File: ugly_password.pl
# Date: August 6, 2018
# Author: Peter Bailie
#
# This will output to STDOUT a randomly generated 32 character ugly password
# using the printable ASCII character range of 33 - 126.  Passwords are based
# on /dev/random to ensure reliable pseudo-random entropy.
#
# /dev/random is blocking, so there may be a delay in output when the seed pool
# needs to be refreshed.

use strict;
use warnings;
use autodie;

# Get 32 random bytes from /dev/random
open my $fh, '<:raw', '/dev/random';
read $fh, my $bytes, 32;
close $fh;

#Convert each byte to a printable ascii char (ascii 33 - 126).
my $output = "";
foreach my $i (0..(length $bytes) - 1) {
	$output .=  chr((unpack 'C', substr $bytes, $i, 1) % 94 + 33);
}

print STDOUT $output;
