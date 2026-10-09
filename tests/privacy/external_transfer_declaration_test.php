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

use core_privacy\local\metadata\collection;
use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;
use local_coursedynamicrules\tests\wire_capturing_ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/testable_createaiactivity_action.php');
require_once(__DIR__ . '/../fixtures/wire_capturing_ai_course_api.php');

/**
 * Couples the Privacy API declaration to the requests the plugin actually sends.
 *
 * provider_test.php already asserts that every declared field resolves to a language string. That
 * is a check on the STRINGS, not on the FIELDS: a declaration can name fields the service never
 * receives, and omit fields it does, while every string resolves and the suite stays green. That
 * is exactly how the declaration drifted away from the payload once the action moved from
 * /smartrules/create-mod to /activity/init.
 *
 * These tests close the loop in both directions, for both requests that carry a body
 * (/activity/init and the /activity/feedback approval), at two points:
 *
 * - the client seam, where this plugin hands its payload to aiprovider_datacurso. The key sets
 *   there are pinned, so a payload change here forces a contract update;
 * - the wire, captured at datacurso_api_base::execute_request() through
 *   wire_capturing_ai_course_api, after the shared transport has merged its own fields (site_id,
 *   userid, timezone, lang, site_url) into the body. The declaration is compared with THIS body,
 *   because it is what leaves the site. Until CDR-PRIV-001-R2 the comparison stopped at the seam
 *   and could not see the transport's fields at all.
 *
 * The transport's fields are declared by aiprovider_datacurso as its own external location as
 * well (from 2026090700, release 1.5.1). This plugin declares them too, because they travel in
 * requests it makes, and its strings say who adds them.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_transfer_declaration_test extends \advanced_testcase {
    /**
     * @var string[] Contractual /activity/init keys handed to the AI client at the seam.
     *
     * site_url is not one of them since CDR-PRIV-001-R2: the transport adds it anyway.
     */
    private const PAYLOAD_CONTRACT_KEYS = [
        'instructions',
        'lang',
        'with_images',
        'userid',
        'auto_approve',
        'service_id',
    ];

    /**
     * @var string[] Contractual /activity/feedback keys handed to the AI client at the seam.
     */
    private const FEEDBACK_CONTRACT_KEYS = [
        'thread_id',
        'approval_status',
        'instruction',
        'userid',
    ];

    /**
     * @var string[] Operational controls that are not declared as transferred data fields.
     *
     * Each entry is a deliberate exclusion from the metadata comparison, not an oversight. This
     * test records the plugin's classification; it does not establish a legal classification.
     *
     * - with_images:     configured at action level and may vary between actions.
     * - auto_approve:    always true - rules run unattended from cron, so nobody can approve a plan.
     * - service_id:      the billing identity of the calling plugin.
     * - thread_id:       the opaque generation id the service itself issued in its init response.
     * - approval_status: always 'accept' - the plan is approved on the student's behalf.
     * - instruction:     always empty - no free text accompanies the approval.
     *
     * A key that is NOT here and NOT declared fails the test on purpose: adding one forces
     * whoever adds it to decide explicitly whether the metadata declaration must include it.
     */
    private const OPERATIONAL_CONTROL_KEYS = [
        'with_images',
        'auto_approve',
        'service_id',
        'thread_id',
        'approval_status',
        'instruction',
    ];

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
     * Run the AI activity action through a plan review and capture both requests.
     *
     * @return array ['handed' => [path => body], 'wire' => [path => body]] for /activity/init and
     *     /activity/feedback.
     */
    private function capture_requests(): array {
        $this->setAdminUser();
        testable_createaiactivity_action::reset();
        wire_capturing_ai_course_api::seed_provider();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $client = wire_capturing_ai_course_api::create();
        testable_createaiactivity_action::$client = $client;
        testable_createaiactivity_action::$streamevents = [
            ['type' => 'review_needed', 'message' => 'Plan ready'],
            [
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

        $captured = ['handed' => [], 'wire' => []];
        foreach (['/activity/init', '/activity/feedback'] as $path) {
            $handed = $client->handed_to($path);
            $wire = $client->wire_to($path);
            $this->assertCount(1, $handed, "The action handed no {$path} request to the client.");
            $this->assertCount(1, $wire, "No {$path} request reached the wire.");
            $captured['handed'][$path] = $handed[0]['body'];
            $captured['wire'][$path] = $wire[0]['body'];
        }

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

        $actual = array_keys($this->capture_requests()['handed']['/activity/init']);
        $expected = self::PAYLOAD_CONTRACT_KEYS;
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * A declared field the service never receives misdescribes the transfer, so it must not exist.
     *
     * Compared with the union of both wire bodies: a field is really sent when either request
     * carries it out of the site.
     *
     * @return void
     */
    public function test_every_declared_field_is_really_sent(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        $wire = $this->capture_requests()['wire'];
        $sent = array_merge(array_keys($wire['/activity/init']), array_keys($wire['/activity/feedback']));

        $phantom = array_diff($this->declared_fields(), $sent);

        $this->assertSame(
            [],
            array_values($phantom),
            'provider.php declares fields that are absent from every request on the wire: ' . implode(', ', $phantom)
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

        $wire = $this->capture_requests()['wire']['/activity/init'];
        $undeclared = array_diff(array_keys($wire), $this->declared_fields(), self::OPERATIONAL_CONTROL_KEYS);

        $this->assertSame(
            [],
            array_values($undeclared),
            'The /activity/init body on the wire carries fields that provider.php does not declare: '
                . implode(', ', $undeclared)
        );
    }

    /**
     * The plan approval is a transfer too, so it is held to the same contract as the init request.
     *
     * CDR-PRIV-001-R2. /activity/feedback carries the student's id and, through the transport, the
     * site's identifiers and the session's time zone; until this test only /activity/init was
     * compared with the declaration.
     *
     * @return void
     */
    public function test_feedback_request_is_covered_by_the_declaration(): void {
        $this->require_ai_stack();
        $this->resetAfterTest(true);

        $captured = $this->capture_requests();

        $handed = array_keys($captured['handed']['/activity/feedback']);
        $expected = self::FEEDBACK_CONTRACT_KEYS;
        sort($handed);
        sort($expected);
        $this->assertSame($expected, $handed);

        $undeclared = array_diff(
            array_keys($captured['wire']['/activity/feedback']),
            $this->declared_fields(),
            self::OPERATIONAL_CONTROL_KEYS
        );
        $this->assertSame(
            [],
            array_values($undeclared),
            'The /activity/feedback body on the wire carries fields that provider.php does not declare: '
                . implode(', ', $undeclared)
        );
    }
}
