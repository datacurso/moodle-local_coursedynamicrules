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
 * The production remote export client: asks the Datacurso course service for a student's data.
 *
 * It sends POST /privacy/export through aiprovider_datacurso's course client, the same client and base
 * URL as /activity/init and /privacy/erase, so the transport adds site_id and site_url as it does for
 * every request (the provider's 5.x line pseudonymises the user id from 2.2.2 on). The contract is
 * described in remote_export_client.
 *
 * The service takes at most MAX_THREADS thread ids per call, so a longer list is sent in several calls
 * and their answers are merged in order. The export is returned only when every call answers
 * {"status": "exported"} with a data object; anything else - an error answer, a transport failure, the
 * provider missing or disabled, any other status - is reported as null and never thrown, so the
 * privacy export it feeds still completes. Nothing about the request or the answer is logged.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_service_remote_export_client implements remote_export_client {
    /** @var string The export endpoint, relative to the course service base URL. */
    public const PATH = '/privacy/export';

    /** @var int The most thread ids the service accepts in one call. */
    public const MAX_THREADS = 1000;

    /** @var string[] The lists the service answers with. */
    private const LISTS = ['requests', 'token_usage', 'threads'];

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
    public function export(string $serviceid, int $userid, array $threadids): ?array {
        $data = array_fill_keys(self::LISTS, []);
        $threadids = array_values($threadids);
        if ($threadids === []) {
            // The service refuses a thread-scoped request without threads, and there is nothing to ask.
            return $data;
        }

        try {
            $client = $this->client();
            foreach (array_chunk($threadids, self::MAX_THREADS) as $chunk) {
                $response = $client->request('POST', self::PATH, [
                    'userid' => $userid,
                    'service_id' => $serviceid,
                    'scope' => remote_erasure_client::SCOPE_THREADS,
                    'thread_ids' => $chunk,
                ]);
                $exported = is_array($response) && ($response['status'] ?? null) === 'exported';
                if (!$exported || !is_array($response['data'] ?? null)) {
                    return null;
                }
                foreach (self::LISTS as $list) {
                    $items = $response['data'][$list] ?? [];
                    if (is_array($items)) {
                        $data[$list] = array_merge($data[$list], array_values($items));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Not retrieved: the privacy export writes a note instead and completes.
            return null;
        }

        return $data;
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
