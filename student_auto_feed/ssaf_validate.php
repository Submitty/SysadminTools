<?php
/* -----------------------------------------------------------------------------
Q: What is going on with the regex for email validation in
   validate::validate_row()?
A: The regex is intending to match an email address as
   1. An empty string.  Obviously not a real email address, but we are allowing
      enrollments in the database with "inactive" or "pending" email addresses.
   -- OR --
   1. Address recipient may not contain characters (),:;<>@\"[]
   2. Address recipient may not start or end with characters !#$%'*+-/=?^_`{|
   3. Address recipient and hostname must be delimited with @ character
   4. Address hostname labels are delimited by the . character
   5. Address hostname labels may contain alphanumeric and - character
   6. Address hostname labels may not start or end with - character
   7. Address top level domain may be alphabetic only and minimum length of 2
   8. The entire email address is case insensitive

   Peter Bailie, Oct 29 2021
   Last Updated May 12, 2022 by Peter Bailie
----------------------------------------------------------------------------- */

namespace ssaf;

/** @author Peter Bailie, Rensselaer Polytechnic Institute */
class validate {

    /** @var null|string $error Holds any generated error messages */
    public static $error = null;

    /**
     * Validate $row is suitable for processing.
     *
     * trim() to remove whitespace is applied to all columns.  trim() to remove
     * trailing zeroes is applied to the registration section column, when
     * column is all numeric characters.
     *
     * @param array $row Data row to validate.
     * @param int $row_num Data row number from CSV file (for error messaging).
     * @return bool TRUE when $row is successfully validated, FALSE otherwise.
     */
    public static function validate_row($row, $row_num) : bool {
        self::$error = null;
        $validate_num_fields = VALIDATE_NUM_FIELDS;
        $num_fields = count($row);

        switch(false) {
        // Make sure $row has the expected number of fields.
        case $num_fields === $validate_num_fields:
            self::$error = "Row {$row_num} has {$num_fields} columns.  {$validate_num_fields} expected.";
            return false;
        // User ID must contain only alpha characters, numbers, underscore, hyphen, and period.
        case boolval(preg_match("/^[a-z0-9_\-\.]+$/i", $row[COLUMN_USER_ID])):
            self::$error = "Row {$row_num} failed user ID validation \"{$row[COLUMN_USER_ID]}\".";
            return false;
        // First name must be alpha characters, white-space, or certain punctuation.
        case boolval(preg_match("/^[a-z'`\-\. ]+$/i", $row[COLUMN_FIRSTNAME])):
            self::$error = "Row {$row_num} failed validation for student first name \"{$row[COLUMN_FIRSTNAME]}\".";
            return false;
        // Last name must be alpha characters, white-space, or certain punctuation.
        case boolval(preg_match("/^[a-z'`\-\. ]+$/i", $row[COLUMN_LASTNAME])):
            self::$error = "Row {$row_num} failed validation for student last name \"{$row[COLUMN_LASTNAME]}\".";
            return false;
        // Student registration section must be alphanumeric, '_', or '-'.
        case boolval(preg_match("/^[a-z0-9_\-]+$/i", $row[COLUMN_SECTION])):
            self::$error = "Row {$row_num} failed validation for student section \"{$row[COLUMN_SECTION]}\".";
            return false;
        // Check email address is properly formed.  Blank email addresses are also accepted.
        case boolval(preg_match("/^$|^(?![!#$%'*+\-\/=?^_`{|])[^(),:;<>@\\\"\[\]]+(?<![!#$%'*+\-\/=?^_`{|])@(?:(?!\-)[a-z0-9\-]+(?<!\-)\.)+[a-z]{2,}$/i", $row[COLUMN_EMAIL])):
            self::$error = "Row {$row_num} failed validation for student email \"{$row[COLUMN_EMAIL]}\".";
            return false;
        }

        // Successfully validated.
        return true;
    }

    /**
     * Check $rows for duplicate user IDs.
     *
     * Submitty's master DB does not permit students to register more than once
     * for any course.  It would trigger a key violation exception.  This
     * function checks for data anomalies where a student shows up in a course
     * more than once as that is indicative of an issue with CSV file data.
     * Returns TRUE, as in no error, when $rows has all unique user IDs.
     * False, as in error found, otherwise.  $user_ids is filled when return
     * is FALSE.
     *
     * @param array $rows Data rows to check (presumably an entire couse).
     * @param string[] &$user_id Duplicated user ID, when found.
     * @param string[] &$d_rows Rows containing duplicate user IDs, indexed by user ID.
     * @return bool TRUE when all user IDs are unique, FALSE otherwise.
     */
    public static function check_for_duplicate_user_ids(array $rows, &$user_ids, &$d_rows) : bool {
        usort($rows, function($a, $b) { return $a[COLUMN_USER_ID] <=> $b[COLUMN_USER_ID]; });

        $user_ids = [];
        $d_rows = [];
        $are_all_unique = true;  // Unless proven FALSE
        $length = count($rows);
        for ($i = 1; $i < $length; $i++) {
            $j = $i - 1;
            if ($rows[$i][COLUMN_USER_ID] === $rows[$j][COLUMN_USER_ID]) {
                $are_all_unique = false;
                $user_id = $rows[$i][COLUMN_USER_ID];
                $user_ids[] = $user_id;
                $d_rows[$user_id][] = $j;
                $d_rows[$user_id][] = $i;
            }
        }

        foreach($d_rows as &$d_row) {
            array_unique($d_row, SORT_REGULAR);
        }
        unset($d_row);

        return $are_all_unique;
    }

    /**
     * Validate that there isn't an excessive drop ratio in course enrollments.
     *
     * An excessive ratio of dropped enrollments may indicate a problem with
     * the data sheet.  Dropped enrollments can be either indicated by a row
     * with a unacceptable registration code, or more critically THAT USER'S
     * DATA ROW WAS OMITTED FROM THE DATASHEET.  In the latter case, it is
     * difficult to tell when missing data is regular or improper.  Therefore
     * this check relies on the config setting VALIDATE_DROP_RATIO as a
     * confidence setting to indicate that processing must be aborted to
     * (possibly? probably?) preserve data integrity.  Returns TRUE when
     * validation is OK.  That is, ratio of dropped students is within
     * confidence.  Or the ratio did not go beyond the cutoff.
     *
     * @param array $rows Data rows for one course
     * @param string $term Current term code.  e.g. 'f21' for Fall 2021.
     * @param string $course Course code for course's data.
     * @return bool TRUE when validation OK, FALSE when validation failed.
     */
    public static function check_for_excessive_dropped_users(array $rows, string $term, string $course, &$diff, &$ratio) : bool {
        // This check is disabled when VALIDATE_DROP_RATIO is set NULL.
        if (is_null(VALIDATE_DROP_RATIO)) return true;

        $ratio_cutoff = VALIDATE_DROP_RATIO * -1;
        $current_enrollments = (int) db::get_enrollment_count($term, $course);
        $new_enrollments = count($rows);

        /* ---------------------------------------------------------------------
           Dropped students shows a reduction in enrollment, and therefore the
           difference will be a negative value to calculate the ratio, resulting
           in a negative ratio.  A calculated ratio that is *smaller* or equals
           the cutoff fails validation.  A positive ratio indicates students
           adding the course, in which case validation is OK.

           If $current_enrollments are 0, the course is empty of students and
           there can be no dropped students.  Also, division by 0.
           Only possible response is TRUE (validate OK), so set to 1.0 to ensure
           ratio is always higher than the cutoff.
        --------------------------------------------------------------------- */
        $diff = $new_enrollments - $current_enrollments;
        $ratio = $current_enrollments !== 0 ? $diff / $current_enrollments : 1.0;
        return $ratio > $ratio_cutoff;
    }
}

// EOF
?>
