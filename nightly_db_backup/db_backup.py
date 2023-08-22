#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
:file:     db_backup.py
:language: python3
:author:   Peter Bailie (Systems Programmer, Dept. of Computer Science, RPI)
:date:     August 28 2018

This script will take backup dumps of each individual Submitty course
database.  This should be set up by a sysadmin to be run on the Submitty
server as a cron job by root.  Recommend that this is run nightly.

The term code can be specified as a command line argument "-t".
The "-g" argument will guess the semester by the current month and year.
Either -t or -g must be specified.

Dumpfile expiration can be specified as a command line argument "-e".  This
indicates the number of days of dumps to keep.  Older dumps will be purged.
Only old dumps of the semester being processed will be purged.  Argument value
must be an unsigned integer 0 - 999 or an error will be issued.  "No expiration"
(no files are purged regardless of age) is indicated by a value of 0, or when
this argument is ommitted.

WARNING: Backup data contains sensitive information protected by FERPA, and
as such should have very strict access permissions.

Change values under CONFIGURATION to match access properties of your
university's Submitty database and file system.
"""

import argparse
import calendar
import datetime
import json
import os
import re
import subprocess
import sys

# DATABASE CONFIGURATION PATH
DB_CONFIG_PATH = '/usr/local/submitty/config/database.json'

# WHERE DUMP FILES ARE WRITTEN
DUMP_PATH = '/var/local/submitty/submitty-dumps'

def delete_obsolete_dumps(working_path, monthly_retention, expiration_date):
	"""
	Recurse through folders/files and delete any obsolete dump files

	:param working_path:      path to recurse through
	:param monthly_retention: day of month that dump is always preserved (val < 1 when disabled)
	:param expiration_date:   date to begin purging old dump files
	:type working_path:       string
	:type monthly_retention:  integer
	:type expiration_date:    datetime.date object
	"""

	# Filter out '.', '..', and any "hidden" files/directories.
	# Prepend full path to all directory list elements
	regex = re.compile('^(?!\.)')
	files_list = filter(regex.match, [working_path + '/{}'.format(x) for x in os.listdir(working_path)])
	re.purge()

	for file in files_list:
		if os.path.isdir(file):
			# If the file is a folder, recurse
			delete_obsolete_dumps(file, monthly_retention, expiration_date)
		else:
			# Determine file's date from its filename
			# Note: datetime.date.fromisoformat() doesn't exist in Python 3.6 or earlier.
			filename = file.split('/')[-1]
			datestamp = filename.split('_')[0]
			year, month, day = map(int, datestamp.split('-'))
			file_date = datetime.date(year, month, day)

			# Conditions to NOT delete old file:
			if file_date > expiration_date:
				pass
			elif file_date.day == monthly_retention:
				pass
			# A month can be as few as 28 days, but we NEVER skip months even when "-m" is 28, 29, 30, or 31.
			elif monthly_retention > 28 and (file_date.day == calendar.monthrange(file_date.year, file_date.month)[1] and file_date.day <= monthly_retention):
				pass
			else:
				os.remove(file)

def main():
	""" Main """

	# ROOT REQUIRED
	if os.getuid() != 0:
		raise SystemExit('Root required. Please contact your sysadmin for assistance.')

	# READ COMMAND LINE ARGUMENTS
	# Note that -t and -g are different args and mutually exclusive
	parser = argparse.ArgumentParser(description='Dump all Submitty databases for a particular academic term.', prefix_chars='-', add_help=True)
	parser.add_argument('-e', action='store', type=int, default=0, help='Set number of days expiration of older dumps (default: no expiration).', metavar='days')
	parser.add_argument('-m', action='store', type=int, default=0, choices=range(0,32), help='Day of month to ALWAYS retain a dumpfile (default: no monthly retention).', metavar='day of month')
	group = parser.add_mutually_exclusive_group(required=True)
	group.add_argument('-t', action='store', type=str, help='Set the term code.', metavar='term code')
	group.add_argument('-g', action='store_true', help='Guess term code based on calender month and year.')

	args = parser.parse_args()

	# Get current date -- needed throughout the script, but also used when guessing default term code.
	today		= datetime.date.today()
	year		= today.strftime("%y")
	today_stamp = today.isoformat()

	# PARSE COMMAND LINE ARGUMENTS
	expiration = args.e
	if args.g is True:
		# Guess the term code by calendar month and year
		# Jan - May = (s)pring, Jun - July = s(u)mmer, Aug - Dec = (f)all
		# if month <= 5: ... elif month >=8: ... else: ...
		semester = 's' + year if today.month <= 5 else ('f' + year if today.month >= 8 else 'u' + year)
	else:
		semester = args.t

	# MONTHLY RETENTION DATE
	monthly_retention = args.m

	# GET DATABASE CONFIG FROM SUBMITTY
	fh = open(DB_CONFIG_PATH, "r")
	db_config = json.load(fh)
	fh.close()
	DB_HOST = db_config['database_host']
	DB_USER = db_config['database_user']
	DB_PASS = db_config['database_password']

	# GET ACTIVE COURSES FROM 'MASTER' DB
	try:
		sql = "select course from courses where term='{}'".format(semester)
		# psql postgresql://user:password@host/dbname?sslmode=prefer -c "COPY (SQL code) TO STDOUT"
		process = "psql postgresql://{}:{}@{}/submitty?sslmode=prefer -c \"COPY ({}) TO STDOUT\"".format(DB_USER, DB_PASS, DB_HOST, sql)
		result = list(subprocess.check_output(process, shell=True).decode('utf-8').split(os.linesep))[:-1]
	except subprocess.CalledProcessError:
		raise SystemExit("Communication error with Submitty 'master' DB")

	if len(result) < 1:
		raise SystemExit("No registered courses found for semester '{}'.".format(semester))

	# BUILD LIST OF DBs TO BACKUP
	# Initial entry is the submitty 'master' database
	# All other entries are submitty course databases
	course_list = ['submitty'] + result

	# MAKE/VERIFY BACKUP FOLDERS FOR EACH DB
	for course in course_list:
		dump_path = '{}/{}/{}/'.format(DUMP_PATH, semester, course)
		try:
			os.makedirs(dump_path, mode=0o700, exist_ok=True)
			os.chown(dump_path, uid=0, gid=0)
		except OSError as e:
			if not os.path.isdir(dump_path):
				raise SystemExit("Failed to prepare DB dump path '{}'{}OS error: '{}'".format(e.filename, os.linesep, e.strerror))

	# BUILD DB LISTS
	# Initial entry is the submitty 'master' database
	# All other entries are submitty course databases
	db_list   = ['submitty']
	dump_list = ['{}_{}_submitty.dbdump'.format(today_stamp, semester)]

	for course in course_list[1:]:
		db_list.append('submitty_{}_{}'.format(semester, course))
		dump_list.append('{}_{}_{}.dbdump'.format(today_stamp, semester, course))

	# DUMP
	for i in range(len(course_list)):
		try:
			# pg_dump postgresql://user:password@host/dbname?sslmode=prefer > /var/local/submitty-dump/semester/course/dump_file.dbdump
			process = 'pg_dump postgresql://{}:{}@{}/{}?sslmode=prefer > {}/{}/{}/{}'.format(DB_USER, DB_PASS, DB_HOST, db_list[i], DUMP_PATH, semester, course_list[i], dump_list[i])
			return_code = subprocess.check_call(process, shell=True)
		except subprocess.CalledProcessError as e:
			print("Error while dumping {}".format(db_list[i]))
			print(e.output.decode('utf-8'))

	# DETERMINE EXPIRATION DATE (to delete obsolete dump files)
	# (do this BEFORE recursion so it is not calculated recursively n times)
	if expiration > 0:
		expiration_date = datetime.date.fromordinal(today.toordinal() - expiration)
		working_path = "{}/{}".format(DUMP_PATH, semester)

		# RECURSIVELY CULL OBSOLETE DUMPS
		delete_obsolete_dumps(working_path, monthly_retention, expiration_date)

if __name__ == "__main__":
	main()
