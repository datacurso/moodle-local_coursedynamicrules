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

        parent::definition();
    }

    /**
     * Map stored params into the defaults consumed by set_data().
     *
     * @param object $params Decoded stored params for the action being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        return [
            'message' => $params->message ?? '',
            'generateimages' => !empty($params->generateimages) ? 1 : 0,
            'sectionnum' => (int) ($params->sectionnum ?? 0),
            'beforemod' => (int) ($params->beforemod ?? 0),
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
