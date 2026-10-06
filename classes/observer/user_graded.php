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

            // One evaluation per (course, student, activity), not per grade row: saving the two grade
            // items of one activity together (a forum's rating and its whole-forum grade) fires one
            // event per item, and keying by the grade row queued two evaluations that each saw both
            // grades and notified twice (MDL-UNIT-012). With the activity as the key the second event
            // finds identical custom data still queued and adds nothing. The rule reads the current
            // grades when it runs, so nothing is lost; a regrade after that run queues a fresh one.
            // An item whose activity cannot be resolved keeps the old per-grade key.
            $customdata = [
                'courseid' => $courseid,
                'userid' => $userid,
            ];
            $cmid = self::resolve_cmid((int) $courseid, $grade->grade_item);
            if ($cmid) {
                $customdata['cmid'] = $cmid;
            } else {
                $customdata['gradeid'] = $grade->id;
            }
            $customdata['conditiontypes'] = self::$conditiontypes;
            $task = rule_task::instance((object) $customdata);

            // Delay the run by a short window. Without it cron could take the first evaluation
            // between the two writes of one save: the second event would then be dropped as a
            // duplicate (the running task's row stays in the queue) and the evaluation would have
            // seen only the first grade. queue_adhoc_task() compares custom data only, so the
            // duplicate check still applies to a delayed task.
            $clock = \core\di::get(\core\clock::class);
            $task->set_next_run_time($clock->time() + rule_task::COALESCE_DELAY);

            \core\task\manager::queue_adhoc_task($task, true);
        }
    }

    /**
     * Course module id of a module grade item, or null when it cannot be resolved.
     *
     * @param int $courseid Course id.
     * @param \grade_item $gradeitem Grade item of the event.
     * @return int|null
     */
    private static function resolve_cmid(int $courseid, $gradeitem): ?int {
        if (empty($gradeitem->itemmodule) || empty($gradeitem->iteminstance)) {
            return null;
        }
        $instances = get_fast_modinfo($courseid)->get_instances_of($gradeitem->itemmodule);
        $cm = $instances[(int) $gradeitem->iteminstance] ?? null;
        return $cm ? (int) $cm->id : null;
    }
}
