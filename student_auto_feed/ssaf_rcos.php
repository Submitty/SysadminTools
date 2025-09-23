<?php
namespace ssaf;

/**
 * Static utilty class to support RCOS (Rensselaer Center for Open Source)
 *
 * @author Peter Bailie
 */
class rcos {
    private array $course_list;

    public function __construct() {
        $this->course_list = RCOS_COURSE_LIST ?? [];
        array_walk($this->course_list, function(&$v, $i) { $v = strtolower($v); });
    }

    /** Adjusts `$row[COLUMN_SECTION]` when `$course` is an RCOS course. */
    public function map(string $course, array &$row): void {
        if ($this->check($course)) {
            $row[COLUMN_SECTION] = "{$row[COLUMN_COURSE_NUMBER]}-{$row[COLUMN_CREDITS]}";
        }
    }

    /** Returns `true` if `$course` is an RCOS course and `false` otherwise. */
    public function check(string $course): bool {
        return array_search($course, self::$course_list) !== false;
    }
}
