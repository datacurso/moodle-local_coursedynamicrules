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

namespace local_coursedynamicrules\task;

use local_coursedynamicrules\core\rule;

/**
 * Class rule_task
 * This task is used to execute rules en foreground to avoid block main executions when rule data is
 * get from observer
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_task extends \core\task\adhoc_task {
    /**
     * Seconds a grade evaluation waits before running, so every grade item written by one
     * gradebook save is in place and folded into a single queued evaluation (MDL-UNIT-012).
     */
    public const COALESCE_DELAY = 30;

    /**
     * Return a instance of rule_task with custom data added
     *
     * @param object $customdata Custom data to pass to the task
     * @return rule_task
     */
    public static function instance($customdata): self {
        $task = new self();
        $task->set_custom_data($customdata);

        return $task;
    }

    /**
     * This function is execute when cron jobs are executed
     */
    public function execute() {
        global $DB;

        try {
            $customdata = $this->get_custom_data();

            $courseid = $customdata->courseid;
            $userid = $customdata->userid;
            $conditiontypes = $customdata->conditiontypes;

            // A deleted user is out: this task can be drained after the site deleted the user it
            // was queued for, and a rule must not act for them - the enable-activity action would
            // write back the very id the deletion just removed from its restrictions.
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if (!$user) {
                return;
            }

            // Make array to pass to rule class in second param.
            $users = [$user];

            // Get active rules for the course. A rule holding a one-shot condition (see
            // condition::is_one_shot()) is loaded too but never executed here: rule::execute()
            // leaves it to its scheduled pass.
            $rules = $DB->get_records('local_coursedynamicrules_rule', ['courseid' => $courseid, 'active' => 1]);

            $additionaldata = [];

            if (isset($customdata->completionid)) {
                $additionaldata['completionid'] = $customdata->completionid;
            }
            if (isset($customdata->gradeid)) {
                $additionaldata['gradeid'] = $customdata->gradeid;
            }
            // Grade evaluations are keyed by the activity (see observer\user_graded); tasks queued
            // before that change still carry a gradeid and resolve through it.
            if (isset($customdata->cmid)) {
                $additionaldata['cmid'] = $customdata->cmid;
            }

            foreach ($rules as $rule) {
                $ruleinstance = new rule($rule, $users, $conditiontypes, $additionaldata);
                $ruleinstance->execute();
            }
        } catch (\Exception $e) {
            mtrace($e);
        }
    }
}
