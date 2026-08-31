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

namespace local_coursedynamicrules\action\sendnotification;

use context_course;
use html_writer;
use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\actions\sendnotification_form;
use moodle_url;
use stdClass;

/**
 * Class sendnotification_action
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sendnotification_action extends action {
    /**
     * Every `params` key that has ever held a list of recipient role ids, current and legacy.
     *
     * resolve_roleids() applies a precedence among them to decide who is notified; anything that
     * must treat these ids AS ids - the backup annotating them, the restore remapping them - has to
     * cover them ALL instead, or an action stored in an older shape keeps role ids nobody remapped.
     *
     * @var string[]
     */
    public const ROLE_PARAM_KEYS = [
        'primaryroleids',
        'observedroleids',
        'roleids',
        'copyroleids',
        'observerroleids',
    ];

    /** @var string type of the action, should be overridden by each action type */
    protected $type = 'sendnotification';

    /** @var string related user id to the event */

    /**
     * @var array<int, string> Memoised formatted message body, keyed by course id (micro-sweep):
     *      a bare "already computed" flag would silently return the WRONG course's formatted body
     *      if this action instance were ever reused for execute() calls against different courses.
     */
    private $cachedmessagebodies = [];

    /**
     * Executes the action
     * @param object $rulecontext Context of the rule
     *
     * @return mixed
     */
    public function execute($rulecontext) {
        global $DB;

        $userid = $rulecontext->userid;
        $courseid = $rulecontext->courseid;

        $messagesubject = $this->params->messagesubject;
        $messagebody = $this->get_formatted_messagebody($courseid);
        $roleids = self::resolve_roleids($this->params);
        $primaryroleids = $roleids['primary'];
        $copyroleids = $roleids['copy'];

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $coursecontext = context_course::instance($course->id);

        if (empty($primaryroleids)) {
            return false;
        }

        $userroles = get_user_roles($coursecontext, $userid, false);
        $isobserveduser = false;
        foreach ($userroles as $userrole) {
            if (in_array($userrole->roleid, $primaryroleids)) {
                $isobserveduser = true;
                break;
            }
        }

        if (!$isobserveduser) {
            return false;
        }

        $messagebody = $this->replace_placeholders($messagebody, $course, $user);
        $smallmessagehtml = html_entity_decode($messagebody, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        $smallmessagetext = $this->sanitize_html_message_twilio($smallmessagehtml);
        $messageids = [];

        $message = $this->create_message($userid, $messagesubject, $messagebody, $smallmessagetext);
        $messageids[] = message_send($message);

        $recipients = $this->get_recipients_by_roles($copyroleids, $coursecontext);
        unset($recipients[$userid]);

        if (!empty($recipients)) {
            $observersubject = $this->build_observer_subject($messagesubject, $user);
            $observermessagebody = $this->build_observer_message_body($messagebody, $user);
            $observersmallmessagehtml = html_entity_decode($observermessagebody, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
            $observersmallmessagetext = $this->sanitize_html_message_twilio($observersmallmessagehtml);

            foreach ($recipients as $recipient) {
                $message = $this->create_message(
                    $recipient->id,
                    $observersubject,
                    $observermessagebody,
                    $observersmallmessagetext
                );
                $messageids[] = message_send($message);
            }
        }

        if (empty($messageids)) {
            return false;
        }

        return $messageids;
    }

    /**
     * Builds a subject for observer recipients.
     *
     * @param string   $messagesubject Notification subject configured in the action.
     * @param stdClass $user User who matched the rule condition.
     * @return string
     */
    private function build_observer_subject(string $messagesubject, stdClass $user): string {
        $params = (object) [
            'fullname' => fullname($user),
            'subject' => $messagesubject,
        ];

        return get_string('observer_notification_subject', 'local_coursedynamicrules', $params);
    }

    /**
     * Builds message body for observer recipients.
     *
     * @param string   $messagebody Notification body already replaced for matched user.
     * @param stdClass $user User who matched the rule condition.
     * @return string
     */
    private function build_observer_message_body(string $messagebody, stdClass $user): string {
        $intro = get_string('observer_notification_intro', 'local_coursedynamicrules', fullname($user));

        return html_writer::tag('p', $intro) . $messagebody;
    }

    /**
     * Resolve the message body to send, computed once per action instance (FIX2-5) rather than
     * once per matched user: rule::execute_actions() calls execute() on the SAME action instance
     * for every user matched in a single rule run, so memoising here means format_text() runs
     * exactly once per rule run, not once per matched user.
     *
     * Honours the raw-vs-legacy invariant (FIX2-6): rows saved by save_action() (this version
     * onward) carry params->bodyisraw, meaning the stored value is the RAW submitted editor text -
     * format_text() is applied here, at send time, with the course context (instead of silently
     * falling back to $PAGE->context = system, which cron execution would otherwise hit). Rows
     * saved before this marker existed stored the ALREADY-formatted body, so they are sent
     * verbatim: running format_text() on them again would be a second, unwanted filter pass.
     *
     * @param int $courseid Course id, used as the format_text() context for raw rows.
     * @return string
     */
    private function get_formatted_messagebody(int $courseid): string {
        if (!array_key_exists($courseid, $this->cachedmessagebodies)) {
            $raw = $this->params->messagebody ?? '';
            if (!empty($this->params->bodyisraw)) {
                $formatted = format_text($raw, FORMAT_HTML, ['context' => context_course::instance($courseid)]);
            } else {
                // Legacy row without the marker: already formatted at save time by an older
                // version - send verbatim, never re-format.
                $formatted = $raw;
            }
            $this->cachedmessagebodies[$courseid] = $formatted;
        }

        return $this->cachedmessagebodies[$courseid];
    }

    /**
     * Creates and returns an instance of the form for editing the item
     *
     * @param mixed $action the action attribute for the form. If empty defaults to auto detect the
     *              current url. If a moodle_url object then outputs params as hidden variables.
     * @param mixed $customdata if your form defintion method needs access to data such as $course
     *              $cm, etc. to construct the form definition then pass it in this array. You can
     *              use globals for somethings.
     * @param string $method if you set this to anything other than 'post' then _GET and _POST will
     *               be merged and used as incoming data to the form.
     * @param string $target target frame for form submission. You will rarely use this. Don't use
     *               it if you don't need to as the target attribute is deprecated in xhtml strict.
     * @param mixed $attributes you can pass a string of html attributes here or an array.
     *               Special attribute 'data-random-ids' will randomise generated elements ids. This
     *               is necessary when there are several forms on the same page.
     *               Special attribute 'data-double-submit-protection' set to 'off' will turn off
     *               double-submit protection JavaScript - this may be necessary if your form sends
     *               downloadable files in response to a submit button, and can't call
     *               \core_form\util::form_download_complete();
     * @param bool $editable
     * @param array $ajaxformdata Forms submitted via ajax, must pass their data here, instead of relying on _GET and _POST.
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
        $this->actionform = new sendnotification_form(
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
     * Saves the action after it has been edited (or created)
     * @param object $formdata
     * @return int The id of the saved action record.
     */
    public function save_action($formdata) {
        $primaryrecipients = $formdata->primaryrecipients ?? [];
        $copyrecipients = $formdata->copyrecipients ?? [];
        $primaryroleids = array_keys($primaryrecipients, 1);
        $copyroleids = array_keys($copyrecipients, 1);

        // FIX3-5: preserve the LOADED record's raw/legacy marker instead of unconditionally
        // stamping bodyisraw => true. A brand-new row (no id yet) has nothing to preserve and is
        // always raw. An EXISTING row keeps being raw only if it already was; a legacy row (no
        // marker at all) stays unmarked on edit too, so get_formatted_messagebody() keeps sending
        // it verbatim instead of double-filtering it (the bug this fix closes: re-saving a legacy
        // row used to stamp bodyisraw => true, causing it to be format_text()'d a SECOND time at
        // send).
        $isnew = empty($this->get_id());
        $wasraw = $isnew || !empty($this->params->bodyisraw);

        $params = [
            'messagesubject' => $formdata->messagesubject,
            // Store the RAW submitted editor text (G6): formatting is applied once, at render/send
            // time (see get_formatted_messagebody()), instead of at every save, so re-editing and
            // re-saving without any change cannot progressively reformat (and eventually corrupt)
            // the stored body. FIX3-4: purify it with clean_text() (the same purifier format_text()
            // uses) before storing - re-opening this action's own edit form materialises the stored
            // value inside the WYSIWYG unescaped, so an unpurified payload here is an editor XSS /
            // privilege-escalation sink. clean_text() is idempotent, so this does not conflict with
            // the "store raw, format once at send" invariant below: re-editing and re-saving without
            // changes still leaves the stored body byte-identical.
            'messagebody' => clean_text($formdata->messagebody['text'], FORMAT_HTML),
            'primaryroleids' => $primaryroleids,
            'copyroleids' => $copyroleids,
        ];

        // INVARIANT (FIX2-6, refined by FIX3-5): 'bodyisraw' marks this row as "raw at rest,
        // formatted at render" - get_formatted_messagebody() only calls format_text() when this
        // marker is present, so a legacy row saved by an older version (which stored the
        // ALREADY-formatted body, no marker) is sent verbatim instead of being filtered twice, even
        // across an edit.
        if ($wasraw) {
            $params['bodyisraw'] = true;
        }

        return $this->upsert($params, $formdata);
    }

    /**
     * Resolve primary/copy role ids from stored params, honouring legacy key fallbacks.
     *
     * Shared by execute(), get_description() and sendnotification_form::preload_defaults() so the
     * legacy fallback chain (observedroleids/roleids/observerroleids) lives in exactly one place.
     *
     * @param object $params Decoded stored params.
     * @return array{primary: array, copy: array}
     */
    public static function resolve_roleids($params): array {
        return [
            'primary' => $params->primaryroleids
                ?? $params->observedroleids
                ?? $params->roleids
                ?? [],
            'copy' => $params->copyroleids
                ?? $params->observerroleids
                ?? [],
        ];
    }

    /**
     * Returns the description of the action to visualization
     *
     * @return string
     */
    public function get_description() {
        $messagesubject = $this->params->messagesubject ?? '';
        $subjectpart = get_string('sendnotification_description', 'local_coursedynamicrules', $messagesubject);

        $roleids = self::resolve_roleids($this->params);
        $primaryroleids = $roleids['primary'];
        $copyroleids = $roleids['copy'];

        $coursecontext = context_course::instance($this->courseid);
        $rolenames = role_get_names($coursecontext, ROLENAME_ALIAS, true);

        $messagebody = $this->params->messagebody ?? '';
        $shortbody = shorten_text(trim(html_to_text($messagebody, 0, false)), 80);

        $details = get_string('sendnotification_description_details', 'local_coursedynamicrules', (object) [
            'primaryroles' => $this->get_role_names_string($primaryroleids, $rolenames),
            'copyroles' => $this->get_role_names_string($copyroleids, $rolenames),
            'body' => $shortbody,
        ]);

        return $subjectpart . ' ' . $details;
    }

    /**
     * Builds a comma separated list of role names for the given role ids.
     *
     * @param array $roleids Role ids configured in the action.
     * @param array $rolenames Localised role names indexed by role id.
     * @return string
     */
    private function get_role_names_string($roleids, $rolenames) {
        $names = [];
        foreach ((array) $roleids as $roleid) {
            if (isset($rolenames[$roleid])) {
                $names[] = $rolenames[$roleid];
            }
        }

        return $names ? implode(', ', $names) : get_string('none');
    }

    /**
     * Sanitizes an HTML message for Twilio.
     *
     * This function takes an HTML message as input and returns a sanitized string
     * that is safe to be sent via Twilio.
     *
     * @param string $html The HTML message to be sanitized.
     * @return string The sanitized message.
     */
    protected function sanitize_html_message_twilio($html): string {
        // Check if the HTML message is empty or invalid.
        if (empty($html) || !is_string($html)) {
            return '';
        }

        // Convert all <a> tags to plain text.
        $html = preg_replace_callback('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', function ($matches) {
            // We return only the URL, we don't need the text.
            return $matches[1] . ' ';
        }, $html);

        // Remove all HTML tags.
        $text = strip_tags($html);

        // Remove all line breaks.
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n+/', ' ', $text);

        // Decode HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return $text;
    }

    /**
     * Replaces message body placeholders with course and user data.
     *
     * Supported placeholders:
     * - {$a->coursename}
     * - {$a->courselink}
     * - {$a->fullname}
     * - {$a->firstname}
     * - {$a->lastname}
     *
     * @param string   $messagebody HTML/text content containing placeholders.
     * @param stdClass $course      Course record (requires at least id, fullname).
     * @param stdClass $user        Target user (requires at least firstname, lastname).
     * @return string  Message with placeholders replaced.
     */
    private function replace_placeholders($messagebody, $course, $user): string {
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $courselink = html_writer::link($courseurl, $course->fullname);
        $key = ['{$a-&gt;coursename}', '{$a-&gt;courselink}', '{$a-&gt;fullname}', '{$a-&gt;firstname}', '{$a-&gt;lastname}'];
        $value = [$course->fullname, $courselink, fullname($user), $user->firstname, $user->lastname];
        return str_replace($key, $value, $messagebody);
    }

    /**
     * Retrieves recipient users by role within the course context.
     *
     * @param int[]          $roleids Role IDs to include.
     * @param context_course $coursecontext Course context.
     * @return stdClass[] Users indexed by user id.
     */
    private function get_recipients_by_roles($roleids, $coursecontext): array {
        $recipients = [];
        foreach ($roleids as $roleid) {
            $userswithrole = get_role_users($roleid, $coursecontext, false, 'u.*', 'u.id ASC', false);
            if (!empty($userswithrole)) {
                foreach ($userswithrole as $user) {
                    $recipients[$user->id] = $user;
                }
            }
        }

        return $recipients;
    }

    /**
     * Builds the message object for Moodle's messaging API.
     *
     * @param int    $recipientid Recipient user id.
     * @param string $subject     Message subject.
     * @param string $fullhtml    Message body in HTML.
     * @param string $smalltext   Short/plain-text summary.
     * @return \core\message\message Message ready to send with message_send().
     */
    private function create_message($recipientid, $subject, $fullhtml, $smalltext): \core\message\message {
        $message = new \core\message\message();
        $message->component = 'local_coursedynamicrules';
        $message->name = 'smart_rules_ai_notification';
        $message->userfrom = \core_user::get_support_user();
        $message->userto = $recipientid;
        $message->subject = $subject;
        $message->fullmessage = html_to_text($fullhtml);
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessagehtml = $fullhtml;
        $message->smallmessage = $smalltext;
        $message->notification = 1;
        return $message;
    }
}
