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
 * The service takes at most MAX_THREADS thread ids per call, so a longer list is sent in several calls
 * in the same scope, and the erasure is confirmed only when every call answers {"status": "erased"}.
 * Anything else - an error answer (403 for a site without balance or subscription, 422 for a body the
 * service refuses), a transport failure, the provider missing or disabled, any other status - is
 * reported as not done and never thrown: the rows stay pending and remote_erasure_task sends them
 * again. Nothing about the request is logged.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_service_remote_erasure_client implements remote_erasure_client {
    /** @var string The erasure endpoint, relative to the course service base URL. */
    public const PATH = '/privacy/erase';

    /** @var int The most thread ids the service accepts in one call. */
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
    public function erase(string $serviceid, int $userid, string $scope, array $threadids): bool {
        $threadids = array_values($threadids);
        if ($scope === self::SCOPE_THREADS && $threadids === []) {
            // The service refuses a thread-scoped erasure without threads; it would never be confirmed.
            return false;
        }
        $chunks = $threadids === [] ? [[]] : array_chunk($threadids, self::MAX_THREADS);

        try {
            $client = $this->client();
            foreach ($chunks as $chunk) {
                $response = $client->request('POST', self::PATH, [
                    'userid' => $userid,
                    'service_id' => $serviceid,
                    'scope' => $scope,
                    'thread_ids' => $chunk,
                ]);
                if (!is_array($response) || ($response['status'] ?? null) !== 'erased') {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // Not done: the rows stay pending and the scheduled task sends them again.
            return false;
        }

        return true;
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
