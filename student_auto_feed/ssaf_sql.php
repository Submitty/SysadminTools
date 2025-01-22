<?php
namespace ssaf;

/** SQL queries accessible as constant properties */
class sql {
    // Transaction queries
    public const LOCK_COURSES = "LOCK TABLE courses IN EXCLUSIVE MODE";
    public const LOCK_REG_SECTIONS = "LOCK TABLE courses_registration_sections IN EXCLUSIVE MODE";
    public const LOCK_COURSES_USERS = "LOCK TABLE courses_users IN EXCLUSIVE MODE";
    public const LOCK_SAML_MAPPED_USERS = "LOCK TABLE saml_mapped_users IN EXCLUSIVE MODE";
    public const BEGIN = "BEGIN";
    public const COMMIT = "COMMIT";
    public const ROLLBACK = "ROLLBACK";

    // Registration type constants
    public const RT_GRADED = "graded";
    public const RT_AUDIT = "audit";
    public const RT_LATEDROP = "withdrawn";

    // SELECT queries
    public const GET_COURSES = <<<SQL
    SELECT course
    FROM courses
    WHERE term=$1
    AND status=1
    SQL;

    public const GET_MAPPED_COURSES = <<<SQL
    SELECT course, registration_section, mapped_course, mapped_section
    FROM mapped_courses
    WHERE term=$1
    SQL;

    public const GET_COURSE_ENROLLMENT_COUNT = <<<SQL
    SELECT count(*) AS num_students FROM courses_users
    WHERE term=$1
    AND course=$2
    AND user_group=4
    AND registration_section IS NOT NULL
    SQL;

    // UPSERT users table
    // Do not remove SQL comment as it is needed for preferred name log tracking
    public const UPSERT_USERS = <<<SQL
    INSERT INTO users (
        user_id,
        user_numeric_id,
        user_givenname,
        user_familyname,
        NULLIF(user_preferred_givenname, ''),
        user_email
    ) VALUES ($1, $2, $3, $4, $5, $6)
    ON CONFLICT (user_id) DO UPDATE
    SET user_numeric_id=EXCLUDED.user_numeric_id,
        user_givenname=EXCLUDED.user_givenname,
        user_familyname=EXCLUDED.user_familyname,
        user_preferred_givenname=
            CASE WHEN users.user_updated=FALSE
                AND users.instructor_updated=FALSE
                AND COALESCE(users.user_preferred_givenname, '')=''
            THEN EXCLUDED.user_preferred_givenname
            ELSE users.user_preferred_givenname
            END,
        user_email=
            CASE WHEN COALESCE(EXCLUDED.user_email, '')<>''
            THEN EXCLUDED.user_email
            ELSE users.user_email
            END
    /* AUTH: "AUTO_FEED" */
    SQL;

    // UPSERT courses_users table
    public const UPSERT_COURSES_USERS = <<<SQL
    INSERT INTO courses_users (
        term,
        course,
        user_id,
        user_group,
        registration_section,
        registration_type,
        manual_registration
    ) VALUES ($1, $2, $3, $4, $5, $6, $7)
    ON CONFLICT (term, course, user_id) DO UPDATE
    SET registration_section=
            CASE WHEN courses_users.user_group=4
                AND courses_users.manual_registration=FALSE
            THEN EXCLUDED.registration_section
            ELSE courses_users.registration_section
            END,
        registration_type=
            CASE WHEN courses_users.user_group=4
                AND courses_users.manual_registration=FALSE
            THEN EXCLUDED.registration_type
            ELSE courses_users.registration_type
            END
    SQL;

    // INSERT courses_registration_sections table
    public const INSERT_REG_SECTION = <<<SQL
    INSERT INTO courses_registration_sections (
        term,
        course,
        registration_section_id,
        course_section_id
    ) VALUES ($1, $2, $3, $4)
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
        AND courses_users.term=$1
        AND courses_users.course=$2
        AND courses_users.user_group=4
    ) AS dropped
    WHERE courses_users.user_id=dropped.user_id
    AND courses_users.term=$1
    AND courses_users.course=$2
    AND courses_users.user_group=4
    AND courses_users.manual_registration=FALSE
    SQL;

    public const INSERT_SAML_MAP = <<<SQL
    INSERT INTO saml_mapped_users (
        saml_id,
        user_id
    ) SELECT tmp.user_id, tmp.user_id
    FROM tmp_enrolled tmp
    LEFT OUTER JOIN saml_mapped_users saml1 ON tmp.user_id = saml1.user_id
    LEFT OUTER JOIN saml_mapped_users saml2 ON tmp.user_id = saml2.saml_id
    WHERE saml1.user_id IS NULL
    AND saml2.saml_id IS NULL
    SQL;
}

//EOF
?>
