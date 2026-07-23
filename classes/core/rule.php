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

namespace local_coursedynamicrules\core;

use context_system;
use local_coursedynamicrules\helper\rule_component_loader;
use stdClass;

/**
 * Class rule
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule {
    /** @var int ID of the rule on the DB */
    private $id;

    /** @var int 1 indicates that the rule is active, 0 indicates that the rule is inactive */
    private $active;

    /** @var int ID of the course */
    private $courseid;

    /** @var condition[] List of conditions instances */
    private $conditions = [];

    /** @var action[] List of actions instances */
    private $actions = [];

    /** @var stdClass[] List of users to validate this rule */
    private $users;

    /** @var array Additional data to add extra checks in conditions to avoid unexpected executions */
    private $additionaldata;

    /** @var string[] Condition types that triggered this evaluation (empty = scheduled/full evaluation) */
    private $conditiontypes;

    /**
     * Rule constructor.
     * @param object $rule
     * @param stdClass[] $users List of users to validate this rule
     * @param string[] $conditiontypes list of conditions to include in the executions
     * @param array $additionaldata additional data to add extra checks in conditions to avoid unexpected executions
     * of rules if not pass all conditions for each rule of the course are added
     */
    public function __construct($rule, $users, $conditiontypes = [], $additionaldata = []) {
        global $DB;
        $this->id = $rule->id;
        $this->courseid = $rule->courseid;
        $this->users = $users;
        $this->active = $rule->active;
        $this->additionaldata = $additionaldata;
        $this->conditiontypes = $conditiontypes;

        // Load conditions and actions from the DB. ALL conditions are loaded so the rule is always
        // evaluated as a full AND; $conditiontypes only records which trigger fired (see is_relevant_trigger).
        $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $this->id]);
        $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $this->id]);

        foreach ($conditions as $conditionrecord) {
            $this->conditions[] = rule_component_loader::create_condition_instance($conditionrecord, $this->courseid);
        }

        foreach ($actions as $actionrecord) {
            $this->actions[] = rule_component_loader::create_action_instance($actionrecord, $this->courseid);
        }
    }

    /**
     * Get conditions for this rule
     * @return condition[]
     */
    public function get_conditions() {
        return $this->conditions;
    }

    /**
     * Validate if all conditions of the rule are true
     * @param object $rulecontext Necesary information to evaluate the conditions of the rule
     * @return bool
     */
    public function evaluate_conditions($rulecontext) {
        if (empty($this->conditions)) {
            return false;
        }
        // Every condition must be satisfied (AND). Each condition evaluates its own current state;
        // trigger relevance is handled at rule level in is_relevant_trigger().
        foreach ($this->conditions as $condition) {
            if (!$condition->evaluate($rulecontext)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the trigger that caused this evaluation is relevant to the rule.
     *
     * Scheduled/full evaluations (no trigger types) are always relevant. Event evaluations are
     * relevant only when a condition of the triggering family targets the activity the event
     * was fired for; this prevents a rule from firing on unrelated events without forcing sibling
     * conditions to false (which would break the AND).
     *
     * @return bool
     */
    private function is_relevant_trigger() {
        if (empty($this->conditiontypes)) {
            return true;
        }

        $cmid = $this->get_cmid_from_additionaldata();
        if (!$cmid) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!in_array($condition->get_type(), $this->conditiontypes)) {
                continue;
            }
            $params = $condition->get_params();
            if (isset($params->cmid) && (int) $params->cmid === (int) $cmid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute all actions of the rule if the conditions are true
     */
    public function execute() {
        if (empty($this->conditions) || empty($this->actions)) {
            return;
        }

        // Only fire when the trigger concerns this rule (an event on a referenced activity, or a
        // scheduled task for a condition type the rule uses).
        if (!$this->is_relevant_trigger()) {
            return;
        }

        foreach ($this->users as $user) {
            $rulecontext = (object)[
                'courseid' => $this->courseid,
                'userid' => $user->id,
                'additionaldata' => $this->additionaldata,
            ];

            // Validate if all conditions of the rule are true for the user.
            if ($this->evaluate_conditions($rulecontext)) {
                // Execute all actions of the rule.
                $this->execute_actions($rulecontext);
            }
        }

        // After the rule is executed, set the last execution time.
        $this->set_last_execution_time(time());
    }

    /**
     * Execute all actions of the rule
     * @param object $rulecontext Necesary information to execute the actions of the rule
     */
    private function execute_actions($rulecontext) {
        foreach ($this->actions as $action) {
            $action->execute($rulecontext);
        }
    }

    /**
     * Set the active status of the rule
     * @param bool $active 1 indicates that the rule is active, 0 indicates that the rule is inactive
     */
    public function set_active($active) {
        global $DB;
        $this->active = $active ? 1 : 0;
        $DB->set_field('local_coursedynamicrules_rule', 'active', $this->active, ['id' => $this->id]);
    }

    /**
     * Sets the last execution time for the rule.
     *
     * This method updates the 'lastexecutiontime' field in the 'local_coursedynamicrules_rule' table
     *
     * @param int $time The timestamp of the last execution time.
     */
    public function set_last_execution_time($time) {
        global $DB;
        $DB->set_field('local_coursedynamicrules_rule', 'lastexecutiontime', $time, ['id' => $this->id]);

        foreach ($this->conditions as $condition) {
            $condition->set_last_execution_time($time);
        }

        foreach ($this->actions as $action) {
            $action->set_last_execution_time($time);
        }
    }

    /**
     * Retrieves the ID of the rule.
     *
     * @return int The ID of the rule.
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Deletes a rule record from the 'local_coursedynamicrules_rule' table. and related conditions and actions with it.
     *
     * @return bool True on success, false on failure.
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public function delete() {
        global $DB;

        foreach ($this->conditions as $condition) {
            $condition->delete();
        }

        foreach ($this->actions as $action) {
            $action->delete();
        }

        return $DB->delete_records('local_coursedynamicrules_rule', ['id' => $this->id]);
    }

    /**
     * Retrieves the course module ID (cmid) from the additional data.
     * Tries using completion ID first, then grade ID.
     *
     * @return int|null Course module ID if found, or null if not available.
     */
    private function get_cmid_from_additionaldata() {
        global $DB;
        if (isset($this->additionaldata['completionid'])) {
            return $this->get_cmid_from_completionid();
        }
        if (isset($this->additionaldata['gradeid'])) {
            return $this->get_cmid_from_gradeid();
        }
        return null;
    }

    /**
     * Retrieves the course module ID using the completion ID.
     *
     * @return int|false Course module ID if found, or false if not found.
     */
    private function get_cmid_from_completionid() {
        global $DB;
        return $DB->get_field('course_modules_completion', 'coursemoduleid', ['id' => $this->additionaldata['completionid']]);
    }

    /**
     * Retrieves the course module ID using the grade ID.
     * Joins grade_grades, grade_items, modules, and course_modules to find the related cmid.
     *
     * @return int|null Course module ID if found, or null if not found.
     */
    private function get_cmid_from_gradeid() {
        global $DB;
        $record = $DB->get_record_sql(
            "SELECT cm.id AS cmid
            FROM
                {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                JOIN {modules} m ON m.name = gi.itemmodule
                JOIN {course_modules} cm ON cm.module = m.id
                AND cm.instance = gi.iteminstance
            WHERE
                gg.id = :gradegradeid
                AND gi.itemtype = :itemtype",
            [
                'gradegradeid' => $this->additionaldata['gradeid'],
                'itemtype' => 'mod',
            ]
        );

        // The grade row may have been deleted between the event dispatch and this run; degrade to
        // null (handled as "not relevant") instead of dereferencing a false record.
        return $record ? $record->cmid : null;
    }
}
