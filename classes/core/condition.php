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

use local_coursedynamicrules\form\conditions\condition_form;
use local_coursedynamicrules\helper\ownership;
use stdClass;

/**
 * Class condition
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class condition {
    /** @var string DB table storing condition rows. */
    const TABLE = 'local_coursedynamicrules_condition';

    /** @var int ID of the condition on the DB */
    private $id;

    /** @var string Type of the action example: sendnotification */
    protected $type;

    /** @var object Parameters of the action stored in DB */
    protected $params;

    /** @var condition_form|null */
    protected $conditionform;

    /** @var int course id */
    protected $courseid;

    /** @var int rule id */
    protected $ruleid;

    /** @var int|null $lastexecutiontime Indicate time of last finished execution */
    protected $lastexecutiontime;

    /**
     * Action constructor.
     * @param object $record Record of the condition stored in DB
     * @param int $courseid the course id where the action is applied.
     */
    public function __construct($record, $courseid = null) {
        $this->set_data($record, $courseid);
    }

    /**
     * Return type of this condition
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Return last execution time of this condition
     */
    public function get_last_execution_time() {
        return $this->lastexecutiontime;
    }

    /**
     * Set last execution time of this condition in DB
     * @param int $time
     */
    public function set_last_execution_time($time) {
        global $DB;
        $this->lastexecutiontime = $time;

        $DB->set_field('local_coursedynamicrules_condition', 'lastexecutiontime', $time, ['id' => $this->id]);
    }

    /**
     * Return params of this condition
     */
    public function get_params() {
        return $this->params;
    }

    /**
     * Runtime-only param keys whose stored value must survive an edit even though the operator
     * form does not submit them (e.g. a throttle timestamp maintained by the condition itself).
     *
     * @return array
     */
    protected function runtime_param_keys(): array {
        return [];
    }

    /**
     * Adjust a runtime-only param before it overwrites the freshly submitted value in $params
     * (FIX3-10). The base behaviour is a blunt force-win: the stored value survives byte-for-byte,
     * unconditionally. Concrete conditions may override this to refine preservation with domain
     * logic instead - e.g. a throttle timestamp that should still advance when the operator-facing
     * value driving it changes, rather than being blindly clobbered back to what was stored.
     *
     * @param string $key Runtime param key currently being reconciled.
     * @param mixed $storedvalue The value currently stored for $key.
     * @param array $newparams The full new params about to be persisted (already contains whatever
     *              the operator submitted this save).
     * @return mixed The value to persist for $key.
     */
    protected function adjust_runtime_param(string $key, $storedvalue, array $newparams) {
        return $storedvalue;
    }

    /**
     * Insert or update this condition's DB row.
     *
     * On update, only {id, params} are written: ruleid, conditiontype and lastexecutiontime are
     * never part of the UPDATE, so a tampered hidden ruleid becomes inert and runtime scheduling
     * state is preserved by construction.
     *
     * @param array $params Params to persist, built by the concrete save_condition().
     * @param stdClass $formdata Submitted form data.
     * @return int The condition id.
     */
    protected function upsert(array $params, stdClass $formdata): int {
        global $DB;

        // Read before set_data() below, which nulls $this->id when given an id-less record.
        $existingid = $this->get_id();
        $record = new stdClass();

        // The lock is enforced AT THE WRITE, not only at the endpoint: conditions.php checked the
        // URL's ruleid, but the insert below targets the form's hidden ruleid - the same
        // decided-here-written-there seam as the editrule capability bug. Resolve the rule this
        // write actually lands on (the stored component's rule on update, the ownership-validated
        // form ruleid on insert) and refuse if it is sealed. Rule deletion is NOT gated here:
        // deleting a whole rule deletes its components and stays allowed by contract.
        $targetruleid = !empty($existingid)
            ? (int) $this->ruleid
            : (int) ownership::get_rule($formdata->ruleid, $this->courseid)->id;
        \local_coursedynamicrules\helper\rule_lock::require_unlocked($targetruleid);

        if (!empty($existingid)) {
            foreach ($this->runtime_param_keys() as $key) {
                // Property_exists(), not isset(): a stored JSON null (isset() === false for it) must
                // still be reconciled via adjust_runtime_param() instead of silently falling through
                // to whatever default the concrete save_*() method computed for a brand-new row -
                // which would silently re-arm a throttle on edit (FIX3-10). is_object() guard
                // (FIX4): property_exists() throws a TypeError on a non-object under PHP 8, and
                // $this->params can be non-object if the stored 'params' column ever decodes to
                // something other than a JSON object (e.g. a stray JSON scalar/array).
                if (is_object($this->params) && property_exists($this->params, $key)) {
                    $params[$key] = $this->adjust_runtime_param($key, $this->params->$key, $params);
                }
            }
            $record->id = $existingid;
            $record->params = json_encode($params);
            $DB->update_record(static::TABLE, $record);

            // Re-hydrate fields dropped from the update object, for the set_data() call below.
            $record->ruleid = $this->ruleid;
            $record->conditiontype = $this->type;
            $record->lastexecutiontime = $this->lastexecutiontime;
        } else {
            // The very id the lock was decided on - one resolution, one write target.
            $record->ruleid = $targetruleid;
            $record->conditiontype = $this->type;
            $record->params = json_encode($params);
            $record->id = $DB->insert_record(static::TABLE, $record);
        }

        $this->set_data($record, $this->courseid);
        return $record->id;
    }

    /**
     * Set the data of the condition
     * @param object $record Record that represents data stored in DB
     * @param int $courseid the course id
     */
    public function set_data($record, $courseid = null) {
        $this->id = $record->id ?? null;
        $this->type = $record->conditiontype;
        $this->courseid = $courseid;
        // The create-branch seed record built by conditions.php always carries a ruleid, but this
        // stays defensive against any other caller that does not.
        $this->ruleid = $record->ruleid ?? null;
        $this->lastexecutiontime = $record->lastexecutiontime ?? null;
        $this->params = json_decode($record->params);
    }

    /**
     * Displays the form for editing an condition
     *
     * this function only can used after the call of build_editform()
     */
    public function show_editform() {
        $this->conditionform->display();
    }

    /**
     * Checks if the editing form was cancelled
     *
     * @return bool
     */
    public function is_cancelled() {
        return $this->conditionform->is_cancelled();
    }

    /**
     * Gets submitted data from the edit form
     *
     * @return mixed
     */
    public function get_data() {
        return $this->conditionform->get_data();
    }

    /**
     * Returns the formatted name of the condition for the complete form or response view
     *
     * @param stdClass $condition
     * @param bool $withpostfix
     * @return string
     */
    public function get_display_name($condition, $withpostfix = true) {
        return format_text($condition->name, FORMAT_HTML, ['noclean' => true, 'para' => false]) .
                ($withpostfix ? $this->get_display_name_postfix($condition) : '');
    }

    /**
     * Returns the postfix to be appended to the display name that is based on other settings
     *
     * @param stdClass $condition
     * @return string
     */
    public function get_display_name_postfix($condition) {
        return '';
    }

    /**
     * Retrieves the ID of the condition.
     *
     * @return int The ID of the condition.
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Deletes a condition record from the 'local_coursedynamicrules_condition' table. and related information with it.
     *
     * @return bool True on success, false on failure.
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public function delete() {
        global $DB;

        $record = $DB->get_record('local_coursedynamicrules_condition', ['id' => $this->id]);

        $result = $DB->delete_records('local_coursedynamicrules_condition', ['id' => $this->id]);

        $event = \local_coursedynamicrules\event\condition_deleted::create([
            'context' => \context_course::instance($this->courseid),
            'objectid' => $this->id,
        ]);
        if ($record) {
            $event->add_record_snapshot('local_coursedynamicrules_condition', $record);
        }
        $event->trigger();

        return $result;
    }

    /**
     * Evaluate the condition and return true if the condition is met
     * @param object $context Context of the rule
     * @return bool
     */
    abstract public function evaluate($context);

    /**
     * Returns the header of the condition to visualization
     *
     * @return string
     */
    abstract public function get_header();

    /**
     * Returns the description of the condition to visualization
     *
     * @return string
     */
    abstract public function get_description();

    /**
     * Creates and returns an instance of the form for editing the item
     *
     * @param mixed $action the action attribute for the form. If empty defaults to auto detect the
     *              current url. If a moodle_url object then outputs params as hidden variables.
     * @param mixed $customdata if your form defintion method needs access to data such as $course
     *              $cm, etc. to construct the form definition then pass it in this array. You can
     *              use globals for somethings.
     * @param string $method if you set this to anything other than 'post' then _GET and _POST will
     *               be merged and used as incoming data to the form.
     * @param string $target target frame for form submission. You will rarely use this. Don't use
     *               it if you don't need to as the target attribute is deprecated in xhtml strict.
     * @param mixed $attributes you can pass a string of html attributes here or an array.
     *               Special attribute 'data-random-ids' will randomise generated elements ids. This
     *               is necessary when there are several forms on the same page.
     *               Special attribute 'data-double-submit-protection' set to 'off' will turn off
     *               double-submit protection JavaScript - this may be necessary if your form sends
     *               downloadable files in response to a submit button, and can't call
     *               \core_form\util::form_download_complete();
     * @param bool $editable
     * @param array $ajaxformdata Forms submitted via ajax, must pass their data here, instead of relying on _GET and _POST.
     */
    abstract public function build_editform(
        $action = null,
        $customdata = null,
        $method = 'post',
        $target = '',
        $attributes = null,
        $editable = true,
        $ajaxformdata = null
    );

    /**
     * Saves the condition after it has been edited (or created)
     * @param object $formdata
     * @return int The id of the saved condition record.
     */
    abstract public function save_condition($formdata);
}
