<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursedynamicrules\observer;

use local_coursedynamicrules\task\rule_task;

/**
 * Class user_graded
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_graded {
    /** @var array $conditions list of conditions to include in the executions for this event observer */
    private static $conditiontypes = [
        'grade_in_activity',
        'passgrade',
        // When modules with manual grading are completed e.g assignments
        // the \core\event\course_module_completion_updated is not triggered.
        'complete_activity',
    ];

    /**
     * Trigger when user receive grade
     * @param \core\event\user_graded $event
     */
    public static function observe(\core\event\user_graded $event) {
        $eventdata = $event->get_data();

        $grade = $event->get_grade();
        $gradeitemtype = $grade->grade_item->itemtype;
        // This validation is because this event is also triggered with the course grade.
        if ($gradeitemtype == 'mod') {
            $courseid = $eventdata["courseid"];
            // User that completed the module.
            $userid = $eventdata["relateduserid"];

            // Nothing of this plugin can act on a course that holds no rule, and queueing an
            // evaluation for one costs a slot in a queue every other plugin shares. See
            // rule::course_has_any_rule() for why this does not ask whether the rule is active.
            if (!\local_coursedynamicrules\core\rule::course_has_any_rule((int) $courseid)) {
                return;
            }

            // Create an instance of the custom adhoc task with required data, including grade ID.
            // The grade ID is used to ensure the uniqueness of the task based on the specific grade_grade record.
            $task = rule_task::instance((object)[
                'gradeid' => $grade->id,
                'courseid' => $courseid,
                'userid' => $userid,
                'conditiontypes' => self::$conditiontypes,
            ]);

            // Queue the adhoc task for execution. The second parameter 'true' ensures that only one
            // unique task is queued for the given grade ID, preventing duplicate executions.
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }
}
