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

namespace local_coursedynamicrules\form\actions;

use local_coursedynamicrules\helper\form_plugin_validator;
use moodle_url;

/**
 * Class enableactivity_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enableactivity_form extends action_form {
    /** @var string type of action */
    protected $type = "enableactivity";

    /**
     * Form definition
     *
     * @return void
     */
    public function definition() {
        global $OUTPUT, $DB;
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $ruleid = $customdata['ruleid'];
        $courseid = $customdata['courseid'];

        $notification = $OUTPUT->notification(
            get_string('enableactivity_action_info', 'local_coursedynamicrules'),
            \core\output\notification::NOTIFY_INFO
        );
        $mform->addElement('html', $notification);

        $requiredplugins = $this->get_required_plugins();
        $missingplugins = form_plugin_validator::add_notifications_to_form($mform, $requiredplugins);

        if (!empty($missingplugins)) {
            return;
        }

        $mform->addElement('hidden', 'type', $this->type);
        $mform->addElement('hidden', 'ruleid', $ruleid);
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('type', PARAM_TEXT);
        $mform->setType('ruleid', PARAM_INT);
        $mform->setType('courseid', PARAM_INT);

        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        $options = [];
        foreach ($cms as $cm) {
            if (!$cm->deletioninprogress) {
                $options[$cm->id] = ucfirst($cm->modname) . " - " . $cm->name;
            }
        }

        $attributes = [
            'multiple' => true,
            'noselectionstring' => get_string('allcourseactivitymodules', 'local_coursedynamicrules'),
        ];
        $mform->addElement(
            'autocomplete',
            'coursemodules',
            get_string(
                'searchcourseactivitymodules',
                'local_coursedynamicrules'
            ),
            $options,
            $attributes
        );
        $mform->setType('coursemodules', PARAM_INT);

        parent::definition();
    }

    /**
     * Reject an empty selection (FIX3-8): submitting with no course module selected would silently
     * revert EVERY currently-managed module (restore_coursemodules() treats the whole prior set as
     * "removed"), which is a destructive edit an operator is unlikely to intend.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Validation errors, keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // ElementExists() guard (FIX4): definition() early-returns without adding 'coursemodules' at
        // all when a required plugin (availability_user) is missing - setting an error keyed to a
        // non-existent element would be silently lost (never rendered next to any visible field),
        // instead of surfacing the real problem via the missing-plugin notification already shown.
        // $this->_form can itself be null here (e.g. a form built via
        // ReflectionClass::newInstanceWithoutConstructor() in a unit test that exercises
        // validation() in isolation) - only treat the element as "missing" when a real $_form is
        // available AND it confirms the element was never added.
        $mform = $this->_form;
        $coursemodulesmissing = $mform !== null && !$mform->elementExists('coursemodules');

        if (!$coursemodulesmissing && empty($data['coursemodules'])) {
            $errors['coursemodules'] = get_string('enableactivity_nomodulesselected', 'local_coursedynamicrules');
        }

        return $errors;
    }

    /**
     * Map stored params into the multi-select default consumed by set_data().
     *
     * @param object $params Decoded stored params for the action being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        return [
            'coursemodules' => array_map(
                fn($cm) => (int) $cm->id,
                $params->coursemodules ?? []
            ),
        ];
    }

    /**
     * Returns the required plugins needed by the action.
     *
     * @return array
     */
    private function get_required_plugins() {
        $plugins = [
            [
                'pluginname' => 'availability_user',
                'enableurl' => new moodle_url('/admin/tool/availabilityconditions/'),
                'downloadurl' => 'https://moodle.org/plugins/availability_user/versions',
            ],
        ];

        return $plugins;
    }
}
