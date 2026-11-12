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
 * "thread_ids": [...]} (at most 1000 thread ids per call). The transport adds site_id and site_url
 * (the provider's 5.x line pseudonymises the user id from 2.2.2 on and sends it as it is before).
 * The service answers HTTP 200 with {"status": "exported", "data": {"requests": [...],
 * "token_usage": [...], "threads": [...]}}, returning only data of that site,
 * student and service id created with the same licence. Anything else - including the 403 of a site
 * without token balance or subscription, or the 422 of a body it refuses - is not an export.
 *
 * Only the "threads" scope is used: the export of a context asks for the threads recorded on this
 * site for the student in that context, never for everything the student has on the service.
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
     * @param string[] $threadids The threads to export; an empty list asks nothing.
     * @return array|null The data as the service returned it - ['requests' => [], 'token_usage' => [],
     *     'threads' => []] - or null when it could not be retrieved.
     */
    public function export(string $serviceid, int $userid, array $threadids): ?array;
}
