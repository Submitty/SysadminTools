<?php
/* -----------------------------------------------------------------------------
Q: What is going on with the regex for email validation in
   validate::validate_row()?
A: The regex is intending to match an email address as
   1. Address recipient may not contain characters (),:;<>@\"[]
   2. Address recipient may not start or end with characters !#$%'*+-/=?^_`{|
   3. Address recipient and hostname must be delimited with @ character
   4. Address hostname labels are delimited by the . character
   5. Address hostname labels may contain alphanumeric and - character
   6. Address hostname labels may not start or end with - character
   7. Address top level domain may be alphabetic only and minimum length of 2
   8. The entire email address is case insensitive

   Peter Bailie, Oct 29 2021
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
        // Check term code (skips when set to null).
        case is_null(EXPECTED_TERM_CODE) ? true : $row[COLUMN_TERM_CODE] === EXPECTED_TERM_CODE:
            self::$error = "Row {$row_num} failed validation for unexpected term code \"{$row[COLUMN_TERM_CODE]}\".";
            return false;
        // User ID must contain only lowercase alpha, numbers, underscore, and hyphen
        case boolval(preg_match("/^[a-z0-9_\-]+$/", $row[COLUMN_USER_ID])):
            self::$error = "Row {$row_num} failed user ID validation \"{$row[COLUMN_USER_ID]}\".";
            return false;
        // First name must be alpha characters, white-space, or certain punctuation.
        case boolval(preg_match("/^[a-zA-Z'`\-\. ]+$/", $row[COLUMN_FIRSTNAME])):
            self::$error = "Row {$row_num} failed validation for student first name \"{$row[COLUMN_FIRSTNAME]}\".";
            return false;
        // Last name must be alpha characters, white-space, or certain punctuation.
        case boolval(preg_match("/^[a-zA-Z'`\-\. ]+$/", $row[COLUMN_LASTNAME])):
            self::$error = "Row {$row_num} failed validation for student last name \"{$row[COLUMN_LASTNAME]}\".";
            return false;
        // Student registration section must be alphanumeric, '_', or '-'.
        case boolval(preg_match("/^[a-zA-Z0-9_\-]+$/", $row[COLUMN_SECTION])):
            self::$error = "Row {$row_num} failed validation for student section \"{$row[COLUMN_SECTION]}\".";
            return false;
        // Check email address is properly formed.
        case boolval(preg_match("/^(?![!#$%'*+\-\/=?^_`{|])[^(),:;<>@\\\"\[\]]+(?<![!#$%'*+\-\/=?^_`{|])@(?:(?!\-)[a-z0-9\-]+(?<!\-)\.)+[a-z]{2,}$/i", $row[COLUMN_EMAIL])):
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
     * @param array $rows Data rows to check (presumably an entire couse)
     * @param string[] &$user_id Duplicated user ID, when found
     * @return bool TRUE when all user IDs are unique, FALSE otherwise.
     */
    public static function check_for_duplicate_user_ids(array $rows, &$user_ids) : bool {
        usort($rows, function($a, $b) { return $a[COLUMN_USER_ID] <=> $b[COLUMN_USER_ID]; });

        $user_ids = array();
        $are_all_unique = true;  // Unless proven FALSE
        $length = count($rows);
        for ($i = 1; $i < $length; $i++) {
            $j = $i - 1;
            if ($rows[$i][COLUMN_USER_ID] === $rows[$j][COLUMN_USER_ID]) {
                $are_all_unique = false;
                $user_ids[] = $rows[$i][COLUMN_USER_ID];
            }
        }

        return $are_all_unique;
    }
}
// EOF
?>
