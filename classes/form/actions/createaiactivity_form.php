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
use local_coursedynamicrules\local\service\grade_combination_service;
use local_coursedynamicrules\local\service\grade_isolation_service;
use moodle_url;

/**
 * Class createaiactivity_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createaiactivity_form extends action_form {
    /** @var string type of action */
    protected $type = 'createaiactivity';

    /**
     * Form definition
     *
     * @return void
     */
    public function definition() {
        global $OUTPUT, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $mform = $this->_form;
        $customdata = $this->_customdata;

        $ruleid = $customdata['ruleid'];
        $courseid = $customdata['courseid'];

        $notification = $OUTPUT->notification(
            get_string('createaiactivity_action_info', 'local_coursedynamicrules'),
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

        $mform->addElement(
            'textarea',
            'message',
            get_string('createaiactivity_prompt', 'local_coursedynamicrules'),
            ['rows' => 5]
        );
        $mform->setType('message', PARAM_RAW_TRIMMED);
        $mform->addRule('message', null, 'required', null, 'client');
        $mform->addHelpButton('message', 'createaiactivity_prompt', 'local_coursedynamicrules');

        $placeholderstext = $OUTPUT->render_from_template('local_coursedynamicrules/notification_placeholders', []);
        $mform->addElement('static', 'message_placeholders', '', $placeholderstext);

        $mform->addElement(
            'advcheckbox',
            'generateimages',
            get_string('createaiactivity_generateimages', 'local_coursedynamicrules'),
            get_string('createaiactivity_generateimages_label', 'local_coursedynamicrules')
        );
        $mform->setType('generateimages', PARAM_BOOL);

        $course = get_course($courseid);
        $sectionoptions = [];
        $sectioninfos = get_fast_modinfo($courseid)->get_section_info_all();
        foreach ($sectioninfos as $sectioninfo) {
            $sectionoptions[$sectioninfo->section] = get_section_name($course, $sectioninfo->section);
        }
        $mform->addElement(
            'select',
            'sectionnum',
            get_string('createaiactivity_section', 'local_coursedynamicrules'),
            $sectionoptions
        );
        $mform->setType('sectionnum', PARAM_INT);
        if (array_key_exists(0, $sectionoptions)) {
            $mform->setDefault('sectionnum', 0);
        }

        $beforeoptions = [0 => get_string('createaiactivity_beforemod_none', 'local_coursedynamicrules')];
        $cms = get_fast_modinfo($courseid)->get_cms();
        foreach ($cms as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            $beforeoptions[$cm->id] = ucfirst($cm->modname) . ' - ' . $cm->name;
        }

        $mform->addElement(
            'select',
            'beforemod',
            get_string('createaiactivity_beforemod', 'local_coursedynamicrules'),
            $beforeoptions
        );
        $mform->setType('beforemod', PARAM_INT);
        $mform->setDefault('beforemod', 0);
        $mform->addHelpButton('beforemod', 'createaiactivity_beforemod', 'local_coursedynamicrules');

        // Two questions, because they are two decisions. Flattening them into one list puts
        // "counts here" and "counts somewhere else" side by side as if they were siblings.
        $mform->addElement('select', 'hasgrade', get_string('createaiactivity_hasgrade', 'local_coursedynamicrules'), [
            0 => get_string('createaiactivity_hasgrade_no', 'local_coursedynamicrules'),
            1 => get_string('createaiactivity_hasgrade_yes', 'local_coursedynamicrules'),
        ]);
        $mform->setType('hasgrade', PARAM_INT);
        // An activity that carries no grade cannot disturb anybody's total, under any aggregation.
        $mform->setDefault('hasgrade', 0);
        $mform->addHelpButton('hasgrade', 'createaiactivity_hasgrade', 'local_coursedynamicrules');

        // Only worth asking when the rule actually watches a graded activity. Offering the choice
        // and then refusing it in validation wastes the teacher's time on a decision that was never
        // available; naming the activity removes any doubt about which one is meant.
        $sourcecmid = grade_combination_service::resolve_source_cmid((int) $ruleid);
        if ($sourcecmid !== null) {
            $sourcename = grade_combination_service::activity_name($sourcecmid);

            // Deliberately NOT the strings the rule listing uses: here the options answer a yes/no
            // question, so MODE_OWN reads as "no, it stays independent"; in a description that same
            // mode has to name itself.
            $affectsource = [];
            foreach (grade_isolation_service::graded_modes() as $mode) {
                $affectsource[$mode] = get_string('createaiactivity_gradeoption_' . $mode, 'local_coursedynamicrules');
            }
            $mform->addElement(
                'select',
                'grademode',
                get_string('createaiactivity_affectsource', 'local_coursedynamicrules', $sourcename),
                $affectsource
            );
            $mform->setType('grademode', PARAM_ALPHA);
            $mform->setDefault('grademode', grade_isolation_service::MODE_OWN);
            $mform->addHelpButton('grademode', 'createaiactivity_affectsource', 'local_coursedynamicrules');
            $mform->hideIf('grademode', 'hasgrade', 'eq', 0);

            // One sub-select per option that takes a rule, rather than a single field whose options
            // change: mform cannot repopulate a select from another field without JavaScript. Two
            // hideIf conditions on the same element are OR-ed (lib/form/form.js:178), so each rule
            // hides both when the activity is ungraded and when the other option is chosen.
            foreach ([
                grade_isolation_service::MODE_COMBINE => 'combinerule',
                grade_isolation_service::MODE_REPLACE => 'replacerule',
            ] as $mode => $field) {
                $options = [];
                foreach (grade_isolation_service::rules_for($mode) as $rule) {
                    $options[$rule] = get_string('createaiactivity_graderule_' . $rule, 'local_coursedynamicrules');
                }
                $mform->addElement(
                    'select',
                    $field,
                    get_string('createaiactivity_' . $field, 'local_coursedynamicrules'),
                    $options
                );
                $mform->setType($field, PARAM_ALPHA);
                $mform->setDefault($field, array_key_first($options));
                $mform->hideIf($field, 'hasgrade', 'eq', 0);
                $mform->hideIf($field, 'grademode', 'neq', $mode);
            }
        }

        parent::definition();
    }

    /**
     * Map stored params into the defaults consumed by set_data().
     *
     * @param object $params Decoded stored params for the action being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        $storedmode = grade_isolation_service::clean_mode($params->grademode ?? null);

        return [
            'message' => $params->message ?? '',
            'generateimages' => !empty($params->generateimages) ? 1 : 0,
            'sectionnum' => (int) ($params->sectionnum ?? 0),
            'beforemod' => (int) ($params->beforemod ?? 0),
            'hasgrade' => $storedmode === grade_isolation_service::MODE_NOGRADE ? 0 : 1,
            'grademode' => $storedmode === grade_isolation_service::MODE_NOGRADE
                ? grade_isolation_service::MODE_OWN
                : $storedmode,
            'combinerule' => grade_isolation_service::clean_rule(
                grade_isolation_service::MODE_COMBINE,
                $params->graderule ?? null
            ),
            'replacerule' => grade_isolation_service::clean_rule(
                grade_isolation_service::MODE_REPLACE,
                $params->graderule ?? null
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
            [
                'pluginname' => 'local_coursegen',
                'downloadurl' => 'https://moodle.org/plugins/local_coursegen/versions',
            ],
            [
                'pluginname' => 'aiprovider_datacurso',
                'downloadurl' => 'https://moodle.org/plugins/aiprovider_datacurso/versions',
            ],
        ];

        return $plugins;
    }
}
