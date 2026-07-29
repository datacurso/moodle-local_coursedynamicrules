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
 * Class no_course_access_task
 * This task is responsible for executing the rules with the condition no_course_access.
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class no_course_access_task extends \core\task\scheduled_task {
    /** @var string type of condition */
    protected $conditiontype = "no_course_access";

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('no_course_access_task', 'local_coursedynamicrules');
    }

    /**
     * Execute the task.
     *
     */
    public function execute() {
        global $DB;

        $conditiontype = $this->conditiontype;

        // Retrieve all active rules with the specified condition type.
        $rules = $DB->get_records_sql(
            "SELECT DISTINCT r.*
            FROM
                {local_coursedynamicrules_rule} r
                JOIN {local_coursedynamicrules_condition} c ON c.ruleid = r.id
            WHERE
                c.conditiontype = :conditiontype
                AND r.active = 1",
            ['conditiontype' => $conditiontype]
        );

        // Iterate through each rule and execute if conditions are met.
        foreach ($rules as $rule) {
            // Deduplicated, active-only enrolled users (excludes suspended and deleted users, and
            // collapses multiple enrolments of the same user so actions run once per user).
            $users = get_enrolled_users(\context_course::instance($rule->courseid), '', 0, 'u.*', null, 0, 0, true);
            $ruleinstance = new rule($rule, $users);
            if ($this->could_be_execute_rule($ruleinstance)) {
                $ruleinstance->execute();
                $this->set_time_period($ruleinstance);
            }
        }
    }

    /**
     * Check if the rule can be executed based on its conditions.
     *
     * @param rule $ruleinstance The rule instance to check.
     * @return bool True if the rule can be executed, false otherwise.
     */
    private function could_be_execute_rule($ruleinstance) {
        $conditions = $ruleinstance->get_conditions();
        foreach ($conditions as $condition) {
            $params = $condition->get_params();

            $now = time();
            // Check if the condition type matches and the current time is before the next time period.
            if ($condition->get_type() == $this->conditiontype && $now < $params->nexttimeperiod) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set the next time period for the rule conditions.
     *
     * @param rule $ruleinstance The rule instance to update.
     */
    private function set_time_period($ruleinstance) {
        global $DB;
        $conditions = $ruleinstance->get_conditions();
        foreach ($conditions as $condition) {
            $params = $condition->get_params();
            $conditionid = $condition->get_id();
            $now = time();

            // Check if the condition type matches and the current time is after the next time period.
            if ($condition->get_type() == $this->conditiontype && $now >= $params->nexttimeperiod) {
                $params->nexttimeperiod = strtotime("+$params->periodvalue $params->periodunit", $now);
                $DB->set_field('local_coursedynamicrules_condition', 'params', json_encode($params), ['id' => $conditionid]);
            }
        }
    }
}
