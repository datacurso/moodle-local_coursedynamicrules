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

namespace local_coursedynamicrules\tests;

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * The real Datacurso course client, cut off from the network at its lowest seam.
 *
 * Everything aiprovider_datacurso does to a request still runs: request(), then send_request(),
 * which merges the shared transport's own fields (site_id, userid, timezone, lang, site_url) into
 * every POST body and JSON-encodes it. Only two methods are replaced:
 *
 * - is_for_ue(), which the constructor calls and which would otherwise ask the shop for the
 *   licence region over the network;
 * - execute_request(), the documented cURL boundary (datacurso_api_base::execute_request()). It
 *   receives the body exactly as it would be written to the socket, so what it records is what
 *   leaves the site, not what this plugin handed to the client.
 *
 * request() is wrapped as well, without changing it, so a test can compare what the plugin handed
 * over with what the transport finally sent.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wire_capturing_ai_course_api extends ai_course_api {
    /** @var string Base URL the double is built with; nothing is ever sent there. */
    public const BASE_URL = 'https://ai.example.test/api/v1';

    /** @var array[] Every request as the plugin handed it over: [method, path, body]. */
    public array $handed = [];

    /** @var array[] Every request as it reached the cURL boundary: [method, url, raw, body]. */
    public array $wire = [];

    /** @var array Canned JSON responses by path suffix; anything else answers an empty object. */
    public array $responses = [
        '/activity/init' => ['thread_id' => 'thread-1', 'status' => 'pending'],
        '/activity/feedback' => ['status' => 'accepted'],
    ];

    /**
     * Create the enabled provider instance the client constructor insists on.
     *
     * datacurso_api_base::__construct() throws unless an enabled aiprovider_datacurso instance with
     * a licence key exists. Older provider releases read the key from the plugin settings instead,
     * so both are seeded.
     *
     * @return void
     */
    public static function seed_provider(): void {
        global $DB;

        set_config('licensekey', 'test-licence-key', 'aiprovider_datacurso');
        if (method_exists(\core_ai\manager::class, 'create_provider_instance')) {
            $manager = new \core_ai\manager($DB);
            $manager->create_provider_instance(
                classname: \aiprovider_datacurso\provider::class,
                name: 'wire capture',
                enabled: true,
                config: ['licensekey' => 'test-licence-key'],
            );
        }
    }

    /**
     * Build the client against the fake base URL.
     *
     * @return self
     */
    public static function create(): self {
        return new self(null, self::BASE_URL, self::BASE_URL);
    }

    /**
     * Never ask the shop for the licence region.
     *
     * @return bool
     */
    public function is_for_ue(): bool {
        return false;
    }

    /**
     * Record the body the plugin hands over, then let the real client run.
     *
     * @param string $method The HTTP method.
     * @param string $path The API path.
     * @param array $body The request body.
     * @return array|null
     */
    public function request(string $method, string $path, array $body = []): ?array {
        $this->handed[] = ['method' => $method, 'path' => $path, 'body' => $body];
        return parent::request($method, $path, $body);
    }

    /**
     * Record the request at the cURL boundary and answer it without the network.
     *
     * @param \curl $curl cURL client the request would have been executed with.
     * @param string $method The HTTP method.
     * @param string $url Full request URL.
     * @param mixed $payload The body, already merged with the transport's defaults and encoded.
     * @param array $headers Request headers.
     * @return string|null
     */
    protected function execute_request(\curl $curl, string $method, string $url, $payload, array $headers): ?string {
        $this->wire[] = [
            'method' => $method,
            'url' => $url,
            'raw' => is_string($payload) ? $payload : null,
            'body' => is_string($payload) ? json_decode($payload, true) : $payload,
        ];

        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                return json_encode($response);
            }
        }

        return '{}';
    }

    /**
     * The wire records of one endpoint.
     *
     * @param string $path The API path, e.g. '/activity/init'.
     * @return array[]
     */
    public function wire_to(string $path): array {
        return array_values(array_filter($this->wire, fn(array $call): bool => str_ends_with($call['url'], $path)));
    }

    /**
     * The handed-over records of one endpoint.
     *
     * @param string $path The API path, e.g. '/activity/init'.
     * @return array[]
     */
    public function handed_to(string $path): array {
        return array_values(array_filter($this->handed, fn(array $call): bool => $call['path'] === $path));
    }
}
