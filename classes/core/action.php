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

use local_coursedynamicrules\form\actions\action_form;
use stdClass;

/**
 * Class action
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class action {
    /** @var int|null ID of the action on the DB */
    private $id;

    /** @var action_form|null */
    protected $actionform;

    /** @var string Type of the action example: sendnotification */
    protected $type;

    /** @var object Parameters of the action stored in DB */
    protected $params;

    /** @var int course id */
    protected $courseid;

    /** @var int rule id */
    protected $ruleid;

    /** @var int|null $lastexecutiontime Indicate time of last finished execution */
    protected $lastexecutiontime;

    /**
     * Action constructor.
     * @param object $record Record of the action stored in DB
     * @param int $courseid the course id where the action is applied.
     */
    public function __construct($record, $courseid = null) {
        $this->set_data($record, $courseid);
    }

    /**
     * Returns the header of the action to visualization
     *
     * @return string
     */
    public function get_header() {
        return get_string($this->type, 'local_coursedynamicrules');
    }

    /**
     * Returns the description of the action to visualization
     *
     * @return string
     */
    public function get_description() {
        return get_string($this->type . '_description', 'local_coursedynamicrules');
    }

    /**
     * Displays the form for editing an action
     *
     * this function only can used after the call of build_editform()
     */
    public function show_editform() {
        $this->actionform->display();
    }

    /**
     * Checks if the editing form was cancelled
     *
     * @return bool
     */
    public function is_cancelled() {
        return $this->actionform->is_cancelled();
    }

    /**
     * Gets submitted data from the edit form
     *
     * @return mixed
     */
    public function get_data() {
        return $this->actionform->get_data();
    }

    /**
     * Return last execution time of this action
     */
    public function get_last_execution_time() {
        return $this->lastexecutiontime;
    }

    /**
     * Set the last execution time of the action in the DB
     *
     * @param int $time the time to set
     */
    public function set_last_execution_time($time) {
        global $DB;
        $this->lastexecutiontime = $time;
        $DB->set_field('local_coursedynamicrules_action', 'lastexecutiontime', $time, ['id' => $this->id]);
    }

    /**
     * Set the action data
     *
     * @param stdClass $record the action data to set
     * @param int $courseid the course id
     */
    public function set_data($record, $courseid = null) {
        $this->id = $record->id ?? null;
        $this->type = $record->actiontype;
        $this->courseid = $courseid;
        $this->ruleid = $record->ruleid;
        $this->lastexecutiontime = $record->lastexecutiontime ?? null;
        $this->params = json_decode($record->params);
    }

    /**
     * Retrieves the ID of the action.
     *
     * @return int The ID of the action.
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Deletes a action record from the 'local_coursedynamicrules_action' table. and related information with it.
     *
     * @return bool True on success, false on failure.
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public function delete() {
        global $DB;

        $record = $DB->get_record('local_coursedynamicrules_action', ['id' => $this->get_id()]);

        $result = $DB->delete_records('local_coursedynamicrules_action', ['id' => $this->get_id()]);

        $event = \local_coursedynamicrules\event\action_deleted::create([
            'context' => \context_course::instance($this->courseid),
            'objectid' => $this->get_id(),
        ]);
        if ($record) {
            $event->add_record_snapshot('local_coursedynamicrules_action', $record);
        }
        $event->trigger();

        return $result;
    }

    /**
     * Execute the action
     * @param object $context Context of the rule
     */
    abstract public function execute($context);

    /**
     * Creates and returns an instance of the form for editing the action
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
     * Saves the action after it has been edited (or created)
     * @param object $formdata
     * @return int The id of the saved action record.
     */
    abstract public function save_action($formdata);
}
