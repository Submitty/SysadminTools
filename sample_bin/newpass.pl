#!/usr/bin/perl -w

use strict;
use warnings;

my $dictsize = 68400;

open WORDS, "/var/local/submitty/bin/words" or die "Could not open file\n";

my $wnone = int(rand($dictsize));
my $wntwo = int(rand($dictsize));
my $wnthree = int(rand ($dictsize));
my $wordone = 'one';
my $wordtwo = 'two';
my $wordthree = 'three';
my @punct = ('!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '_', '-', '+', '=', '[', ']', '{', '}', '<', '>');
my @nums = (0, 1, 2, 3, 4, 5, 6, 7, 8, 9);

my $count = 1;

while (<WORDS>) {
	chomp $_;
	if ($count== $wnone) {
		$wordone = $_;
		}
	if ($count==$wntwo) {
		$wordtwo = $_;
		}
	if ($count==$wnthree) {
		$wordthree = $_;
		}
	$count=$count+1;
}

my $sone = @punct[(int(rand(20)))];
my $stwo = @nums[(int(rand(10)))];

print "$wordone$sone$wordtwo$stwo$wordthree\n";
