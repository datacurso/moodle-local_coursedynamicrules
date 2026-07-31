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

use context_course;
use local_coursedynamicrules\action\sendnotification\sendnotification_action;
use moodle_url;

/**
 * Class sendnotification_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sendnotification_form extends action_form {
    /** @var string type of action */
    protected $type = "sendnotification";

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
            get_string('notification_action_info', 'local_coursedynamicrules'),
            \core\output\notification::NOTIFY_INFO
        );
        $mform->addElement('html', $notification);

        // Check if the messaging plugins are installed.
        if (
            !$DB->record_exists('config_plugins', ['plugin' => 'local_datacurso_msghub', 'name' => 'version'])
            || !$DB->record_exists('config_plugins', ['plugin' => 'message_datacurso_msghub', 'name' => 'version'])
        ) {
            $plugininfo = $OUTPUT->notification(
                get_string('missing_plugins_warning', 'local_coursedynamicrules'),
                \core\output\notification::NOTIFY_WARNING
            );
            $mform->addElement('html', $plugininfo);
        } else {
            $enabledproviders = get_config(
                'message',
                'message_provider_local_coursedynamicrules_smart_rules_ai_notification_enabled'
            );

            // Validate if enabledproviders includes datacurso_msghub.
            $enabledproviderslist = explode(',', $enabledproviders);
            if (!in_array('datacurso_msghub', $enabledproviderslist)) {
                $notificationsettingssurl = new moodle_url('/admin/message.php');
                $plugininfo = $OUTPUT->notification(
                    get_string('provider_not_enabled_warning', 'local_coursedynamicrules', $notificationsettingssurl->out()),
                    \core\output\notification::NOTIFY_WARNING
                );
                $mform->addElement('html', $plugininfo);
            }
        }

        $mform->addElement('text', 'messagesubject', get_string('messagesubject', 'local_coursedynamicrules'));
        $mform->setType('messagesubject', PARAM_TEXT);
        $mform->addRule('messagesubject', null, 'required', null, 'client');

        $editoroptions = [
            'subdirs' => 0,
            'maxbytes' => 0,
            'maxfiles' => 0,
            'changeformat' => 0,
            'context' => null,
            'noclean' => 0,
            'trusttext' => 0,
            'enable_filemanagement' => true,
        ];
        $mform->addElement(
            'editor',
            'messagebody',
            get_string('messagebody', 'local_coursedynamicrules'),
            null,
            $editoroptions
        );
        $mform->setType('messagebody', PARAM_RAW);
        $mform->addRule('messagebody', null, 'required', null, 'client');
        $mform->addHelpButton('messagebody', 'messagebody', 'local_coursedynamicrules');

        $placeholderstext = $OUTPUT->render_from_template('local_coursedynamicrules/notification_placeholders', []);

        $mform->addElement('static', 'messagebody_static', '', $placeholderstext);

        $mform->addElement(
            'header',
            'notificationtargetingheader',
            get_string('notificationtargeting', 'local_coursedynamicrules')
        );
        $mform->addHelpButton('notificationtargetingheader', 'notificationtargeting', 'local_coursedynamicrules');

        $roles = get_default_enrol_roles(context_course::instance($courseid));
        $roleids = array_keys($roles);
        $rolerecords = [];
        if (!empty($roleids)) {
            $rolerecords = $DB->get_records_list('role', 'id', $roleids, '', 'id,shortname');
        }

        // On CREATE only: default the student role to checked as a sensible starting point. On
        // EDIT, this must be skipped entirely: setDefault() stores a FLAT bracketed key
        // ('primaryrecipients[<id>]') in the mform's _defaultValues, which HTML_QuickForm resolves
        // BEFORE the nested zero-fill array preload_defaults() supplies via set_data() below — so a
        // deliberately unchecked student role would otherwise come back checked on edit (G3).
        // array_key_exists(), not !empty(): json_decode('[]') decodes to an empty PHP array, which
        // !empty() treats as "no record" even though the key IS present (an edit row whose stored
        // params happen to be empty), which would spuriously re-check the student default (FIX2-12).
        $isediting = array_key_exists('record', $customdata);

        $primarycheckboxes = [];
        foreach ($roles as $roleid => $rolename) {
            $fieldname = 'primaryrecipients[' . $roleid . ']';
            $primarycheckboxes[] = $mform->createElement('advcheckbox', $roleid, '', $rolename);
            $mform->setType($fieldname, PARAM_INT);

            if (!$isediting && isset($rolerecords[$roleid]) && $rolerecords[$roleid]->shortname === 'student') {
                $mform->setDefault($fieldname, 1);
            }
        }
        $mform->addGroup(
            $primarycheckboxes,
            'primaryrecipients',
            get_string('primaryrecipients', 'local_coursedynamicrules'),
            '<br />'
        );
        $mform->addHelpButton('primaryrecipients', 'primaryrecipients', 'local_coursedynamicrules');

        $copycheckboxes = [];
        foreach ($roles as $roleid => $rolename) {
            $fieldname = 'copyrecipients[' . $roleid . ']';
            $copycheckboxes[] = $mform->createElement('advcheckbox', $roleid, '', $rolename);
            $mform->setType($fieldname, PARAM_INT);
        }
        $mform->addGroup(
            $copycheckboxes,
            'copyrecipients',
            get_string('copyrecipients', 'local_coursedynamicrules'),
            '<br />'
        );
        $mform->addHelpButton('copyrecipients', 'copyrecipients', 'local_coursedynamicrules');

        $mform->addElement('hidden', 'type', $this->type);
        $mform->addElement('hidden', 'ruleid', $ruleid);
        $mform->setType('type', PARAM_TEXT);
        $mform->setType('ruleid', PARAM_INT);

        parent::definition();
    }

    /**
     * Validate the form data.
     *
     * @param array $data The form data.
     * @param array $files The uploaded files.
     * @return array An array of validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $primaryrecipients = $data['primaryrecipients'] ?? [];
        // Check if at least one primary recipient role checkbox was selected.
        $atleastoneselected = false;
        foreach ($primaryrecipients as $value) {
            if ($value == 1) {
                $atleastoneselected = true;
                break;
            }
        }

        if (!$atleastoneselected) {
            $errors['primaryrecipients'] = get_string('mustselectoneprimaryrole', 'local_coursedynamicrules');
        }

        return $errors;
    }

    /**
     * Map stored params into the checkbox-group defaults consumed by set_data().
     *
     * Every role present in the form is explicitly zero-filled first: mform's own
     * setDefault('primaryrecipients[<student>]', 1) in definition() would otherwise leave the
     * student role checked on an edit where it was deliberately unchecked, because set_data() only
     * overrides the keys it is given (G2/blocker 4).
     *
     * @param object $params Decoded stored params for the action being edited.
     * @return array
     */
    protected function preload_defaults($params): array {
        $courseid = $this->_customdata['courseid'];
        $roles = get_default_enrol_roles(context_course::instance($courseid));

        $roleids = sendnotification_action::resolve_roleids($params);
        $primaryroleids = $roleids['primary'];
        $copyroleids = $roleids['copy'];

        $primaryrecipients = [];
        $copyrecipients = [];
        foreach (array_keys($roles) as $roleid) {
            $primaryrecipients[$roleid] = in_array($roleid, $primaryroleids) ? 1 : 0;
            $copyrecipients[$roleid] = in_array($roleid, $copyroleids) ? 1 : 0;
        }

        return [
            'messagesubject' => $params->messagesubject ?? '',
            'messagebody' => ['text' => $params->messagebody ?? '', 'format' => FORMAT_HTML],
            'primaryrecipients' => $primaryrecipients,
            'copyrecipients' => $copyrecipients,
        ];
    }
}
