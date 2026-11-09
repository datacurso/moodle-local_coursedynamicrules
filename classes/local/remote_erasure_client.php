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
 * /activity/init and sent through aiprovider_datacurso's course client, with the body
 * {"userid": <Moodle user id>, "service_id": <calling plugin>, "scope": "threads"|"user",
 * "thread_ids": [...]}. The transport pseudonymises the user id and adds site_id and site_url, so the
 * service erases only this site's data of that student, and only for that service id: the activity
 * instructions, the request configuration and the generated activity kept for those threads. The
 * service accepts at most 1000 thread ids per call, each 1 to 255 characters, and refuses a
 * "threads" request with an empty list (422). It confirms with HTTP 200 and
 * {"status": "erased", "requests": n, "threads": m}. Anything else is not a confirmation, including
 * the 403 a site without token balance or with an inactive subscription receives.
 *
 * WHAT IS SENT. The course service was never told the course of a request (the payload of
 * /activity/init carries no course id), so a request is scoped by thread or by user, never by course:
 *
 * - SCOPE_THREADS: erase exactly the listed threads of the student. The list is never empty. This is
 *   what an erasure limited to some courses sends: the thread ids recorded for those courses.
 * - SCOPE_USER: erase every thread of the student for this service id, including threads this site
 *   has no id for (requests recorded by the upgrade, or an /activity/init whose answer was lost). It is
 *   sent only when every request recorded for the student is to be erased; the listed thread ids are
 *   the ones known here, possibly none.
 *
 * A request recorded without a thread id whose erasure covers only some of the student's courses is
 * not sent at all: by user it would erase the other courses too, and by thread there is nothing to
 * name. It stays pending (local_coursedynamicrules_aireq.erasurerequested) and is covered by the
 * SCOPE_USER request sent once every request of the student is to be erased - an account deletion
 * always is.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface remote_erasure_client {
    /** @var string Erase exactly the listed threads of the student. */
    public const SCOPE_THREADS = 'threads';

    /** @var string Erase every thread of the student for this service id. */
    public const SCOPE_USER = 'user';

    /**
     * Erase the student's threads of one calling plugin on the course service.
     *
     * @param string $serviceid The calling plugin's service id (always local_coursedynamicrules here).
     * @param int $userid The student, as this site knows them.
     * @param string $scope SCOPE_THREADS or SCOPE_USER.
     * @param string[] $threadids The threads to erase (SCOPE_THREADS, never empty), or the threads known
     *     on this site for the student (SCOPE_USER, possibly empty).
     * @return bool True only when the service confirmed the erasure.
     */
    public function erase(string $serviceid, int $userid, string $scope, array $threadids): bool;
}
