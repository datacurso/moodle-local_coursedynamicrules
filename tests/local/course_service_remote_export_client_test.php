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

namespace local_coursedynamicrules\local;

use local_coursedynamicrules\tests\wire_capturing_ai_course_api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/wire_capturing_ai_course_api.php');

/**
 * The production remote export client asks the course service for what it keeps about a student.
 *
 * The real Datacurso course client runs, cut off from the network at its cURL boundary
 * (wire_capturing_ai_course_api), so the body asserted is the one that would leave the site.
 *
 * CDR-PRIV-001-R2 follow-up.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\course_service_remote_export_client
 * @covers     \local_coursedynamicrules\hook\di_callbacks
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_service_remote_export_client_test extends \advanced_testcase {
    /** @var string The export endpoint, relative to the course service base URL. */
    private const PATH = '/privacy/export';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        if (!\core_plugin_manager::instance()->get_plugin_info('aiprovider_datacurso')) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the course service client requires it.');
        }
    }

    /**
     * Build the production client over a wire-capturing course client.
     *
     * @param array|null $response What the service answers on the export endpoint.
     * @return array [course_service_remote_export_client $export, wire_capturing_ai_course_api $wire]
     */
    private function client(?array $response = null): array {
        wire_capturing_ai_course_api::seed_provider();
        $wire = wire_capturing_ai_course_api::create();
        $wire->responses[self::PATH] = $response ?? $this->exported('thread-1');
        $export = new course_service_remote_export_client(fn() => $wire);
        return [$export, $wire];
    }

    /**
     * An answer of the service carrying one request, one usage row and one conversation of a thread.
     *
     * @param string $threadid The thread.
     * @return array
     */
    private function exported(string $threadid): array {
        return [
            'status' => 'exported',
            'data' => [
                'requests' => [[
                    'thread_id' => $threadid,
                    'request_type' => 'ACTIVITY',
                    'status' => 'COMPLETED',
                    'request_config' => ['lang' => 'en'],
                    'final_result' => ['name' => 'AI page'],
                    'created_at' => '2026-11-10T10:00:00Z',
                    'updated_at' => '2026-11-10T10:01:00Z',
                ]],
                'token_usage' => [[
                    'thread_id' => $threadid,
                    'action' => 'create_mod',
                    'step' => 'Plan',
                    'model_name' => 'model-x',
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                    'thoughts_token_count' => 0,
                    'total_tokens' => 30,
                    'cached_content_token_count' => null,
                    'created_at' => '2026-11-10T10:00:30Z',
                ]],
                'threads' => [[
                    'thread_id' => $threadid,
                    'messages' => [['type' => 'human', 'content' => 'Create a page'], ['type' => 'ai', 'content' => 'Done']],
                ]],
            ],
        ];
    }

    /**
     * The thread-scoped export sends exactly the contract body, as a POST on /privacy/export.
     */
    public function test_sends_exactly_the_contract_body(): void {
        $student = $this->getDataGenerator()->create_user();
        [$export, $wire] = $this->client();

        $data = $export->export('local_coursedynamicrules', (int) $student->id, ['thread-1', 'thread-2']);

        $this->assertSame($this->exported('thread-1')['data'], $data);
        $handed = $wire->handed_to(self::PATH);
        $this->assertCount(1, $handed);
        $this->assertSame('POST', $handed[0]['method']);
        $this->assertSame([
            'userid' => (int) $student->id,
            'service_id' => 'local_coursedynamicrules',
            'scope' => 'threads',
            'thread_ids' => ['thread-1', 'thread-2'],
        ], $handed[0]['body']);

        // On the wire the transport pseudonymises the student and adds the site.
        $sent = $wire->wire_to(self::PATH);
        $this->assertCount(1, $sent);
        $this->assertSame(wire_capturing_ai_course_api::BASE_URL . self::PATH, $sent[0]['url']);
        $this->assertSame(
            \aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid((int) $student->id),
            (string) $sent[0]['body']['userid']
        );
        $this->assertArrayHasKey('site_id', $sent[0]['body']);
    }

    /**
     * More than 1000 threads go in calls of at most 1000, and the answers are merged in order.
     */
    public function test_long_thread_lists_are_sent_in_chunks_of_1000_and_merged(): void {
        [$export, $wire] = $this->client();
        $wire->sequences[self::PATH] = [$this->exported('a'), $this->exported('b'), $this->exported('c')];
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 2501));

        $data = $export->export('local_coursedynamicrules', 2, $threadids);

        $handed = $wire->handed_to(self::PATH);
        $this->assertSame([1000, 1000, 501], array_map(fn(array $call): int => count($call['body']['thread_ids']), $handed));
        $this->assertSame($threadids, array_merge(...array_map(fn(array $call): array => $call['body']['thread_ids'], $handed)));
        $this->assertSame(['a', 'b', 'c'], array_column($data['requests'], 'thread_id'));
        $this->assertSame(['a', 'b', 'c'], array_column($data['token_usage'], 'thread_id'));
        $this->assertSame(['a', 'b', 'c'], array_column($data['threads'], 'thread_id'));
    }

    /**
     * Exactly 1000 threads fit in one call.
     */
    public function test_exactly_1000_threads_fit_in_one_call(): void {
        [$export, $wire] = $this->client();
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 1000));

        $this->assertNotNull($export->export('local_coursedynamicrules', 2, $threadids));
        $this->assertCount(1, $wire->handed_to(self::PATH));
    }

    /**
     * No thread means nothing to ask: nothing is sent and the data is empty.
     */
    public function test_no_thread_sends_nothing(): void {
        [$export, $wire] = $this->client();

        $this->assertSame(
            ['requests' => [], 'token_usage' => [], 'threads' => []],
            $export->export('local_coursedynamicrules', 2, [])
        );
        $this->assertSame([], $wire->handed_to(self::PATH));
    }

    /**
     * Anything but {"status": "exported"} with a data object is not an export.
     *
     * @dataProvider unexported_provider
     * @param array $response What the service answers.
     */
    public function test_anything_but_exported_is_null(array $response): void {
        [$export] = $this->client($response);

        $this->assertNull($export->export('local_coursedynamicrules', 2, ['thread-1']));
    }

    /**
     * Answers that are not an export.
     *
     * @return array
     */
    public static function unexported_provider(): array {
        return [
            'empty object' => [[]],
            'failed' => [['status' => 'failed', 'data' => []]],
            'no data' => [['status' => 'exported']],
            'data not an object' => [['status' => 'exported', 'data' => 'x']],
        ];
    }

    /**
     * One chunk that is not exported makes the whole export unavailable, rather than partial.
     */
    public function test_one_failed_chunk_makes_the_export_unavailable(): void {
        [$export, $wire] = $this->client();
        $wire->sequences[self::PATH] = [$this->exported('a'), ['status' => 'failed'], $this->exported('c')];
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 2001));

        $this->assertNull($export->export('local_coursedynamicrules', 2, $threadids));
    }

    /**
     * A transport failure (the provider disabled) is reported as null, never thrown.
     */
    public function test_a_transport_failure_is_null(): void {
        [$export, $wire] = $this->client();
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);

        $this->assertNull($export->export('local_coursedynamicrules', 2, ['thread-1']));
        $this->assertSame([], $wire->wire_to(self::PATH), 'nothing left the site');
    }

    /**
     * The service refuses a site without balance or subscription with 403, and a bad body with 422.
     *
     * @dataProvider refused_provider
     * @param int $httpcode The status the service answers with.
     * @param array $response The body it answers with.
     */
    public function test_a_refused_request_is_null(int $httpcode, array $response): void {
        [$export, $wire] = $this->client($response);
        $wire->httpcode = $httpcode;

        $this->assertNull($export->export('local_coursedynamicrules', 2, ['thread-1']));
        $this->assertCount(1, $wire->wire_to(self::PATH), 'the request was sent and refused');
        // The transport reports the refusal to developers; that is its business, not this test's.
        $this->resetDebugging();
    }

    /**
     * Answers the service refuses an export with.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'no token balance' => [403, ['detail' => 'tokens_not_sufficient']],
            'forbidden' => [403, ['detail' => 'Forbidden']],
            'invalid body' => [422, ['detail' => 'validation error']],
        ];
    }

    /**
     * The DI container hands out the course service client in production.
     */
    public function test_di_resolves_the_course_service_client(): void {
        $this->assertInstanceOf(
            course_service_remote_export_client::class,
            \core\di::get(remote_export_client::class)
        );
    }
}
