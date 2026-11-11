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
 * The production remote erasure client asks the course service to erase a student's requests.
 *
 * The real Datacurso course client runs, cut off from the network at its cURL boundary
 * (wire_capturing_ai_course_api), so the body asserted is the one that would leave the site.
 *
 * CDR-PRIV-001-R2.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\local\course_service_remote_erasure_client
 * @covers     \local_coursedynamicrules\hook\di_callbacks
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_service_remote_erasure_client_test extends \advanced_testcase {
    /** @var string The erasure endpoint, relative to the course service base URL. */
    private const PATH = '/privacy/erase';

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
     * @param array|null $response What the service answers on the erasure endpoint.
     * @return array [course_service_remote_erasure_client $erasure, wire_capturing_ai_course_api $wire]
     */
    private function client(?array $response = ['status' => 'erased']): array {
        wire_capturing_ai_course_api::seed_provider();
        $wire = wire_capturing_ai_course_api::create();
        if ($response !== null) {
            $wire->responses[self::PATH] = $response;
        }
        $erasure = new course_service_remote_erasure_client(fn() => $wire);
        return [$erasure, $wire];
    }

    /**
     * Both scopes send exactly the contract body, as a POST on /privacy/erase.
     *
     * @dataProvider scope_provider
     * @param string $scope The erasure scope.
     * @param string[] $threadids The thread ids handed to the client.
     */
    public function test_sends_exactly_the_contract_body(string $scope, array $threadids): void {
        $student = $this->getDataGenerator()->create_user();
        [$erasure, $wire] = $this->client();

        $this->assertTrue($erasure->erase('local_coursedynamicrules', (int) $student->id, $scope, $threadids));

        $handed = $wire->handed_to(self::PATH);
        $this->assertCount(1, $handed);
        $this->assertSame('POST', $handed[0]['method']);
        $this->assertSame([
            'userid' => (int) $student->id,
            'service_id' => 'local_coursedynamicrules',
            'scope' => $scope,
            'thread_ids' => $threadids,
        ], $handed[0]['body']);

        // On the wire the transport adds the site. The provider's 5.x line sends the Moodle user id
        // as it is (its README: "this version does not pseudonymise it").
        $sent = $wire->wire_to(self::PATH);
        $this->assertCount(1, $sent);
        $this->assertSame(wire_capturing_ai_course_api::BASE_URL . self::PATH, $sent[0]['url']);
        $this->assertSame((string) $student->id, (string) $sent[0]['body']['userid']);
        $this->assertSame($threadids, $sent[0]['body']['thread_ids']);
        $this->assertArrayHasKey('site_id', $sent[0]['body']);
    }

    /**
     * The two scopes of remote_erasure_client.
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'threads' => [remote_erasure_client::SCOPE_THREADS, ['thread-1', 'thread-2']],
            'user' => [remote_erasure_client::SCOPE_USER, []],
        ];
    }

    /**
     * Anything but {"status": "erased"} is not a confirmation.
     *
     * @dataProvider unconfirmed_provider
     * @param array|null $response What the service answers; null leaves the fixture's empty object.
     */
    public function test_anything_but_erased_is_not_confirmed(?array $response): void {
        [$erasure] = $this->client($response);

        $this->assertFalse($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_THREADS, ['thread-1']));
    }

    /**
     * Answers that do not confirm the erasure.
     *
     * @return array
     */
    public static function unconfirmed_provider(): array {
        return [
            'empty object' => [null],
            'failed' => [['status' => 'failed']],
            'pending' => [['status' => 'pending']],
            'no status' => [['erased' => true]],
        ];
    }

    /**
     * A transport failure is reported as not done, never thrown: the rows stay pending.
     *
     * On Moodle 5.x the course client refuses to be built while no enabled Datacurso provider
     * instance exists (instance_disabled), so nothing can leave the site.
     */
    public function test_a_transport_failure_is_not_confirmed(): void {
        global $DB;
        wire_capturing_ai_course_api::seed_provider();
        $manager = new \core_ai\manager($DB);
        foreach ($manager->get_provider_instances(['provider' => \aiprovider_datacurso\provider::class]) as $instance) {
            $manager->disable_provider_instance($instance);
        }
        $built = [];
        $erasure = new course_service_remote_erasure_client(function () use (&$built) {
            $wire = wire_capturing_ai_course_api::create();
            $built[] = $wire;
            return $wire;
        });

        $this->assertFalse($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_USER, []));
        $this->assertSame([], $built, 'no course client could be built, so nothing left the site');
    }

    /**
     * The service refuses a site without balance or subscription with 403, and a bad body with 422.
     *
     * Both are reported as not done, so the scheduled task sends the erasure again later.
     *
     * @dataProvider refused_provider
     * @param int $httpcode The status the service answers with.
     * @param array $response The body it answers with.
     */
    public function test_a_refused_request_is_not_confirmed(int $httpcode, array $response): void {
        [$erasure, $wire] = $this->client($response);
        $wire->httpcode = $httpcode;

        $this->assertFalse($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_THREADS, ['thread-1']));
        $this->assertCount(1, $wire->wire_to(self::PATH), 'the request was sent and refused');
        // The transport reports the refusal to developers; that is its business, not this test's.
        $this->resetDebugging();
    }

    /**
     * Answers the service refuses an erasure with.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'no token balance' => [403, ['detail' => 'tokens_not_sufficient']],
            'inactive subscription' => [403, ['detail' => 'license_not_allowed']],
            'forbidden' => [403, ['detail' => 'Forbidden']],
            'invalid body' => [422, ['detail' => 'validation error']],
        ];
    }

    /**
     * More than 1000 threads go in calls of at most 1000, each in the same scope.
     *
     * @dataProvider scope_provider
     * @param string $scope The erasure scope.
     */
    public function test_long_thread_lists_are_sent_in_chunks_of_1000(string $scope): void {
        [$erasure, $wire] = $this->client();
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 2501));

        $this->assertTrue($erasure->erase('local_coursedynamicrules', 2, $scope, $threadids));

        $handed = $wire->handed_to(self::PATH);
        $this->assertSame([1000, 1000, 501], array_map(fn(array $call): int => count($call['body']['thread_ids']), $handed));
        $this->assertSame($threadids, array_merge(...array_map(fn(array $call): array => $call['body']['thread_ids'], $handed)));
        foreach ($handed as $call) {
            $this->assertSame($scope, $call['body']['scope']);
            $this->assertSame('local_coursedynamicrules', $call['body']['service_id']);
        }
    }

    /**
     * The erasure is confirmed only when every chunk is.
     */
    public function test_one_unconfirmed_chunk_leaves_the_erasure_unconfirmed(): void {
        [$erasure, $wire] = $this->client();
        $wire->sequences[self::PATH] = [['status' => 'erased'], ['status' => 'failed'], ['status' => 'erased']];
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 2001));

        $this->assertFalse($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_THREADS, $threadids));
    }

    /**
     * Exactly 1000 threads fit in one call.
     */
    public function test_exactly_1000_threads_fit_in_one_call(): void {
        [$erasure, $wire] = $this->client();
        $threadids = array_map(fn(int $i): string => 'thread-' . $i, range(1, 1000));

        $this->assertTrue($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_THREADS, $threadids));
        $this->assertCount(1, $wire->handed_to(self::PATH));
    }

    /**
     * A thread-scoped erasure with no thread is refused by the service: it is never sent.
     */
    public function test_an_empty_thread_scoped_erasure_is_not_sent(): void {
        [$erasure, $wire] = $this->client();

        $this->assertFalse($erasure->erase('local_coursedynamicrules', 2, remote_erasure_client::SCOPE_THREADS, []));
        $this->assertSame([], $wire->handed_to(self::PATH));
    }

    /**
     * The DI container hands out the course service client in production.
     */
    public function test_di_resolves_the_course_service_client(): void {
        $this->assertInstanceOf(
            course_service_remote_erasure_client::class,
            \core\di::get(remote_erasure_client::class)
        );
    }
}
