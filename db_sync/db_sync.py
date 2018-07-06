#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Submitty Database Sync Command Line Tool

Submitty's database structure works with a "master" database and separate
databases for each course.  When a user (instructor, grader, student) is added
or updated to a course, they are first added/updated in the "master" database,
and then an automatic trigger will execute to ensure the addition/update is also
applied to the appropriate course database.

Should data between the "master" database and a course database no longer match,
this tool will reconcile the differences under these rules:

* All user records are determined by the User ID field.
* User records in the "master" database have precedence over records in the
  course database.  "master" database records will overwrite inconsistencies
  found in a course database.
* Users recorded in the "master" database, but not existing in the course
  database will be copied to the course database.
* Users recorded in the course database, but not esixting in the "master"
  database will be copied over to the "master" database.
* This script requires "superuser" DB privilege.  Role 'hsdbu' is not a
  superuser.  Please consult with your sysadmin or DB admin for help.

**IMPORTANT**
* This tool only works with Postgresql databases.
* Requires the ``psycopg2`` python library.
* A "reverse sync" requires a super user role.  "Reverse sync" is when a course
  DB has user data that the "master" DB does not.  'hsdbu' is not supposed to
  be a superuser, but otherwise will work when a "reverse sync" is not needed.
"""

import datetime
import os
import psycopg2
import sys

# CONFIGURATION ----------------------------------------------------------------
DB_HOST = 'localhost'
DB_USER = 'hsdbu'  # NOTE: hsdbu won't work during a "reverse sync"
DB_PASS = 'hsdbu'  # Do NOT use this password in production
# ------------------------------------------------------------------------------

class db_sync:
	"""Sync user data in course databases with "master" database"""

	MASTER_DB_CONN = None
	"""psycopg2 connection resource for Submitty "master" DB"""
	MASTER_DB_CUR  = None
	"""psycopg2 "cursor" resource for Submitty "master" DB"""

	COURSE_DB_CONN = None
	"""psycopg2 connection resource for a Submitty course DB"""
	COURSE_DB_CUR  = None
	"""psycopg2 "cursor" resource for Submitty course DB"""

	SEMESTER = None
	"""current semester code (e.g. s18 = Spring 2018)"""

	def __init__(self):
		"""Auto start main process"""

		self.main()

	def __del__(self):
		"""Cleanup DB cursors and connections"""

		self.master_db_disconnect()
		self.course_db_disconnect()

# ------------------------------------------------------------------------------

	def main(self):
		"""Main Process"""

		if len(sys.argv) < 2 or sys.argv[1] == 'help':
			self.print_help()
			sys.exit(0)
		elif sys.argv[1] == 'all':
			# Get complete course list
			self.master_db_connect()
			course_list = self.get_all_courses()
		else:
			# Validate that courses exist
			self.master_db_connect()
			all_courses = self.get_all_courses()
			course_list = tuple(course for course in sys.argv[1:] if course in all_courses)
			invalid_course_list = tuple(course for course in sys.argv[1:] if course not in course_list)

			# Check that invalidated_course_list is not empty
			if invalid_course_list:
				# Get user permission to proceed
				# Clear console
				os.system('cls' if os.name == 'nt' else 'clear')
				print("The following courses are invalid:" + os.linesep + str(invalid_course_list)[1:-1] + os.linesep)
				# Check that course_list is empty.
				if not course_list:
					raise SystemExit("No valid courses specified.")

				print("Proceed syncing valid courses?" + os.linesep + str(course_list)[1:-1] + os.linesep)
				if input("Y/[N]:").lower() != 'y':
					print("exiting...")
					sys.exit(0)

		# Process database sync
		self.SEMESTER = self.determine_semester()
		for course in course_list:
			print ("Syncing {}".format(course))
			if not self.course_db_connect(course):
				print("Error connecting to course DB.")
				continue
			masterdb_users, coursedb_users = self.retrieve_all_users(course)
			common_users = tuple(user for user in coursedb_users if user in masterdb_users)
			masterdb_unique_users = tuple(user for user in masterdb_users if user not in coursedb_users)
			coursedb_unique_users = tuple(user for user in coursedb_users if user not in masterdb_users)
			# Greater than 5 discrepancies may indicate a larger data integrity problem.  Do not sync.
			if len(masterdb_unique_users) + len(coursedb_unique_users) > 5:
				print("There are greater than five users unique to either master or course DB." + os.linesep +
				      "There may be serious problems with the databases.  No sync will occur.")
				sys.exit(0)
			if common_users:
				if not self.reconcile_masterdb_coursedb(common_users):
					print("Error reconciling master and course DBs.")
					continue
			if masterdb_unique_users:
				if not self.forward_sync(masterdb_unique_users):
					print("Error syncing missing data from master DB to course DB.")
					continue
			if coursedb_unique_users:
				if not self.reverese_sync(course, coursedb_unique_users):
					print("Error syncing missing data from course DB to master DB.")
					continue

			self.course_db_disconnect()
			print ("Sync for course {} complete.".format(course))

# ------------------------------------------------------------------------------

	def master_db_connect(self):
		"""
		Establish connection to Submitty Master DB

		:raises SystemExit:  Master DB connection failed.
		"""

		try:
			self.MASTER_DB_CONN = psycopg2.connect("dbname=submitty user={} host={} password={}".format(DB_USER, DB_HOST, DB_PASS))
			self.MASTER_DB_CUR  = self.MASTER_DB_CONN.cursor()
		except Exception as e:
			raise SystemExit(str(e) + os.linesep + "ERROR: Cannot connect to Submitty master database")

# ------------------------------------------------------------------------------

	def master_db_disconnect(self):
		"""Close an open cursor connection to Submitty "master" DB"""

		if hasattr(self.MASTER_DB_CUR, 'closed') and self.MASTER_DB_CUR.closed == False:
			self.MASTER_DB_CUR.close()

		if hasattr(self.MASTER_DB_CONN, 'closed') and self.MASTER_DB_CONN.closed == 0:
			self.MASTER_DB_CONN.close()

# ------------------------------------------------------------------------------

	def course_db_connect(self, course):
		"""
		Establish connection to a Submitty course DB

		:param course:   course name
		:return:         flag indicating connection success or failure
		:rtype:          boolean
		"""

		db_name = "submitty_{}_{}".format(self.SEMESTER, course)

		try:
			self.COURSE_DB_CONN = psycopg2.connect("dbname={} user={} host={} password={}".format(db_name, DB_USER, DB_HOST, DB_PASS))
			self.COURSE_DB_CUR  = self.COURSE_DB_CONN.cursor()
		except Exception as e:
			print(str(e))
			return False

		return True

# ------------------------------------------------------------------------------

	def course_db_disconnect(self):
		"""Close an open cursor and connecton to a Submitty course DB"""

		if hasattr(self.COURSE_DB_CUR, 'closed') and self.COURSE_DB_CUR.closed == False:
			self.COURSE_DB_CUR.close()

		if hasattr(self.COURSE_DB_CONN, 'closed') and self.MASTER_DB_CONN.closed == 0:
			self.COURSE_DB_CONN.close()

# ------------------------------------------------------------------------------

	def get_all_courses(self):
		"""
		Retrieve active course list from Master DB

		:return: list of all active courses
		:rtype:  tuple (string)
		"""

		self.MASTER_DB_CUR.execute("SELECT course FROM courses WHERE semester='{}'".format(self.determine_semester()))
		return tuple(row[0] for row in self.MASTER_DB_CUR.fetchall())

# ------------------------------------------------------------------------------
	def retrieve_all_users(self, course):
		"""
		Retrieve all user IDs in both "master" and course databases

		:return: all user IDs in master database, all user IDs in course database
		:rtype:  tuple (string), tuple (string)
		"""

		self.MASTER_DB_CUR.execute("SELECT user_id FROM courses_users where course='{}' and semester='{}'".format(course, self.SEMESTER))
		masterdb_users = tuple(row[0] for row in self.MASTER_DB_CUR.fetchall())

		self.COURSE_DB_CUR.execute("SELECT user_id FROM users")
		coursedb_users = tuple(row[0] for row in self.COURSE_DB_CUR.fetchall())

		return masterdb_users, coursedb_users

# ------------------------------------------------------------------------------

	def reconcile_masterdb_coursedb(self, user_list):
		"""
		master DB user data overrides existing course DB user data

		:param user_list:  tuple of user ids to process
		:return:           flag indicating process success or failure
		:rtype:            boolean
		"""

		print ("Reconcile discrepencies between master DB and course DB")

		# Retrieve data from "master" DB
		# user_id is primary key (unique record identifier), so there should be only one row per query.
		for user_id in user_list:
			try:
				self.MASTER_DB_CUR.execute("SELECT user_firstname, user_preferred_firstname, user_lastname, user_email FROM users where user_id='{}'".format(user_id))
				row = list(self.MASTER_DB_CUR.fetchone())
				self.MASTER_DB_CUR.execute("SELECT user_group, registration_section, manual_registration from courses_users")
				row.extend(self.MASTER_DB_CUR.fetchone())
				self.COURSE_DB_CUR.execute("UPDATE users SET user_firstname='{}', user_preferred_firstname='{}', user_lastname='{}', user_email='{}', user_group='{}', registration_section='{}', manual_registration='{}' where user_id='{}'".format(*row, user_id))
			except Exception as e:
				print (str(e))
				return False

		return True

# ------------------------------------------------------------------------------

	def forward_sync(self, user_list):
		"""
		"Forward sync" of "master" DB users to course DB

		:param user_list:  tuple of user ids to process
		:return:           flag indicating process success or failure
		:rtype:            boolean
		"""


		print ("Forward Sync (master DB --> course DB)")

		for user_id in user_list:
			try:
				self.MASTER_DB_CUR.execute("SELECT user_firstname, user_preferred_firstname, user_lastname, user_email FROM users where user_id='{}'".format(user_id))
				row = list(self.MASTER_DB_CUR.fetchone())
				self.MASTER_DB_CUR.execute("SELECT user_group, registration_section, manual_registration from courses_users")
				row.extend(self.MASTER_DB_CUR.fetchone())
				self.COURSE_DB_CUR.execute("INSERT INTO users VALUES ('{}', NULL, '{}', '{}', '{}', '{}', {}, {}, {}, {})".format(user_id, *row))
			except Exception as e:
				print(str(e))
				return False

		return True

# ------------------------------------------------------------------------------

	def reverse_sync(self, course, user_list):
		"""
		"reverse sync" of course DB users to "master" DB
		Superuser role needed to disable triggers

		:param course:     course name
		:param user_list:  tuple of user ids to process
		:return:           flag indicating process success or failure
		:rtype:            boolean
		"""

		print ("Reverse Sync (master DB <-- course DB)")

		# Disables triggers for this session only.
		# This is where superuser role is needed.
		try:
			self.MASTER_DB_CUR.execute("SET session_replication_role = replica")
		except Exception as e:
			print (str(e))
			print ("HINT: Permission errors indicate that a superuser role is needed." + os.linesep +
			       "NOTE: For DB security, standard role 'hsdbu' is not supposed to be a superuser.")
			return False

		# Do "reverse" sync
		for user_id in user_list:
			try:
				self.COURSE_DB_CUR.execute("SELECT user_firstname, user_preferred_firstname, user_lastname, user_email, user_group, registration_section, manual_registration FROM users WHERE user_id='{}'".format(user_id))
				row = self.MASTER_DB_CUR.fetchone()
				self.MASTER_DB_CUR.execute("INSERT INTO users (user_id, user_firstname, user_preferred_firstname, user_lastname, user_email) VALUES ('{}','{}','{}','{}','{}')".format(user_id, *row[0:4]))
				self.MASTER_DB_CUR.execute("INSERT INTO courses_users (semester, course, user_id, user_group, registration_section, manual_registration) VALUES ('{}','{}','{}',{},{},{})".format(self.SEMESTER, course, user_id, *row[4:]))
			except Exception as e:
				print (str(e))
				return False

		return True

# ------------------------------------------------------------------------------

	def determine_semester(self):
		"""
		Build/return semester string.  e.g. "s17" for Spring 2017.

		:return: The semester string
		:rtype:  string
		"""

		today = datetime.date.today()
		month = today.month
		year  = str(today.year % 100)
		# if month <= 5: ... elif month >=8: ... else: ...
		return 's' + year if month <= 5 else ('f' + year if month >= 8 else 'm' + year)

# ------------------------------------------------------------------------------

	def print_help(self):
		"""Print help message to STDOUT/console"""

		# Clear console
		os.system('cls' if os.name == 'nt' else 'clear')
		print("Usage: db_sync.py (help | all | course...)\n");
		print("Command line tool to sync course databases with master submitty database.\n")
		print("help:   This help message")
		print("all:    Sync all course databases")
		print("course: Specific course or list of courses to sync\n")
		print("EXAMPLES:")
		print("db_sync.py all")
		print("Sync ALL courses with master submitty database.\n")
		print("db_sync.py csci1100")
		print("Sync course csci1100 with master submitty databse.\n")
		print("db_sync.py csci1200 csci2200 csci3200")
		print("Sync courses csci1200, csci2200, and csci3200 with master submitty database.\n")

# ------------------------------------------------------------------------------

if __name__ == "__main__":
	db_sync()
