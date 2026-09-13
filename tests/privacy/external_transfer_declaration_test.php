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

use aiprovider_datacurso\httpclient\ai_course_api;
use core_privacy\local\metadata\collection;
use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/testable_createaiactivity_action.php');

/**
 * Couples the Privacy API declaration to the payload the plugin actually sends.
 *
 * provider_test.php already asserts that every declared field resolves to a language string. That
 * is a check on the STRINGS, not on the FIELDS: a declaration can name fields the service never
 * receives, and omit fields it does, while every string resolves and the suite stays green. That
 * is exactly how the declaration drifted away from the payload once the action moved from
 * /smartrules/create-mod to /activity/init.
 *
 * These tests close the loop in both directions by capturing what this plugin hands to the AI
 * client and comparing its keys against the declaration - so renaming a payload key here without
 * touching provider.php turns the suite red instead of silently misdeclaring the transfer.
 *
 * KNOW WHAT THIS DOES NOT COVER, because an earlier version of this docblock claimed it did. The
 * capture point is the client seam, which is ABOVE the wire: aiprovider_datacurso's transport
 * merges its own fields into every POST body before sending it
 * (aiprovider_datacurso\httpclient\datacurso_api_base::send_request(), the $defaultpayload array on
 * the POST branch, currently site_id and timezone). Those keys never pass through the seam this
 * test stubs, so nothing here can see them, and no assertion below should be read as a statement
 * about them. They are added by that plugin's transport, so declaring them is that plugin's
 * responsibility - the same reasoning that keeps core's messaging fields out of this plugin's
 * declaration. But responsibility is not coverage: as of this writing aiprovider_datacurso's own
 * provider declares prompt, numberimages and userid only, so today NOBODY declares site_id or
 * timezone. (timezone is the timezone of the account the request runs as; the action only ever
 * runs from an adhoc task, so that is the cron account's, not the student's.)
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_transfer_declaration_test extends \advanced_testcase {
    /**
     * @var string[] Contractual payload keys handed to the AI client at this seam.
     */
    private const PAYLOAD_CONTRACT_KEYS = [
        'instructions',
        'lang',
        'with_images',
        'userid',
        'site_url',
        'auto_approve',
        'service_id',
    ];

    /**
     * @var string[] Operational controls that are not declared as transferred data fields.
     *
     * Each entry is a deliberate exclusion from the metadata comparison, not an oversight. This
     * test records the plugin's classification; it does not establish a legal classification.
     *
     * - with_images:  configured at action level and may vary between actions.
     * - auto_approve: always true - rules run unattended from cron, so nobody can approve a plan.
     * - service_id:   the billing identity of the calling plugin.
     *
     * A payload key that is NOT here and NOT declared fails the test on purpose: adding one forces
     * whoever adds it to decide explicitly whether the metadata declaration must include it.
     */
    private const OPERATIONAL_CONTROL_KEYS = ['with_images', 'auto_approve', 'service_id'];

    /**
     * Skip when the external AI stack is absent: without it the action returns before building a
     * payload, so there would be nothing to compare the declaration against.
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
     * Run the AI activity action against a client double and return the captured request body.
     *
     * @return array The payload sent to /activity/init.
     */
    private function capture_init_payload(): array {
        $this->setAdminUser();
        set_config('datacurso_service_url', 'https://svc.example.test', 'local_coursegen');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $captured = null;

        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();
        $client->method('request')
            ->willReturnCallback(function ($method, $path, $body = []) use (&$captured) {
                // Only the init call carries a body; the later result GET must not overwrite it.
                if ($method === 'POST' && $path === '/activity/init') {
                    $captured = $body;
                }
                return ['thread_id' => 'thread-1', 'status' => 'pending'];
            });
        $client->method('get_base_url')->willReturn('https://ai.example.test/api/v1/');

        testable_createaiactivity_action::$client = $client;
        testable_createaiactivity_action::$streamevent = [
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

        $record = (object) [
            'id' => 1,
            'ruleid' => 1,
            'actiontype' => 'createaiactivity',
            'params' => json_encode([
                // Every placeholder the prompt builder substitutes, so a field that only reaches
                // the service through the prompt is exercised too.
                'message' => 'Help {$a->fullname} ({$a->firstname} {$a->lastname}) '
                    . 'in {$a->coursename} at {$a->courseurl} with fractions',
                'generateimages' => true,
                'sectionnum' => 0,
                'beforemod' => null,
            ]),
        ];

        $action = new testable_createaiactivity_action($record, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertIsArray($captured, 'The action sent no /activity/init request to compare against.');

        return $captured;
    }

    /**
     * Return the fields declared for the external AI location.
     *
     * @return string[] Declared field names.
     */
    private function declared_fields(): array {
        $items = provider::get_metadata(new collection('local_coursedynamicrules'))->get_collection();

        foreach ($items as $item) {
            if ($item->get_name() === 'datacurso_ai') {
                return array_keys($item->get_privacy_fields());
            }
        }

        $this->fail('The provider declares no datacurso_ai external location.');
    }

    /**
     * The client seam has an explicit key contract, so coordinated drift requires a contract update.
     *
     * @return void
     */
    public function test_payload_keys_match_explicit_contract(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        $actual = array_keys($this->capture_init_payload());
        $expected = self::PAYLOAD_CONTRACT_KEYS;
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * A declared field the service never receives misdescribes the transfer, so it must not exist.
     *
     * @return void
     */
    public function test_every_declared_field_is_really_sent(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        $payload = $this->capture_init_payload();
        $declared = $this->declared_fields();

        $phantom = array_diff($declared, array_keys($payload));

        $this->assertSame(
            [],
            array_values($phantom),
            'provider.php declares fields that are absent from the /activity/init payload: '
                . implode(', ', $phantom)
        );
    }

    /**
     * A field not classified as an operational control must be present in the declaration.
     *
     * @return void
     */
    public function test_every_non_operational_field_sent_is_declared(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        $payload = $this->capture_init_payload();
        $declared = $this->declared_fields();

        $undeclared = array_diff(array_keys($payload), $declared, self::OPERATIONAL_CONTROL_KEYS);

        $this->assertSame(
            [],
            array_values($undeclared),
            'The /activity/init payload carries fields that provider.php does not declare: '
                . implode(', ', $undeclared)
        );
    }
}
