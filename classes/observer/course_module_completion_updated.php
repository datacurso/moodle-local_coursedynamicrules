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
 * Class course_module_completion_updated
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_completion_updated {
    /** @var array $conditions list of conditions to include in the executions for this event observer */
    private static $conditiontypes = [
        'complete_activity',
    ];
    /**
     * The definition of the event.
     *
     * @param \core\event\course_module_completion_updated $event The event object
     */
    public static function observe(\core\event\course_module_completion_updated $event) {
        global $DB;
        $eventdata = $event->get_data();

        $completion = $event->get_record_snapshot('course_modules_completion', $eventdata["objectid"]);

        // When completiongrade or passgrade is set, it means that the completion is based on grade.
        // In this case, we don't need to execute the rules in this observer.
        // This is to avoid executing the same rules twice.
        if (isset($completion->completiongrade) || isset($completion->passgrade)) {
            return;
        }

        // If the completion state is incomplete, it means that the user has not completed the module.
        // This is when a module has more than one completion state
        // e.g for assignments with completion set to View and make submission.
        if ($completion->completionstate == COMPLETION_INCOMPLETE) {
            return;
        }

        $courseid = $eventdata["courseid"];

        // User that completed the module.
        $userid = $eventdata["relateduserid"];

        // Nothing of this plugin can act on a course that holds no rule, and queueing an
        // evaluation for one costs a slot in a queue every other plugin shares. See
        // rule::course_has_any_rule() for why this does not ask whether the rule is active.
        if (!\local_coursedynamicrules\core\rule::course_has_any_rule((int) $courseid)) {
            return;
        }

        // Create an instance of the custom adhoc task with required data, including completion ID.
        // The completion ID is used to ensure the uniqueness of the task based on the specific completion record.
        $task = rule_task::instance((object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'conditiontypes' => self::$conditiontypes,
            'completionid' => $completion->id,
        ]);

        // Queue the adhoc task for execution. The second parameter 'true' ensures that only one
        // unique task is queued for the given completion ID, preventing duplicate executions.
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
