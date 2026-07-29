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

namespace local_coursedynamicrules\action\createaiactivity;

use aiprovider_datacurso\httpclient\ai_course_api;
use core_availability\tree;
use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\actions\createaiactivity_form;
use local_coursedynamicrules\local\payload_anonymizer;
use local_coursegen\ai_context;
use local_coursegen\mod_manager;
use moodle_url;
use stdClass;

/**
 * Class createaiactivity_action
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createaiactivity_action extends action {
    /** @var string type of the action */
    protected $type = 'createaiactivity';

    /**
     * Execute the action
     *
     * @param object $context Context of the rule
     */
    public function execute($context) {
        global $CFG, $DB;

        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_coursegen');
        if (!$plugininfo) {
            debugging(get_string('error_required_local_coursegen', 'local_coursedynamicrules'), DEBUG_DEVELOPER);
            return;
        }

        $courseid = $context->courseid;
        $userid = $context->userid;

        $message = $this->params->message ?? '';
        if (trim($message) === '') {
            debugging(get_string('error_empty_aiactivity_prompt', 'local_coursedynamicrules'), DEBUG_DEVELOPER);
            return;
        }

        try {
            require_once($CFG->dirroot . '/course/lib.php');
            require_once($CFG->dirroot . '/course/modlib.php');

            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

            $sectionnum = (int) ($this->params->sectionnum ?? 0);
            $beforemod = $this->params->beforemod ?? null;
            $beforemod = $beforemod ? (int) $beforemod : null;
            $generateimages = !empty($this->params->generateimages);

            $prompt = $this->build_prompt($message, $course, $user);

            $aicontext = ai_context::get_valid_course_context($courseid);

            $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);

            $payload = [
                'message' => $prompt,
                'course_id' => $courseid,
                'userid' => (string) $userid,
                'generate_images' => $generateimages,
                'url' => $courseurl->out(false),
                'context_type' => $aicontext ? $aicontext->context_type : null,
                'model_name' => $aicontext ? $aicontext->model_name : null,
            ];

            $anonymized = payload_anonymizer::anonymize($payload, $user);
            $payload = $anonymized['payload'];
            $replacements = $anonymized['replacements'];

            // These calls may take a long time depending on prompt complexity.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            \core\session\manager::write_close();

            $client = new ai_course_api();
            $result = $client->request('POST', '/smartrules/create-mod', $payload);
            $result = payload_anonymizer::deanonymize_data($result, $replacements);

            $newcm = mod_manager::create_from_ai_result($result, $course, $sectionnum, $beforemod);

            // Restrict the new activity to the current user only.
            $availabilityoptions = (object) [
                'type' => 'user',
                'userids' => [$userid],
            ];
            $availability = tree::get_root_json([$availabilityoptions], tree::OP_AND, false);

            $DB->set_field(
                'course_modules',
                'availability',
                json_encode($availability),
                ['id' => $newcm->coursemodule]
            );

            set_coursemodule_visible($newcm->coursemodule, 1);
            rebuild_course_cache($courseid, true);
        } catch (\Throwable $e) {
            debugging(
                get_string('error_unexpected_creating_aiactivity', 'local_coursedynamicrules', $e->getMessage()),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Creates and returns an instance of the form for editing the action.
     *
     * @param mixed $action the action attribute for the form.
     * @param mixed $customdata form custom data.
     * @param string $method form method.
     * @param string $target form target.
     * @param mixed $attributes form attributes.
     * @param bool $editable whether the form is editable.
     * @param array|null $ajaxformdata ajax form data.
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
        $this->actionform = new createaiactivity_form(
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
     * Saves the action after it has been created or edited.
     *
     * @param object $formdata
     */
    public function save_action($formdata) {
        global $DB;

        $params = [
            'message' => trim($formdata->message),
            'generateimages' => !empty($formdata->generateimages),
            'sectionnum' => (int) $formdata->sectionnum,
            'beforemod' => empty($formdata->beforemod) ? null : (int) $formdata->beforemod,
        ];

        $action = new stdClass();
        $action->ruleid = $formdata->ruleid;
        $action->actiontype = $this->type;
        $action->params = json_encode($params);

        $this->set_data($action);
        return $DB->insert_record('local_coursedynamicrules_action', $action);
    }

    /**
     * Returns the description of the action to visualization.
     *
     * @return string
     */
    public function get_description() {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $course = get_course($this->courseid);
        $sectionnum = (int) ($this->params->sectionnum ?? 0);
        $sectionname = get_section_name($course, $sectionnum);
        $prompt = $this->params->message ?? '';
        $shortprompt = shorten_text($prompt, 80);

        $data = (object) [
            'section' => $sectionname,
            'prompt' => $shortprompt,
        ];

        $description = get_string('createaiactivity_description', 'local_coursedynamicrules', $data);

        $generateimages = !empty($this->params->generateimages) ? get_string('yes') : get_string('no');
        $description .= ' ' . get_string(
            'createaiactivity_description_generateimages',
            'local_coursedynamicrules',
            $generateimages
        );

        $beforemod = !empty($this->params->beforemod) ? (int) $this->params->beforemod : null;
        if ($beforemod) {
            $modinfo = get_fast_modinfo($course);
            $cm = $modinfo->cms[$beforemod] ?? null;
            if ($cm) {
                $description .= ' ' . get_string(
                    'createaiactivity_description_beforemod',
                    'local_coursedynamicrules',
                    $cm->name
                );
            }
        }

        return $description;
    }

    /**
     * Build the AI prompt replacing placeholders.
     *
     * @param string $message
     * @param \stdClass $course
     * @param \stdClass $user
     * @return string
     */
    protected function build_prompt(string $message, \stdClass $course, \stdClass $user): string {
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $placeholders = [
            '{$a->coursename}' => format_string($course->fullname),
            '{$a->courseurl}' => $courseurl->out(false),
            '{$a->fullname}' => fullname($user),
            '{$a->firstname}' => $user->firstname,
            '{$a->lastname}' => $user->lastname,
        ];

        return strtr($message, $placeholders);
    }
}
