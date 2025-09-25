<?php
namespace ssaf;

/**
 * Static utilty class to support RCOS (Rensselaer Center for Open Source)
 *
 * This will override enrollment registration sections for RCOS courses to `{course}-{credits}`  e.g. `CSCI4700-4`.
 * Some RCOS students may declare how many credits they are registering for, so normal course mapping in the database
 * is insufficient.  This must done while processing the CSV because every override requires each student's registered
 * credits from the CSV.
 *
 * @author Peter Bailie
 */
class rcos {
    private array $course_list;

    public function __construct() {
        $this->course_list = RCOS_COURSE_LIST ?? [];
        array_walk($this->course_list, function(&$v, $i) { $v = strtolower($v); });
        sort($this->course_list, SORT_STRING);
    }

    /** Adjusts `$row[COLUMN_SECTION]` when `$course` is an RCOS course. */
    public function map(string $course, array &$row): void {
        if (in_array($course, $this->course_list, true)) {
            $course = strtoupper($course);
            $row[COLUMN_SECTION] = "{$course}-{$row[COLUMN_CREDITS]}";
        }
    }
}
