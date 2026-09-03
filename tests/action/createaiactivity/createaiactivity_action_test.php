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
use local_coursedynamicrules\core\action;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/testable_createaiactivity_action.php');

/**
 * Tests for create AI activity action.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class createaiactivity_action_test extends \advanced_testcase {
    /**
     * Build an action instance from raw params.
     *
     * @param array $params Action params.
     * @param int $courseid Course id.
     * @return createaiactivity_action
     */
    private function create_test_action(array $params, int $courseid): createaiactivity_action {
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode($params),
        ];

        return new createaiactivity_action($record, $courseid);
    }

    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A round-trip create -> edit must persist exactly one row, update the mutated field, leave
     * lastexecutiontime untouched, and map an unselected beforemod (null) to 0.
     *
     * @covers ::save_action
     */
    public function test_save_action_round_trip_persists_single_row_with_beforemod_null_to_zero(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) [
            'id' => null, 'ruleid' => $ruleid, 'actiontype' => 'createaiactivity', 'params' => json_encode([]),
        ];
        $action = new createaiactivity_action($record, $course->id);
        $action->save_action((object) [
            'ruleid' => $ruleid,
            'message' => 'Original prompt',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ]);

        $id = $action->get_id();
        $DB->set_field(action::TABLE, 'lastexecutiontime', 55555, ['id' => $id]);

        $stored = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $storedparams = json_decode($stored->params);

        $reflection = new \ReflectionClass(\local_coursedynamicrules\form\actions\createaiactivity_form::class);
        $forminstance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);
        $defaults = $method->invoke($forminstance, $storedparams);
        $this->assertSame(0, $defaults['beforemod']);

        $editaction = new createaiactivity_action($stored, $course->id);
        $editaction->save_action((object) [
            'ruleid' => $ruleid,
            'message' => 'Updated prompt',
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => null,
        ]);

        $this->assertEquals($id, $editaction->get_id());
        $this->assertEquals(1, $DB->count_records(action::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(action::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(55555, $final->lastexecutiontime);
        $finalparams = json_decode($final->params);
        $this->assertEquals('Updated prompt', $finalparams->message);
        $this->assertTrue($finalparams->generateimages);
        $this->assertNull($finalparams->beforemod);
    }

    /**
     * Test description shows image generation status and the module the activity is inserted before.
     *
     * @covers ::get_description
     */
    public function test_get_description_shows_generateimages_and_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reference assignment',
        ]);

        $prompt = 'Create a reinforcement activity about polynomial factorization with several'
            . ' worked examples and practice questions for struggling students';

        $action = $this->create_test_action([
            'message' => $prompt,
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => $assign->cmid,
        ], $course->id);

        $description = $action->get_description();

        $this->assertStringContainsString(get_section_name($course, 0), $description);
        // Full text, never the 80-char cut: the operator reading the card must see the whole
        // prompt the AI will receive (product ask 2026-08-31 - "que se vea todo sin cortar").
        $this->assertStringContainsString($prompt, $description);
        $this->assertStringContainsString(get_string('yes'), $description);
        $this->assertStringContainsString('Reference assignment', $description);
    }

    /**
     * Test description without image generation and without insert position.
     *
     * @covers ::get_description
     */
    public function test_get_description_without_images_or_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $action = $this->create_test_action([
            'message' => 'Short prompt',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $description = $action->get_description();

        $generateimagesstring = get_string(
            'createaiactivity_description_generateimages',
            'local_coursedynamicrules',
            get_string('no')
        );

        $this->assertStringContainsString($generateimagesstring, $description);
        $this->assertStringNotContainsString(
            get_string('createaiactivity_description_beforemod', 'local_coursedynamicrules', ''),
            $description
        );
    }

    /**
     * Test description does not fail when beforemod points to a deleted module.
     *
     * @covers ::get_description
     */
    public function test_get_description_with_stale_beforemod(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();

        $action = $this->create_test_action([
            'message' => 'Short prompt',
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => 999999,
        ], $course->id);

        $description = $action->get_description();

        $this->assertStringContainsString(get_string('yes'), $description);
        $this->assertStringNotContainsString('999999', $description);
    }

    /**
     * Reset the testable action static seams after every test.
     */
    protected function tearDown(): void {
        testable_createaiactivity_action::reset();
        parent::tearDown();
    }

    /**
     * Build a testable action wired to the given params and course.
     *
     * @param array $params Action params.
     * @param int $courseid Course id.
     * @return testable_createaiactivity_action
     */
    private function create_testable_action(array $params, int $courseid): testable_createaiactivity_action {
        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode($params),
        ];

        return new testable_createaiactivity_action($record, $courseid);
    }

    /**
     * Build an AI client double whose request() records its arguments and returns a canned response.
     *
     * @param array $response Decoded response the double must return.
     * @param array|null $captured Filled with ['method', 'path', 'body'] on the request() call.
     * @param bool $expectcall Whether request() must be called exactly once (never when false).
     * @return ai_course_api
     */
    private function mock_api_client(array $response, ?array &$captured = null, bool $expectcall = true): ai_course_api {
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();

        $client->expects($expectcall ? $this->once() : $this->never())
            ->method('request')
            ->willReturnCallback(function ($method, $path, $body = []) use (&$captured, $response) {
                $captured = ['method' => $method, 'path' => $path, 'body' => $body];
                return $response;
            });

        $client->method('get_base_url')->willReturn('https://ai.example.test/api/v1/');

        return $client;
    }

    /**
     * Canned flat activity result, as the v2 service returns it (no envelope).
     *
     * @param string $name Module name for the generated page.
     * @return array
     */
    private function page_ai_result(string $name = 'AI reinforcement page'): array {
        return [
            'action' => 'create',
            'resource_type' => 'page',
            'parameters' => [
                'modulename' => 'page',
                'name' => $name,
                'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'page' => ['text' => '<p>Reinforcement content</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                'display' => 5,
                'printintro' => 0,
                'printlastmodified' => 1,
                'visible' => 1,
                'cmidnumber' => '',
            ],
        ];
    }

    /**
     * Canned /activity/init response.
     *
     * @return array
     */
    private function init_response(): array {
        return ['thread_id' => 'thread-1', 'status' => 'pending'];
    }

    /**
     * Arm the testable action with a successful init + completed stream event.
     *
     * @param array|null $captured Filled with the init request arguments.
     * @return void
     */
    private function arm_happy_path(?array &$captured = null): void {
        testable_createaiactivity_action::$client = $this->mock_api_client($this->init_response(), $captured);
        testable_createaiactivity_action::$streamevent = [
            'type' => 'completed',
            'result' => $this->page_ai_result(),
        ];
    }

    /**
     * Create a course plus an enrolled student and return both.
     *
     * @return array [course, user]
     */
    private function create_course_and_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        return [$course, $user];
    }

    /**
     * The action must create the module from the AI result envelope, restrict it to the
     * target user and leave it visible.
     *
     * @covers ::execute
     */
    public function test_execute_creates_module_restricted_to_user(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        $this->arm_happy_path();

        $action = $this->create_testable_action([
            'message' => 'Create a page about fractions',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertNotNull(testable_createaiactivity_action::$laststreamurl);
        $this->assertStringContainsString(
            '/activity/stream/thread-1',
            testable_createaiactivity_action::$laststreamurl
        );

        $pages = $DB->get_records('page', ['course' => $course->id]);
        $this->assertCount(1, $pages);
        $page = reset($pages);
        $this->assertSame('AI reinforcement page', $page->name);

        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $this->assertEquals(1, $cm->visible);

        $availability = $DB->get_field('course_modules', 'availability', ['id' => $cm->id]);
        $this->assertNotEmpty($availability);
        $this->assertStringContainsString('"type":"user"', $availability);
        $this->assertStringContainsString((string) $user->id, $availability);
    }

    /**
     * The /activity/init payload must carry the v2 contract (instructions, with_images,
     * userid, site_url, auto_approve, service_id), anonymize the instructions, drop the
     * legacy keys, and hit the configured service URL.
     *
     * @covers ::execute
     */
    public function test_execute_sends_expected_init_payload(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        set_config('datacurso_service_url', 'https://svc.example.test', 'local_coursegen');

        [$course, $user] = $this->create_course_and_student();

        $captured = null;
        $this->arm_happy_path($captured);

        $action = $this->create_testable_action([
            'message' => 'Help {$a->firstname} with fractions',
            'generateimages' => true,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertNotNull($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('/activity/init', $captured['path']);

        $payload = $captured['body'];
        $this->assertSame('Help [STUDENT_FIRSTNAME] with fractions', $payload['instructions']);
        $this->assertSame((string) $user->id, $payload['userid']);
        $this->assertTrue($payload['with_images']);
        $this->assertSame('en', $payload['lang']);
        $this->assertSame($CFG->wwwroot, $payload['site_url']);
        $this->assertTrue($payload['auto_approve']);
        $this->assertSame('local_coursedynamicrules', $payload['service_id']);
        $this->assertArrayNotHasKey('message', $payload);
        $this->assertArrayNotHasKey('generate_images', $payload);
        $this->assertArrayNotHasKey('course_id', $payload);
        $this->assertArrayNotHasKey('model_name', $payload);
        $this->assertArrayNotHasKey('context_type', $payload);

        $this->assertSame('https://svc.example.test', testable_createaiactivity_action::$lasturls['baseurl']);
    }

    /**
     * The v2 service has no channel for the system instruction context: the payload
     * must not carry legacy context keys and the instructions must stay untouched.
     *
     * @covers ::execute
     */
    public function test_execute_ignores_system_instruction_context(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        $instructionid = $DB->insert_record('local_coursegen_system_instruction', (object) [
            'name' => 'Math tutor',
            'content' => 'Act as a math tutor.',
            'deleted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => get_admin()->id,
        ]);
        $DB->insert_record('local_coursegen_course_context', (object) [
            'courseid' => $course->id,
            'context_type' => \local_coursegen\ai_context::CONTEXT_TYPE_SYSTEM_INSTRUCTION,
            'system_instruction_id' => $instructionid,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => get_admin()->id,
        ]);

        $captured = null;
        $this->arm_happy_path($captured);

        $action = $this->create_testable_action([
            'message' => 'Create a reinforcement page',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $payload = $captured['body'];
        $this->assertSame('Create a reinforcement page', $payload['instructions']);
        $this->assertArrayNotHasKey('context_type', $payload);
        $this->assertArrayNotHasKey('system_instruction_name', $payload);
        $this->assertArrayNotHasKey('prompt_text', $payload);
    }

    /**
     * A custom prompt AI context is inlined as a preamble of the instructions,
     * the only context channel the v2 service supports.
     *
     * @covers ::execute
     */
    public function test_execute_inlines_custom_prompt_context(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        $DB->insert_record('local_coursegen_course_context', (object) [
            'courseid' => $course->id,
            'context_type' => \local_coursegen\ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
            'prompt_text' => '<p>Focus on the basics.</p>',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => get_admin()->id,
        ]);

        $captured = null;
        $this->arm_happy_path($captured);

        $action = $this->create_testable_action([
            'message' => 'Create a reinforcement page',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $payload = $captured['body'];
        $this->assertStringStartsWith('Focus on the basics.', $payload['instructions']);
        $this->assertStringContainsString('Create a reinforcement page', $payload['instructions']);
        $this->assertArrayNotHasKey('context_type', $payload);
        $this->assertArrayNotHasKey('prompt_text', $payload);
    }

    /**
     * An init response without a thread id must not open the stream nor create anything.
     *
     * @covers ::execute
     */
    public function test_execute_skips_creation_when_init_has_no_thread(): void {
        // The failure now reaches the task log by contract (final-review observability fix):
        // this asserts the mtrace happens - delete it and this test names the regression.
        $this->expectOutputRegex('/createaiactivity failed/');
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        testable_createaiactivity_action::$client = $this->mock_api_client(['ok' => true]);

        $action = $this->create_testable_action([
            'message' => 'Create a page about fractions',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalledCount(1);
        $this->assertNull(testable_createaiactivity_action::$laststreamurl);
        $this->assertEquals(0, $DB->count_records('page', ['course' => $course->id]));
    }

    /**
     * A failed stream event must be reported without touching the result endpoint
     * and without creating anything.
     *
     * @covers ::execute
     */
    public function test_execute_skips_creation_when_stream_fails(): void {
        // The failure now reaches the task log by contract (final-review observability fix):
        // this asserts the mtrace happens - delete it and this test names the regression.
        $this->expectOutputRegex('/createaiactivity failed/');
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        testable_createaiactivity_action::$client = $this->mock_api_client($this->init_response());
        testable_createaiactivity_action::$streamevent = [
            'type' => 'failed',
            // The service localizes event messages as {string_id, string} objects.
            'message' => [
                'string_id' => 'stream_generic_error',
                'string' => 'We could not complete your request right now.',
            ],
            'code' => 'stream_error',
            'retriable' => false,
        ];

        $action = $this->create_testable_action([
            'message' => 'Create a page about fractions',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalledCount(1);
        $this->assertEquals(0, $DB->count_records('page', ['course' => $course->id]));
    }

    /**
     * An outdated coursegen install must be reported without calling the AI service.
     *
     * @covers ::execute
     */
    public function test_execute_reports_outdated_coursegen(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $user] = $this->create_course_and_student();

        testable_createaiactivity_action::$client = $this->mock_api_client([], $captured, false);
        testable_createaiactivity_action::$coursegenversiondb = 2025112000;

        $action = $this->create_testable_action([
            'message' => 'Create a page about fractions',
            'generateimages' => false,
            'sectionnum' => 0,
            'beforemod' => null,
        ], $course->id);

        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertDebuggingCalledCount(1);
        $this->assertEquals(0, $DB->count_records('page', ['course' => $course->id]));
    }
}
