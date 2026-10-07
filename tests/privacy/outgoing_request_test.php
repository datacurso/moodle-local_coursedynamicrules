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

namespace local_coursedynamicrules\privacy;

use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;
use local_coursedynamicrules\tests\wire_capturing_ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/testable_createaiactivity_action.php');
require_once(__DIR__ . '/../fixtures/wire_capturing_ai_course_api.php');

/**
 * What the AI activity action really sends to the Datacurso service, captured on the wire.
 *
 * The other payload tests stop at the client seam: they see what this plugin hands to
 * aiprovider_datacurso, not what leaves the site. The shared transport then merges its own fields
 * into every POST body (datacurso_api_base::send_request()), and its values win. These tests run
 * the real client and capture the body at execute_request(), the last method before cURL, so the
 * assertions below are about the request a network observer would see.
 *
 * The action runs as the administrator, as it does under cron, so a field that silently falls
 * back to the session user shows up as the administrator's.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
 * @covers     \local_coursedynamicrules\local\payload_anonymizer
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outgoing_request_test extends \advanced_testcase {
    /** @var string Prompt naming the student in every way a teacher could, and the course. */
    private const PROMPT = 'Help {$a->fullname} ({$a->firstname}, {$a->lastname}) in {$a->coursename} '
        . 'at {$a->courseurl}. Write to eva.perez@example.com, login evaperez, student number STU-0042.';

    /**
     * Skip when the external AI stack is absent.
     *
     * @return void
     */
    private function require_ai_stack(): void {
        foreach (['aiprovider_datacurso', 'local_coursegen'] as $component) {
            if (!\core_plugin_manager::instance()->get_plugin_info($component)) {
                $this->markTestSkipped($component . ' is not installed; the AI activity action requires it.');
            }
        }
    }

    /**
     * Run the action as the administrator against the wire-capturing client.
     *
     * @param bool $withreview Whether the service stops once for review, so /activity/feedback is sent too.
     * @return array [wire_capturing_ai_course_api $client, \stdClass $student, \stdClass $course]
     */
    private function run_action(bool $withreview = false): array {
        $this->setAdminUser();
        testable_createaiactivity_action::reset();
        wire_capturing_ai_course_api::seed_provider();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Fractions 101']);
        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Eva',
            'lastname' => 'Pérez',
            'email' => 'eva.perez@example.com',
            'username' => 'evaperez',
            'idnumber' => 'STU-0042',
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $client = wire_capturing_ai_course_api::create();
        testable_createaiactivity_action::$client = $client;
        $completed = [
            'type' => 'completed',
            'result' => [
                'action' => 'create',
                'resource_type' => 'page',
                'parameters' => [
                    'modulename' => 'page',
                    'name' => 'AI reinforcement page',
                    'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                    'page' => ['text' => '<p>Reinforcement content</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                    'display' => 5,
                    'printintro' => 0,
                    'printlastmodified' => 1,
                    'visible' => 1,
                    'cmidnumber' => '',
                ],
            ],
        ];
        testable_createaiactivity_action::$streamevents = $withreview
            ? [['type' => 'review_needed', 'message' => 'Plan ready'], $completed]
            : [$completed];

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode([
                'message' => self::PROMPT,
                'generateimages' => true,
                'sectionnum' => 0,
                'beforemod' => null,
            ]),
        ];
        $action = new testable_createaiactivity_action($record, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $student->id]);

        $this->assertCount(1, $client->wire_to('/activity/init'), 'The action sent no /activity/init request.');
        if ($withreview) {
            $this->assertCount(1, $client->wire_to('/activity/feedback'), 'The action sent no /activity/feedback request.');
        }

        return [$client, $student, $course];
    }

    /**
     * The final /activity/init body carries exactly the documented keys, transport fields included.
     *
     * CDR-PRIV-001-R2. instructions, lang, with_images, userid, auto_approve and service_id come from
     * this plugin; site_id, timezone and site_url are added by aiprovider_datacurso's transport. A
     * new key on the wire, from either side, turns this red.
     *
     * @return void
     */
    public function test_the_wire_body_of_activity_init_is_exactly_the_documented_set(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        [$client] = $this->run_action();

        $keys = array_keys($client->wire_to('/activity/init')[0]['body']);
        sort($keys);
        $this->assertSame(
            ['auto_approve', 'instructions', 'lang', 'service_id', 'site_id', 'site_url', 'timezone', 'userid', 'with_images'],
            $keys
        );
    }

    /**
     * The plugin no longer sets site_url, and the site URL still leaves the site.
     *
     * CDR-PRIV-001-R2. Removing site_url from the plugin's payload is declaration hygiene only: the
     * shared transport adds $CFG->wwwroot to every POST body regardless. This test pins both halves,
     * so nobody reads the first one as a data-minimisation gain.
     *
     * @return void
     */
    public function test_site_url_reaches_the_wire_only_through_the_shared_transport(): void {
        global $CFG;
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        [$client] = $this->run_action(true);

        foreach (['/activity/init', '/activity/feedback'] as $path) {
            $this->assertArrayNotHasKey(
                'site_url',
                $client->handed_to($path)[0]['body'],
                "The plugin itself must not put site_url in {$path}."
            );
            $this->assertSame(
                $CFG->wwwroot,
                $client->wire_to($path)[0]['body']['site_url'] ?? null,
                "The shared transport adds site_url to {$path}; if this fails it stopped doing so."
            );
        }
    }

    /**
     * The student's name, email, username, ID number and course URL never reach the wire.
     *
     * CDR-PRIV-001-R2. The prompt names the student through every placeholder and writes the email,
     * username and ID number literally. None of them may be in any byte that leaves the site.
     *
     * @return void
     */
    public function test_the_wire_body_never_contains_the_students_name_email_or_username(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        [$client, $student, $course] = $this->run_action(true);

        // Sanity: the prompt really does carry everything checked below.
        foreach (['eva.perez@example.com', 'evaperez', 'STU-0042', '{$a->fullname}', '{$a->courseurl}'] as $needle) {
            $this->assertStringContainsString($needle, self::PROMPT);
        }

        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $this->assertNotEmpty($client->wire);
        foreach ($client->wire as $call) {
            $raw = (string) $call['raw'];
            foreach (['Eva', 'Pérez', $student->email, $student->username, $student->idnumber] as $value) {
                $this->assertStringNotContainsString($value, $raw, "'{$value}' left the site in {$call['url']}.");
            }
            foreach ($call['body'] as $key => $value) {
                $this->assertStringNotContainsString(
                    $courseurl,
                    (string) json_encode($value, JSON_UNESCAPED_SLASHES),
                    "The course URL left the site in {$key} of {$call['url']}."
                );
            }
        }

        $instructions = $client->wire_to('/activity/init')[0]['body']['instructions'];
        $placeholders = [
            '[STUDENT_NAME]',
            '[STUDENT_FIRSTNAME]',
            '[STUDENT_LASTNAME]',
            '[STUDENT_EMAIL]',
            '[STUDENT_USERNAME]',
            '[STUDENT_IDNUMBER]',
            '[COURSE_URL]',
        ];
        foreach ($placeholders as $placeholder) {
            $this->assertStringContainsString($placeholder, $instructions);
        }
    }

    /**
     * The userid on the wire is the student's, never the user the task runs as.
     *
     * CDR-PRIV-001-R2. Under cron the session user is the administrator, and the transport falls back
     * to $USER->id when the plugin sends no userid. The plugin sends the student's on both requests,
     * so consumption is billed to the student; the test runs as the administrator to prove it.
     *
     * @return void
     */
    public function test_the_wire_userid_is_the_student_not_the_cron_user(): void {
        global $USER;
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        [$client, $student] = $this->run_action(true);

        $this->assertNotEquals((int) $student->id, (int) $USER->id, 'Sanity: the action must run as someone else.');
        foreach (['/activity/init', '/activity/feedback'] as $path) {
            $this->assertSame((string) $student->id, (string) $client->wire_to($path)[0]['body']['userid'], $path);
        }
    }
}
