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

namespace local_coursedynamicrules\form\conditions;

use local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition;

/**
 * Class course_inactivity_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_inactivity_form extends condition_form {
    /** @var string base date for evaluating the intervals is start date of user enrolment*/
    const DATE_FROM_ENROLLMENT = course_inactivity_condition::DATE_FROM_ENROLLMENT;

    /** @var string base date for evaluating the intervals is start date of course */
    const DATE_FROM_COURSE_START = course_inactivity_condition::DATE_FROM_COURSE_START;

    /** @var string base date for evaluating the intervals is current date */
    const DATE_FROM_NOW = course_inactivity_condition::DATE_FROM_NOW;

    /**
     * @var string interval is a custom set of comma-separated values
     * for interval evaluations. For example, "7,14,30" means the condition will be
     * evaluated at 7 days, 14 days, and 30 days.
     */
    const INTERVAL_CUSTOM = course_inactivity_condition::INTERVAL_CUSTOM;

    /** @var string interval is recurring based on the specified date type, e.g., every 7 days */
    const INTERVAL_RECURRING = course_inactivity_condition::INTERVAL_RECURRING;

    /** @var string type of condition */
    protected $type = "course_inactivity";

    /**
     * Form definition
     *
     * @return void
     */
    public function definition() {
        global $PAGE, $OUTPUT;
        $pluginname = 'local_coursedynamicrules';

        $mform = $this->_form;
        $customdata = $this->_customdata;
        $this->courseid = $customdata['courseid'];
        $this->ruleid = $customdata['ruleid'];

        $notification = $OUTPUT->notification(
            get_string('course_inactivity_info', $pluginname),
            \core\output\notification::NOTIFY_INFO
        );
        $mform->addElement('html', $notification);

        $intervaltypeoptions = [
            self::INTERVAL_CUSTOM => get_string('customintervals', $pluginname),
            self::INTERVAL_RECURRING => get_string('recurringinterval', $pluginname),
        ];
        $mform->addElement('select', 'intervaltype', get_string('intervaltype', $pluginname), $intervaltypeoptions);
        $mform->addHelpButton('intervaltype', 'intervaltype', $pluginname);

        $mform->addElement('text', 'customintervals', get_string('customintervals', $pluginname));
        $mform->setType('customintervals', PARAM_RAW);
        $mform->addHelpButton('customintervals', 'customintervals', $pluginname);
         $mform->hideIf('customintervals', 'intervaltype', 'neq', self::INTERVAL_CUSTOM);

         $mform->addElement('text', 'recurringinterval', get_string('recurringinterval', $pluginname));
         $mform->setType('recurringinterval', PARAM_RAW);
         $mform->addHelpButton('recurringinterval', 'recurringinterval', $pluginname);
         $mform->hideIf('recurringinterval', 'intervaltype', 'neq', self::INTERVAL_RECURRING);

         $mform->addElement('select', 'intervalunit', get_string('intervalunit', $pluginname), [
            'days' => get_string('days', $pluginname),
            'weeks' => get_string('weeks', $pluginname),
            'months' => get_string('months', $pluginname),
         ]);
        $mform->addHelpButton('intervalunit', 'intervalunit', $pluginname);

        $basedatetypeoptions = [
            self::DATE_FROM_ENROLLMENT => get_string('date_from_enrollment', $pluginname),
            self::DATE_FROM_COURSE_START => get_string('date_from_course_start', $pluginname),
            self::DATE_FROM_NOW => get_string('date_from_now', $pluginname),
        ];
        $mform->addElement('select', 'basedatetype', get_string('basedate', $pluginname), $basedatetypeoptions);
        $mform->addHelpButton('basedatetype', 'basedate', $pluginname);

        parent::definition();
    }

    /**
     * Server side validation of the interval configuration.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $intervaltype = $data['intervaltype'] ?? '';
        if ($intervaltype === self::INTERVAL_RECURRING) {
            if (!course_inactivity_condition::is_valid_recurring_interval($data['recurringinterval'] ?? '')) {
                $errors['recurringinterval'] = get_string('errorrecurringinterval', 'local_coursedynamicrules');
            }
        } else if ($intervaltype === self::INTERVAL_CUSTOM) {
            if (!course_inactivity_condition::is_valid_custom_intervals($data['customintervals'] ?? '')) {
                $errors['customintervals'] = get_string('errorcustomintervals', 'local_coursedynamicrules');
            }
        }

        return $errors;
    }
}
