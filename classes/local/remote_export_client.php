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

/**
 * Asks the course service for what it keeps about one student for this plugin.
 *
 * Resolved through \core\di (registered in db/hooks.php), so tests inject a fake. The production
 * implementation is course_service_remote_export_client.
 *
 * THE CONTRACT. POST /privacy/export on the course service, relative to the same base URL as
 * /activity/init and sent through aiprovider_datacurso's course client, with the same body as
 * /privacy/erase: {"userid": <Moodle user id>, "service_id": <calling plugin>, "scope": "threads",
 * "thread_ids": [...], "client_request_ids": [...]} (at most 1000 of each per call; the optional
 * client_request_ids are merged with thread_ids, and are sent only when there are any). The transport
 * adds site_id and site_url (the provider's 5.x line pseudonymises the user id from 2.2.2 on and sends
 * it as it is before). The service answers HTTP 200 with
 * {"status": "exported", "data": {"requests": [...], "token_usage": [...], "threads": [...]}},
 * returning only data of that site, student and service id created with the same licence. The
 * privacy routes do not check the site's token balance. Anything else - an error answer such as the
 * 422 of a body it refuses, or any other status - is not an export.
 *
 * Only the "threads" scope is used: the export of a context asks for the threads and client request
 * ids recorded on this site for the student in that context, never for everything the student has on
 * the service. The same call verifies an erasure (remote_erasure::send_pending()): an erasure counts
 * only when the export of its threads and keys returns no request and no thread. A service that
 * predates client_request_id ignores the keys, so it returns nothing for a request known here only by
 * its key whether or not it still keeps it.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface remote_export_client {
    /**
     * Ask the course service for the listed threads of the student for one calling plugin.
     *
     * @param string $serviceid The calling plugin's service id (always local_coursedynamicrules here).
     * @param int $userid The student, as this site knows them.
     * @param string[] $threadids The threads to export.
     * @param string[] $clientrequestids The client request ids of the requests to export. When both lists
     *     are empty nothing is asked.
     * @return array|null The data as the service returned it - ['requests' => [], 'token_usage' => [],
     *     'threads' => []] - or null when it could not be retrieved.
     */
    public function export(string $serviceid, int $userid, array $threadids, array $clientrequestids = []): ?array;
}
