<?php
namespace ssaf;

/** SQL queries accessible as constant properties */
class sql {
    // Transaction queries
    public const LOCK_COURSES = "LOCK TABLE courses IN EXCLUSIVE MODE";
    public const LOCK_REG_SECTIONS = "LOCK TABLE courses_registration_sections IN EXCLUSIVE MODE";
    public const LOCK_COURSES_USERS = "LOCK TABLE courses_users IN EXCLUSIVE MODE";
    public const BEGIN = "BEGIN";
    public const COMMIT = "COMMIT";
    public const ROLLBACK = "ROLLBACK";

    // SELECT queries
    public const GET_COURSES = <<<SQL
SELECT course
FROM courses
WHERE semester=$1
AND status=1
SQL;

    public const GET_MAPPED_COURSES = <<<SQL
SELECT course, registration_section, mapped_course, mapped_section
FROM mapped_courses
WHERE semester=$1
SQL;

    // UPSERT users table
    // Do not remove SQL comment as it is needed for preferred name log tracking
    public const UPSERT_USERS = <<<SQL
INSERT INTO users (
    user_id,
    user_numeric_id,
    user_firstname,
    user_lastname,
    user_preferred_firstname,
    user_email
) VALUES ($1, $2, $3, $4, $5, $6)
ON CONFLICT (user_id) DO UPDATE
SET user_numeric_id=EXCLUDED.user_numeric_id,
    user_firstname=EXCLUDED.user_firstname,
    user_lastname=EXCLUDED.user_lastname,
    user_preferred_firstname=
        CASE WHEN users.user_updated=FALSE
            AND users.instructor_updated=FALSE
            AND COALESCE(users.user_preferred_firstname, '')=''
        THEN EXCLUDED.user_preferred_firstname
        ELSE users.user_preferred_firstname
        END,
    user_email=EXCLUDED.user_email
/* AUTH: "AUTO_FEED" */
SQL;

    // UPSERT courses_users table
    public const UPSERT_COURSES_USERS = <<<SQL
INSERT INTO courses_users (
    semester,
    course,
    user_id,
    user_group,
    registration_section,
    manual_registration
) VALUES ($1, $2, $3, $4, $5, $6)
ON CONFLICT (semester, course, user_id) DO UPDATE
SET registration_section=
    CASE WHEN courses_users.user_group=4
        AND courses_users.manual_registration=FALSE
    THEN EXCLUDED.registration_section
    ELSE courses_users.registration_section
    END
SQL;

    // INSERT courses_registration_sections table
    public const INSERT_REG_SECTION = <<<SQL
INSERT INTO courses_registration_sections (
    semester,
    course,
    registration_section_id
) VALUES ($1, $2, $3)
ON CONFLICT DO NOTHING
SQL;

    /* -------------------------------------------------------------------------
       DROPPED USERS queries
       We store a list of enrolled students (by user ID) from the data sheet to
       the tmp table, and then compare that list with those in the database's
       user table.  Anyone not in the tmp table wasn't in the datasheet and
       therefore assumed to no longer be enrolled.  Unenrolled (dropped)
       students are moved to the NULL section, which signifies they have
       dropped the course.
    ------------------------------------------------------------------------- */
    public const CREATE_TMP_TABLE = <<<SQL
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_enrolled (
    user_id VARCHAR
) ON COMMIT DELETE ROWS
SQL;

    public const INSERT_TMP_TABLE = <<<SQL
INSERT INTO tmp_enrolled
VALUES ($1);
SQL;

    public const DROPPED_USERS = <<<SQL
UPDATE courses_users
SET registration_section=NULL
FROM (
    SELECT courses_users.user_id
    FROM courses_users
    LEFT OUTER JOIN tmp_enrolled
    ON courses_users.user_id=tmp_enrolled.user_id
    WHERE tmp_enrolled.user_id IS NULL
    AND courses_users.semester=$1
    AND courses_users.course=$2
    AND courses_users.user_group=4
) AS dropped
WHERE courses_users.user_id=dropped.user_id
AND courses_users.semester=$1
AND courses_users.course=$2
AND courses_users.user_group=4
AND courses_users.manual_registration=FALSE
SQL;
}

//EOF
?>
