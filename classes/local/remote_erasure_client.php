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
 * Asks the course service to erase what it keeps about one student for this plugin.
 *
 * Resolved through \core\di (registered in db/hooks.php), so tests inject a fake. The production
 * implementation is course_service_remote_erasure_client.
 *
 * THE CONTRACT. POST /privacy/erase on the course service, relative to the same base URL as
 * /activity/init and sent through aiprovider_datacurso's course client, with the body {"userid":
 * <Moodle user id>, "service_id": <calling plugin>, "scope": "threads"|"user", "thread_ids": [...],
 * "client_request_ids": [...]}. The transport adds site_id and site_url (the provider's 5.x line
 * pseudonymises the user id from 2.2.2 on and sends it as it is before), so the service erases only
 * this site's data of that student, and only for that service id: the activity instructions, the
 * request configuration and the generated activity kept for those requests. The service accepts at
 * most 1000 thread ids and 1000 client request ids per call; client_request_ids is optional and merged
 * with thread_ids, and a "threads" request must name at least one of either (422 otherwise). It
 * confirms with HTTP 200 and {"status": "erased", "requests": n, "threads": m, "erased_thread_ids":
 * [...]}. Anything else is not a confirmation. The privacy routes do not check the site's token
 * balance, so a site without balance is not refused there.
 *
 * THE CLIENT REQUEST ID. Since 1.8.15 every /activity/init carries a random client_request_id (a UUID
 * v4) that the service stores before any run, and the request is recorded on this site under that
 * key before the call. A request whose answer was lost - no thread id here - is still named by its
 * key. The key list is sent only when it is not empty, so an erasure without keys keeps the body
 * older services know; those services ignore the field.
 *
 * WHAT IS SENT. The course service was never told the course of a request (the payload of
 * /activity/init carries no course id), so a request is scoped by thread/key or by user, never by
 * course:
 *
 * - SCOPE_THREADS: erase exactly the listed threads and keys of the student. At least one of the two
 *   lists is not empty. This is what an erasure limited to some courses sends: the thread ids and keys
 *   recorded for those courses.
 * - SCOPE_USER: erase every thread of the student for this service id that the service can find. It is
 *   sent only when every request recorded for the student is to be erased; the listed thread ids and
 *   keys are the ones known here, possibly none.
 *
 * A request recorded with neither a thread id nor a key (only those recorded by the 1.8.13 upgrade)
 * whose erasure covers only some of the student's courses is not sent at all: by user it would erase
 * the other courses too, and by thread there is nothing to name. It stays pending
 * (local_coursedynamicrules_aireq.erasurerequested) and is covered by the SCOPE_USER request sent once
 * every request of the student is to be erased - an account deletion always is.
 *
 * VERIFICATION AND ITS LIMITS. A confirmation is checked with remote_export_client for the same thread
 * ids and keys; the rows are forgotten only when that export returns nothing for them (see
 * remote_erasure::send_pending()). A service that predates client_request_id ignores the keys, so its
 * "erased" and its empty export prove nothing about a request known here only by its key. Only a service
 * that answers erased_thread_ids understands keys (remote_erasure_result::$keysunderstood); without that
 * field a key-only row is never forgotten: it stays pending, its failed attempts are counted, it shows in
 * the overdue list and in time fires remote_erasure_failed. What remains unverifiable: the service finds a
 * thread for a user-wide erasure only once its run finished, so a thread known neither by id nor by key
 * is not reached while its run never finished; and a row with neither thread id nor key (recorded by the
 * 1.8.13 upgrade) cannot be named in the export, so its "erased" answer is taken as given.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface remote_erasure_client {
    /** @var string Erase exactly the listed threads and client request ids of the student. */
    public const SCOPE_THREADS = 'threads';

    /** @var string Erase every thread of the student for this service id that the service can find. */
    public const SCOPE_USER = 'user';

    /**
     * Erase the student's threads of one calling plugin on the course service.
     *
     * @param string $serviceid The calling plugin's service id (always local_coursedynamicrules here).
     * @param int $userid The student, as this site knows them.
     * @param string $scope SCOPE_THREADS or SCOPE_USER.
     * @param string[] $threadids The threads to erase (SCOPE_THREADS), or the threads known on this site
     *     for the student (SCOPE_USER); possibly empty.
     * @param string[] $clientrequestids The client request ids of the requests to erase, possibly empty.
     *     With SCOPE_THREADS, $threadids and $clientrequestids are never both empty.
     * @return remote_erasure_result Whether the service confirmed the erasure, and whether its answer shows
     *     it understood the client request ids (erased_thread_ids present).
     */
    public function erase(
        string $serviceid,
        int $userid,
        string $scope,
        array $threadids,
        array $clientrequestids = []
    ): remote_erasure_result;
}
