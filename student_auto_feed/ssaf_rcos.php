<?php
namespace ssaf;

/**
 * Static utilty class to support RCOS (Rensselaer Center for Open Source)
 *
 * This will override enrollment registration sections for RCOS courses.  Some RCOS students may declare how many
 * credits they are registered for, but are otherwise all placed in the same course and registration section.
 * (currently) Submitty does not keep records for regsitered credits per student, but this record is needed for
 * RCOS.  Therefore, this helper class will override a RCOS student's enrollment record to `{course}-{credits}`
 * e.g. `csci4700-4`.  This must done while processing the CSV because every override requires the student's
 * registered credits from the CSV.
 *
 * @author Peter Bailie
 */
class rcos {
    private array $course_list;

    public function __construct() {
        $this->course_list = RCOS_COURSE_LIST ?? [];
        sort($this->course_list, SORT_STRING);
        array_walk($this->course_list, function(&$v, $i) { $v = strtolower($v); });
    }

    /** Adjusts `$row[COLUMN_SECTION]` when `$course` is an RCOS course. */
    public function map(string $course, array &$row): void {
        if (in_array($course, $this->course_list, true)) {
            $row[COLUMN_SECTION] = "{$course}-{$row[COLUMN_CREDITS]}";
        }
    }
}
