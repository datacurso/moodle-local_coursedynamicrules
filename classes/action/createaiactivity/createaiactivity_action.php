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
use core\lock\lock;
use core\lock\lock_config;
use core_availability\tree;
use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\actions\createaiactivity_form;
use local_coursedynamicrules\local\payload_anonymizer;
use local_coursedynamicrules\local\service\grade_register_service;
use local_coursedynamicrules\local\service\grade_isolation_service;
use local_coursegen\ai_context;
use local_coursegen\local\service\create_mod_service;
use moodle_url;

/**
 * Class createaiactivity_action
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createaiactivity_action extends action {
    /** @var int Minimum local_coursegen version providing the create_mod_service API. */
    public const COURSEGEN_MIN_VERSION = 2026082400;

    /** @var int Maximum seconds to keep the activity generation stream open. */
    public const STREAM_TIMEOUT = 600;

    /** @var string Lock type guarding one (action, user) generation against a concurrent twin. */
    public const GENERATION_LOCK_TYPE = 'local_coursedynamicrules_aiactivity';

    /** @var string type of the action */
    protected $type = 'createaiactivity';

    /**
     * Execute the action, once, even when two processes ask at the same instant.
     *
     * already_generated() below is a read, and the write that answers it happens AFTER the paid AI
     * call returns - so the window between them is as wide as the generation itself, up to
     * STREAM_TIMEOUT seconds. Two processes reaching it together both see "not generated yet", both
     * pay, and the student gets two activities.
     *
     * That is reachable, not theoretical. The three scheduled tasks are separate task CLASSES, so
     * core's per-task lock does not serialise them against each other, and each selects rules by
     * joining on condition type (no_complete_activity_task.php:57-61) - a rule carrying two cron
     * condition types is picked up by both, every minute. On top of that the event observers queue
     * ad-hoc rule_task runs, which can overlap a scheduled pass. And nothing else stands in the
     * way: set_last_execution_time() is written after every action has already run
     * (core/rule.php:174), and is_time_to_execute_rule() never reads it.
     *
     * A unique key on (actionid, userid) was considered and rejected twice over: it would collide
     * only at the insert, AFTER the money is spent, and it is not even expressible - forget_action()
     * and forget_users() deliberately write actionid = 0 and userid = 0 on many rows to sever a
     * register entry rather than delete it, which is precisely what a unique index forbids.
     *
     * @param object $context Context of the rule
     */
    public function execute($context) {
        $lock = $this->get_generation_lock((int) ($context->userid ?? 0));
        if ($lock === null) {
            // Somebody else is generating this exact activity for this exact student right now.
            // Doing nothing is correct: when they finish, the register row makes every later pass
            // a no-op anyway.
            return;
        }

        try {
            $this->execute_generation($context);
        } finally {
            $lock->release();
        }
    }

    /**
     * Take the generation lock for one (action, user) pair, without waiting.
     *
     * A method of its own so a test can refuse the lock: with mysqli the factory is
     * mysql_lock_factory, whose GET_LOCK is re-entrant within one database session, so a second
     * acquisition inside a single PHPUnit process SUCCEEDS. The guard only manifests across
     * processes, which is where cron lives and where no unit test can follow.
     *
     * Timeout 0 on purpose: a queue here would serialise a paid, minutes-long call behind another
     * one whose completion makes this pass pointless.
     *
     * @param int $userid
     * @return lock|null Null when another process holds it.
     */
    protected function get_generation_lock(int $userid): ?lock {
        $factory = lock_config::get_lock_factory(self::GENERATION_LOCK_TYPE);
        $lock = $factory->get_lock($this->get_id() . '_' . $userid, 0);

        return $lock instanceof lock ? $lock : null;
    }

    /**
     * The generation itself, run under the lock taken by execute().
     *
     * @param object $context Context of the rule
     */
    private function execute_generation($context) {
        global $CFG, $COURSE, $DB, $OUTPUT, $PAGE;

        $coursegenversion = $this->get_coursegen_versiondb();
        if ($coursegenversion === null) {
            debugging(get_string('error_required_local_coursegen', 'local_coursedynamicrules'), DEBUG_DEVELOPER);
            return;
        }
        if ($coursegenversion < self::COURSEGEN_MIN_VERSION) {
            debugging(
                get_string(
                    'error_required_local_coursegen_version',
                    'local_coursedynamicrules',
                    self::COURSEGEN_MIN_VERSION
                ),
                DEBUG_DEVELOPER
            );
            return;
        }

        $courseid = $context->courseid;
        $userid = $context->userid;

        // Before anything else, and before the paid AI call: the scheduled tasks re-run their
        // rules indefinitely, so without this the same student receives a new activity on every
        // pass - up to 1440 a day with no_complete_activity_task.
        if (grade_register_service::already_generated((int) $this->get_id(), (int) $userid)) {
            return;
        }

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

            // The v2 service has no server-side course context: a custom prompt is the
            // only context type it can honor, inlined as a preamble of the instructions.
            $aicontext = ai_context::get_valid_course_context($courseid);
            $instructions = $prompt;
            if (!empty($aicontext->prompt_text)) {
                $instructions = trim(html_to_text($aicontext->prompt_text, 0, false)) . "\n\n" . $instructions;
            }

            $payload = [
                'instructions' => $instructions,
                'lang' => $this->resolve_request_language($user, $course),
                'with_images' => $generateimages,
                'userid' => (string) $userid,
                'site_url' => $CFG->wwwroot,
                // Rules run unattended from cron: nobody can approve the plan.
                'auto_approve' => true,
                // Billing identity: consumption belongs to SmartRules, not coursegen.
                'service_id' => 'local_coursedynamicrules',
            ];

            $anonymized = payload_anonymizer::anonymize($payload, $user);
            $payload = $anonymized['payload'];
            $replacements = $anonymized['replacements'];

            // These calls may take a long time depending on prompt complexity.
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            \core\session\manager::write_close();

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
            $client = $this->get_api_client($baseurl, $baseurleu);

            $init = $client->request('POST', '/activity/init', $payload);
            $threadid = is_array($init) ? ($init['thread_id'] ?? null) : null;
            if (!is_string($threadid) || $threadid === '') {
                throw new \moodle_exception('error_unexpected_airesponse', 'local_coursedynamicrules');
            }

            // The graph only advances while the stream is open: consume it until the
            // terminal event. With auto_approve the single pass runs to completion.
            $streamurl = $client->get_base_url() . 'activity/stream/' . rawurlencode($threadid);
            $event = $this->read_activity_stream($streamurl);

            $eventtype = $event['type'] ?? '';
            if ($eventtype === 'failed') {
                // The service localizes event messages as {string_id, string} objects.
                $failmessage = $event['message'] ?? '';
                if (is_array($failmessage)) {
                    $failmessage = $failmessage['string'] ?? json_encode($failmessage);
                }
                throw new \moodle_exception(
                    'error_aiactivity_generation_failed',
                    'local_coursedynamicrules',
                    '',
                    $failmessage
                );
            }

            $resultinfo = ($eventtype === 'completed' && !empty($event['result'])) ? $event['result'] : null;
            if (!is_array($resultinfo)) {
                // The stream may have been cut right at the end: the persisted result
                // is the source of truth for a finished thread.
                $resultinfo = $client->request('GET', '/activity/result/' . rawurlencode($threadid));
            }

            $resultinfo = payload_anonymizer::deanonymize_data($resultinfo, $replacements);

            if (!is_array($resultinfo) || empty($resultinfo['resource_type'])) {
                throw new \moodle_exception('error_unexpected_airesponse', 'local_coursedynamicrules');
            }

            // The service may omit mod_settings for simple module types, but
            // create_mod_service reads the key unconditionally.
            $mode = grade_isolation_service::clean_mode($this->params->grademode ?? null);

            if (isset($resultinfo['parameters']) && is_array($resultinfo['parameters'])) {
                $resultinfo['parameters']['mod_settings'] = $resultinfo['parameters']['mod_settings'] ?? null;
                // Asked BEFORE creation: a module rewrites its own grade item from its own
                // settings on every grade push, so switching grading off afterwards does not hold.
                $resultinfo['parameters'] = grade_isolation_service::prepare_payload(
                    $resultinfo['parameters'],
                    $mode
                );
            }

            // The module form built by create_mod_service reads the current course from the
            // $COURSE global, and building it initialises the page theme, which resets
            // $COURSE to the site when the current page has no course. Under cron both
            // globals point at the site course, so module creation runs against a fresh
            // page bound to the target course, restored afterwards.
            $previouspage = $PAGE;
            $previouscourse = $COURSE;
            $previousoutput = $OUTPUT;
            $PAGE = new \moodle_page();
            $PAGE->set_course($course);
            try {
                $newcm = create_mod_service::create_from_ai_result($resultinfo, $course, $sectionnum, $beforemod);
            } finally {
                $PAGE = $previouspage;
                $COURSE = $previouscourse;
                $OUTPUT = $previousoutput;
            }

            // The marker goes here, the moment the activity exists, and NOT after the work below.
            // It means "an activity is live and the AI call is paid for", not "everything
            // succeeded": anything that throws from here on would otherwise leave no record, and
            // the scheduled tasks - no_complete_activity_task runs every minute - would generate a
            // duplicate live activity and a second paid call on the next pass.
            grade_register_service::record_generation(
                $courseid,
                (int) $this->ruleid,
                (int) $this->get_id(),
                $userid,
                (int) $newcm->coursemodule,
                $mode
            );

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

            $this->isolate_grades($courseid, $newcm, $mode, (int) $userid);
        } catch (\Throwable $e) {
            // The task log keeps a durable record even with debugging off: a failed PAID
            // generation must never be invisible (final-review finding - the same silence this
            // release's changelog criticizes about the 1.8.x breakage).
            mtrace('local_coursedynamicrules createaiactivity failed: ' . $e->getMessage());
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
     * @return int The id of the saved action record.
     */
    public function save_action($formdata) {
        $params = [
            'message' => trim($formdata->message),
            'generateimages' => !empty($formdata->generateimages),
            'sectionnum' => (int) $formdata->sectionnum,
            'beforemod' => empty($formdata->beforemod) ? null : (int) $formdata->beforemod,
            'grademode' => grade_isolation_service::mode_from_choice($formdata->hasgrade ?? 0),
        ];

        return $this->upsert($params, $formdata);
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
        // The WHOLE prompt, never an 80-char teaser: the operator reading this card is reading
        // what the AI service will actually receive (product ask 2026-08-31).
        $prompt = $this->params->message ?? '';

        $data = (object) [
            'section' => $sectionname,
            'prompt' => $prompt,
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

        $description .= ' ' . get_string(
            'createaiactivity_description_grademode',
            'local_coursedynamicrules',
            $this->describe_grademode()
        );

        return $description;
    }

    /**
     * Build the HTTP client for the Datacurso AI course service.
     *
     * Protected factory so tests can inject a double, mirroring the seam used by
     * local_coursegen's web services.
     *
     * @param string|null $baseurl Configured service URL override, or null for the provider default.
     * @param string|null $baseurleu Configured EU service URL override, or null for the provider default.
     * @return ai_course_api
     */
    protected function get_api_client(?string $baseurl, ?string $baseurleu): ai_course_api {
        return new ai_course_api(null, $baseurl, $baseurleu);
    }

    /**
     * Consume the activity generation SSE stream until its terminal event.
     *
     * The v2 service only advances the generation graph while this stream is
     * open, so the connection is held until a "completed" or "failed" event
     * arrives (or STREAM_TIMEOUT expires). Returns the terminal event data,
     * or an empty array when the stream ended without one.
     *
     * @param string $streamurl Absolute URL of the activity stream.
     * @return array
     */
    protected function read_activity_stream(string $streamurl): array {
        $finalevent = [];
        $buffer = '';

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => self::STREAM_TIMEOUT,
            'CURLOPT_HTTPHEADER' => ['Accept: text/event-stream'],
            'CURLOPT_RETURNTRANSFER' => false,
            'CURLOPT_WRITEFUNCTION' => function ($handle, $chunk) use (&$buffer, &$finalevent) {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if (strpos($line, 'data:') !== 0) {
                        continue;
                    }
                    $data = json_decode(trim(substr($line, 5)), true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $type = $data['type'] ?? '';
                    if ($type === 'completed' || $type === 'failed') {
                        $finalevent = $data;
                        // Abort the transfer: the terminal event arrived.
                        return 0;
                    }
                }
                return strlen($chunk);
            },
        ]);

        $curl->get($streamurl);

        return $finalevent;
    }

    /**
     * Return the installed local_coursegen version, or null when it is not installed.
     *
     * @return int|null
     */
    protected function get_coursegen_versiondb(): ?int {
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_coursegen');
        if (!$plugininfo || empty($plugininfo->versiondb)) {
            return null;
        }

        return (int) $plugininfo->versiondb;
    }

    /**
     * Resolve the language to request from the AI service.
     *
     * Under cron the current language is the site default, so the target user's language
     * is preferred, then the course language. The result is normalised to the primary
     * language subtag (e.g. "es_MX" or "es-mx" become "es").
     *
     * @param \stdClass $user Target user of the rule.
     * @param \stdClass $course Course the activity is created in.
     * @return string
     */
    protected function resolve_request_language(\stdClass $user, \stdClass $course): string {
        $candidates = [
            $user->lang ?? '',
            $course->lang ?? '',
            current_language(),
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim(\core_text::strtolower(str_replace('-', '_', (string) $candidate)));
            if ($normalized === '') {
                continue;
            }
            $parts = explode('_', $normalized);
            if ($parts[0] !== '') {
                return $parts[0];
            }
        }

        return 'en';
    }

    /**
     * Keep the generated activity from disturbing the rest of the course's grades.
     *
     * Its own guard, not the outer one: by this point the activity exists, is restricted, and is
     * already recorded, so a gradebook failure must not be reported as "generation failed". It is a
     * distinct fault - the activity is live and IS moving other students' totals - and the operator
     * has to be told that specifically.
     *
     * Protected so a test can make it fail and check that everything before it survives.
     *
     * @param int $courseid
     * @param object $newcm The course module returned by the creation service.
     * @param string $mode One of the grade_isolation_service::MODE_* constants.
     * @param int $recipientuserid The student the activity was generated for. Everybody else in the
     *            course is shielded from its column; only "own grade" spares this one student.
     */
    protected function isolate_grades(int $courseid, $newcm, string $mode, int $recipientuserid = 0): void {
        try {
            $applied = grade_isolation_service::apply(
                $courseid,
                $newcm->modulename,
                (int) $newcm->instance,
                $mode,
                $recipientuserid
            );
            if ($applied < 0) {
                // Gradable items exist and not one gradebook user could be resolved, so no shield
                // was built and the activity IS counting against the whole course. Zero rows
                // written must never read as zero rows needed, and silence here would leave the
                // operator believing the opposite of what the gradebook is doing.
                mtrace('local_coursedynamicrules: generated activity could not be shielded');
                debugging(
                    get_string('error_grade_isolation_failed', 'local_coursedynamicrules', $newcm->modulename),
                    DEBUG_DEVELOPER
                );
            }
        } catch (\Throwable $ge) {
            mtrace('local_coursedynamicrules grade isolation failed: ' . $ge->getMessage());
            debugging(
                get_string('error_grade_isolation_failed', 'local_coursedynamicrules', $ge->getMessage()),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * A human sentence for the chosen grade mode, for the rule listing.
     *
     * @return string
     */
    private function describe_grademode(): string {
        return get_string(
            'createaiactivity_grademode_' . grade_isolation_service::clean_mode($this->params->grademode ?? null),
            'local_coursedynamicrules'
        );
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
