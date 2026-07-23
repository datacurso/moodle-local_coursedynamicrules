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

namespace local_coursedynamicrules\condition\no_course_access;

use local_coursedynamicrules\core\condition;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\conditions\no_course_access_form;
use stdClass;

/**
 * Class no_course_access_condition
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class no_course_access_condition extends condition {
    /** @var string type of condition */
    protected $type = "no_course_access";

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
    public function build_editform(
        $action = null,
        $customdata = null,
        $method = 'post',
        $target = '',
        $attributes = null,
        $editable = true,
        $ajaxformdata = null
    ) {
        $this->conditionform = new no_course_access_form(
            $action,
            $customdata,
            $method,
            $target,
            $attributes,
            $editable,
            $ajaxformdata
        );
    }

    /**
     * Evaluate the condition and return true if the condition is met
     *
     * @param object $context Context of the rule
     * @return bool
     */
    public function evaluate($context) {
        global $DB;

        $courseid = $context->courseid;
        $userid = $context->userid;
        $periodvalue = $this->params->periodvalue;
        $periodunit = $this->params->periodunit;

        // Guard against invalid stored data (e.g. legacy rules saved before validation existed):
        // an empty/non-positive period would make strtotime() return false and match every user.
        if (!self::is_valid_period($periodvalue)) {
            debugging('Invalid period value in no_course_access condition; condition skipped', DEBUG_DEVELOPER);
            return false;
        }

        $lastaccess = $DB->get_field('user_lastaccess', 'timeaccess', [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);

        // If the user has never accessed the course, measure the period from their enrolment date
        // rather than matching instantly (a freshly enrolled user is not yet "without access for N").
        if (!$lastaccess) {
            $lastaccess = $this->get_enrolment_start($courseid, $userid);
            if (!$lastaccess) {
                // No enrolment: the period cannot be measured, so the condition is not met.
                return false;
            }
        }

        $now = time();

        // Calculate the period.
        $period = strtotime("+$periodvalue $periodunit", $lastaccess);

        // If the user has not accessed the course in the last period.
        return $now >= $period;
    }

    /**
     * Get the earliest effective enrolment start for a user in a course.
     *
     * A user may have several enrolments; the earliest effective start is used. Each enrolment's
     * effective start is its timestart, or its timecreated when timestart is unset (0).
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return int|null Earliest effective enrolment start, or null if the user has no enrolment.
     */
    private function get_enrolment_start($courseid, $userid) {
        global $DB;

        $enrolments = $DB->get_records_sql(
            "SELECT ue.id, ue.timestart, ue.timecreated
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             WHERE ue.userid = :userid AND e.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );

        $starts = [];
        foreach ($enrolments as $enrolment) {
            $starts[] = $enrolment->timestart > 0 ? (int) $enrolment->timestart : (int) $enrolment->timecreated;
        }

        return $starts ? min($starts) : null;
    }

    /**
     * Saves the condition after it has been edited (or created)
     * @param object $formdata
     */
    public function save_condition($formdata) {
        global $DB;

        $periodvalue = $formdata->periodvalue;
        $periodunit = $formdata->periodunit;

        if (!self::is_valid_period($periodvalue)) {
            throw new \invalid_parameter_exception('Invalid period value: expected a positive integer');
        }

        $params = [
            'periodvalue' => (int) $periodvalue,
            'periodunit' => clean_param($periodunit, PARAM_ALPHA),
            'nexttimeperiod' => time(),
        ];

        $condition = new stdClass();
        $condition->ruleid = $formdata->ruleid;
        $condition->conditiontype = $this->type;
        $condition->params = json_encode($params);

        $this->set_data($condition);

        $DB->insert_record('local_coursedynamicrules_condition', $condition);
    }

    /**
     * Validate that a period value is a positive integer.
     *
     * @param mixed $value The period value to validate.
     * @return bool True if the value is a whole number greater than zero.
     */
    private static function is_valid_period($value) {
        return ctype_digit((string) $value) && (int) $value >= 1;
    }

    /**
     * Returns the header of the condition to visualization
     *
     * @return string
     */
    public function get_header() {
        return get_string('no_course_access', 'local_coursedynamicrules');
    }

    /**
     * Returns the description of the condition to visualization
     *
     * @return string
     */
    public function get_description() {
        $periodvalue = $this->params->periodvalue;
        $periodunit = $this->params->periodunit;

        $periodunitstr = get_string($periodunit, 'local_coursedynamicrules');
        $options = [
            'periodvalue' => $periodvalue,
            'periodunit' => strtolower($periodunitstr),
        ];
        $description = get_string('no_course_access_description', 'local_coursedynamicrules', $options);

        return $description;
    }
}
