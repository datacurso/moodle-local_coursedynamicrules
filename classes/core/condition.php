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
use stdClass;

/**
 * Class condition
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class condition {
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
     * Set the data of the condition
     * @param object $record Record that represents data stored in DB
     * @param int $courseid the course id
     */
    public function set_data($record, $courseid = null) {
        $this->id = $record->id ?? null;
        $this->type = $record->conditiontype;
        $this->courseid = $courseid;
        $this->ruleid = $record->ruleid;
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
