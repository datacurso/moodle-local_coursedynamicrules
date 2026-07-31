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


/**
 * TODO describe file passgrade_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passgrade_form extends condition_form {
    /** @var string type of condition */
    protected $type = "passgrade";
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

        $notification = $OUTPUT->notification(
            get_string('passgrade_condition_info', 'local_coursedynamicrules'),
            \core\output\notification::NOTIFY_INFO
        );
        $mform->addElement('html', $notification);

        $modinfo = get_fast_modinfo($this->courseid);
        $cms = $modinfo->get_cms();
        $options = [];
        foreach ($cms as $cm) {
            // Get only course modules that require passgrade.
            if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC && $cm->completionpassgrade) {
                $options[$cm->id] = ucfirst($cm->modname) . " - " . $cm->name;
            }
        }

        $attributes = [
            'multiple' => false,
            'noselectionstring' => get_string('selectanactivity', 'local_coursedynamicrules'),
        ];
        $mform->addElement(
            'autocomplete',
            'coursemodule',
            get_string(
                'searchcourseactivitymodules',
                'local_coursedynamicrules'
            ),
            $options,
            $attributes
        );
        $mform->setType('coursemodule', PARAM_INT);

        parent::definition();
    }

    /**
     * Server side validation: an activity of this course must be selected.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $cmid = (int) ($data['coursemodule'] ?? 0);
        if ($cmid <= 0 || !isset(get_fast_modinfo($this->courseid)->cms[$cmid])) {
            $errors['coursemodule'] = get_string('errornocoursemodule', 'local_coursedynamicrules');
        }

        return $errors;
    }

    /**
     * Map the stored 'cmid' param onto the 'coursemodule' autocomplete element.
     *
     * @param object $params Decoded stored params for the condition being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        return ['coursemodule' => $params->cmid ?? null];
    }
}
