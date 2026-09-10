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

use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\helper\component_renderer;
use local_coursedynamicrules\helper\form_plugin_validator;

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

        // ElementExists() guard: definition() early-returns without adding 'coursemodules' at all
        // when a required plugin (availability_user) is missing. A browser never submits that
        // degraded form, but a forged POST can - and skipping the emptiness check here would let
        // get_data() hand save_action() an action with no target modules. The error is set either
        // way: an error keyed to a non-existent element renders nowhere, but any validation error
        // makes get_data() return null, which is the refusal that matters; the visible explanation
        // stays with the missing-plugin notification the page already shows.
        // $this->_form can itself be null here (e.g. a form built via
        // ReflectionClass::newInstanceWithoutConstructor() in a unit test that exercises
        // validation() in isolation) - that case still validates the submitted data.
        if (empty($data['coursemodules'])) {
            $errors['coursemodules'] = get_string('enableactivity_nomodulesselected', 'local_coursedynamicrules');

            return $errors;
        }

        // An activity already gated by ANOTHER action of this plugin cannot be shared: Moodle ANDs
        // the two gates, so the activity would close for the students the other action had opened it
        // for, from the moment this form is saved and until this action runs for each of them too.
        // Refused rather than warned, because the harm lands on students who did nothing.
        // Read exactly as definition() reads it, with no "?? 0" fallback: a missing course would
        // make the query below match nothing and the refusal disappear in silence, which is the one
        // outcome worse than refusing wrongly. No form can reach this without a course anyway -
        // definition() reads the same key unguarded and would have failed first.
        $courseid = (int) $this->_customdata['courseid'];
        $clashing = enableactivity_action::modules_gated_by_another_action(
            (array) $data['coursemodules'],
            $courseid,
            $this->_customdata['actionid'] ?? null
        );
        if (!empty($clashing)) {
            // Escaped, not raw: a form error is rendered as HTML by core (element-template.mustache
            // emits {{{error}}}), and an activity name is operator input. component_renderer is the
            // plugin's one door for this, used everywhere a component's name reaches a screen.
            $modinfo = get_fast_modinfo($courseid);
            $names = [];
            foreach ($clashing as $cmid) {
                $names[] = component_renderer::escaped_name(
                    $modinfo->cms[$cmid]->name ?? (string) $cmid,
                    \context_course::instance($courseid)
                );
            }
            $errors['coursemodules'] = get_string(
                'enableactivity_alreadygated',
                'local_coursedynamicrules',
                implode(', ', $names)
            );
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
        return \local_coursedynamicrules\local\form_preload::enableactivity($params);
    }

    /**
     * Returns the required plugins needed by the action.
     *
     * @return array
     */
    private function get_required_plugins() {
        // Asked of the action, so the pencil in the listing and the notifications on this form can
        // never disagree about what is needed.
        return enableactivity_action::required_plugins();
    }
}
