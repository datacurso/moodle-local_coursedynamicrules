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

use html_writer;

/**
 * Class grade_in_activity
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_in_activity_form extends condition_form {
    /** @var string type of condition */
    protected $type = "grade_in_activity";

    /**
     * Form definition
     *
     * @return void
     */
    public function definition() {
        global $PAGE, $OUTPUT;
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $this->courseid = $customdata['courseid'];
        $this->ruleid = $customdata['ruleid'];

        $attributes = $mform->getAttributes();
        $attributes['id'] = 'grade_in_activity_form';
        $attributes['novalidate'] = true;

        $mform->setAttributes($attributes);

        $notification = $OUTPUT->notification(
            get_string('grade_in_activity_condition_info', 'local_coursedynamicrules'),
            \core\output\notification::NOTIFY_INFO
        );
        $mform->addElement('html', $notification);

        // Create container for dynamic form.
        $mform->addElement('html', html_writer::div('', '', ['data-region' => 'dynamicform']));

        $mform->addElement('hidden', 'gradeitems', '{}');
        $mform->setType('gradeitems', PARAM_RAW);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        parent::definition();

        $PAGE->requires->js_call_amd('local_coursedynamicrules/grade_in_activity_form', 'init', []);
    }

    /**
     * Server side backstop: never allow saving a condition without any enabled grade threshold,
     * which would silently create a rule that can never be met.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $gradeitems = json_decode($data['gradeitems'] ?? '', true);
        if (!is_array($gradeitems)) {
            $errors['gradeitems'] = get_string('errorinvalidgradeitems', 'local_coursedynamicrules');
            return $errors;
        }

        $enabled = array_filter($gradeitems, function ($item) {
            return is_array($item) && empty($item['disabled']) && ($item['value'] ?? '') !== '';
        });

        if (empty($enabled)) {
            $errors['gradeitems'] = get_string('errornogradeconditions', 'local_coursedynamicrules');
        }

        return $errors;
    }
}
