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
use local_coursedynamicrules\helper\enrolled_users;
use local_coursedynamicrules\helper\task_batch;

/**
 * Class no_complete_activity_task
 * This task is responsible for executing the rules with the condition no_complete_activity.
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class no_complete_activity_task extends \core\task\scheduled_task {
    /** @var string type of condition */
    protected $conditiontype = "no_complete_activity";

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('no_complete_activity_task', 'local_coursedynamicrules');
    }

    /**
     * Execute the task.
     *
     */
    public function execute() {
        global $DB;

        $starttime = microtime(true);
        $batchsize = task_batch::size();

        $rules = $DB->get_records_sql(
            "SELECT DISTINCT r.*
            FROM
                {local_coursedynamicrules_rule} r
                JOIN {local_coursedynamicrules_condition} c ON c.ruleid = r.id
            WHERE
                c.conditiontype = :conditiontype
                AND r.active = 1",
            ['conditiontype' => $this->conditiontype]
        );

        $executed = 0;
        $totalusers = 0;

        foreach ($rules as $rule) {
            // Active-only enrolled users (excludes suspended and deleted users, one row per user however
            // many enrolments they hold), walked in pages of $batchsize ids: the rule engine reads
            // nothing but the id, and a course is never held in memory whole. Nothing is queried
            // until the rule is really executed; the walk itself is live (see helper\enrolled_users).
            $context = \context_course::instance($rule->courseid);
            $users = enrolled_users::ids($context, $batchsize);

            $ruleinstance = new rule($rule, $users);
            $conditions = $ruleinstance->get_conditions();

            if ($this->is_time_to_execute_rule($ruleinstance) && !empty($conditions)) {
                // Counted only for a rule that runs, for the report and the threshold notice.
                $usercount = count_enrolled_users($context, '', 0, true);
                $totalusers += $usercount;
                if ($usercount > $batchsize) {
                    mtrace("local_coursedynamicrules: course {$rule->courseid} has {$usercount} enrolled users "
                        . "(over batch threshold {$batchsize}) while evaluating rule {$rule->id}.");
                }
                $ruleinstance->execute();
                $ruleinstance->set_active(false);
                $executed++;
            }
        }

        if (!empty($rules)) {
            mtrace(sprintf(
                'local_coursedynamicrules: %s evaluated %d active rules and %d users, executed %d, in %.2fs.',
                $this->conditiontype,
                count($rules),
                $totalusers,
                $executed,
                microtime(true) - $starttime
            ));
        }
    }

    /**
     * Validate if rule could be executed.
     * @param rule $rule
     */
    private function is_time_to_execute_rule($rule) {
        $conditions = $rule->get_conditions();

        foreach ($conditions as $condition) {
            $now = time();

            $params = $condition->get_params();
            $expectedcompletiondate = $params->expectedcompletiondate;

            if ($condition->get_type() == $this->conditiontype && $now < $expectedcompletiondate) {
                return false;
            }
        }

        return true;
    }
}
