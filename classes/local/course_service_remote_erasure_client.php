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

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * The production remote erasure client: asks the Datacurso course service to erase (CDR-PRIV-001-R2).
 *
 * It sends POST /privacy/erase through aiprovider_datacurso's course client, the same client and base
 * URL as /activity/init, so the transport pseudonymises the user id and adds site_id and site_url as
 * it does for every request. The contract is described in remote_erasure_client.
 *
 * The service takes at most MAX_THREADS thread ids and MAX_THREADS client request ids per call, so
 * longer lists are sent in several calls in the same scope - call n carries the n-th slice of each
 * list - and the erasure is confirmed only when every call answers {"status": "erased"}. The
 * client_request_ids key is sent only when there are keys, and the result says whether every answer
 * carried erased_thread_ids, the sign of a service that understands them. Anything else - an error answer (such as 422
 * for a body the service refuses), a transport failure, the
 * provider missing or disabled, any other status - is reported as not done and never thrown: the rows
 * stay pending and remote_erasure_task sends them again. Nothing about the request is logged.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_service_remote_erasure_client implements remote_erasure_client {
    /** @var string The erasure endpoint, relative to the course service base URL. */
    public const PATH = '/privacy/erase';

    /** @var int The most thread ids, and the most client request ids, the service accepts in one call. */
    public const MAX_THREADS = 1000;

    /** @var \Closure|null Builds the course client; null builds the production one. */
    private ?\Closure $clientfactory;

    /**
     * Build the client.
     *
     * @param \Closure|null $clientfactory Returns the ai_course_api to send through (tests); null for production.
     */
    public function __construct(?\Closure $clientfactory = null) {
        $this->clientfactory = $clientfactory;
    }

    #[\Override]
    public function erase(
        string $serviceid,
        int $userid,
        string $scope,
        array $threadids,
        array $clientrequestids = []
    ): remote_erasure_result {
        $threadids = array_values($threadids);
        $clientrequestids = array_values($clientrequestids);
        if ($scope === self::SCOPE_THREADS && $threadids === [] && $clientrequestids === []) {
            // The service refuses a thread-scoped erasure naming nothing; it would never be confirmed.
            return new remote_erasure_result(false);
        }
        $keysunderstood = true;

        try {
            $client = $this->client();
            foreach (self::slices($threadids, $clientrequestids, self::MAX_THREADS) as [$threadchunk, $keychunk]) {
                $body = [
                    'userid' => $userid,
                    'service_id' => $serviceid,
                    'scope' => $scope,
                    'thread_ids' => $threadchunk,
                ];
                if ($keychunk !== []) {
                    $body['client_request_ids'] = $keychunk;
                }
                $response = $client->request('POST', self::PATH, $body);
                if (!is_array($response) || ($response['status'] ?? null) !== 'erased') {
                    return new remote_erasure_result(false);
                }
                // Only a service that understands client_request_ids answers erased_thread_ids.
                $keysunderstood = $keysunderstood && is_array($response['erased_thread_ids'] ?? null);
            }
        } catch (\Throwable $e) {
            // Not done: the rows stay pending and the scheduled task sends them again.
            return new remote_erasure_result(false);
        }

        return new remote_erasure_result(true, $keysunderstood);
    }

    /**
     * Split two lists into calls carrying at most $size items of each: call n gets the n-th slice of both.
     *
     * @param string[] $threadids The thread ids.
     * @param string[] $clientrequestids The client request ids.
     * @param int $size The most items of each list per call.
     * @return array[] One [thread ids, client request ids] pair per call; one empty pair for empty lists.
     */
    public static function slices(array $threadids, array $clientrequestids, int $size): array {
        $threadchunks = array_chunk($threadids, $size);
        $keychunks = array_chunk($clientrequestids, $size);
        $calls = max(1, count($threadchunks), count($keychunks));
        $slices = [];
        for ($i = 0; $i < $calls; $i++) {
            $slices[] = [$threadchunks[$i] ?? [], $keychunks[$i] ?? []];
        }

        return $slices;
    }

    /**
     * The course client the request is sent through.
     *
     * @return ai_course_api
     */
    private function client(): ai_course_api {
        if ($this->clientfactory !== null) {
            return ($this->clientfactory)();
        }
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        return new ai_course_api(null, $baseurl, $baseurleu, 'local_coursedynamicrules');
    }
}
