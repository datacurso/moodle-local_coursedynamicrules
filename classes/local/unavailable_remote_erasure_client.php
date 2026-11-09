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
 * The production remote erasure client, while the course service has no erasure endpoint.
 *
 * It calls nothing and reports every erasure as not done, so the local rows are kept (they stay
 * exportable) and marked pending, and the scheduled remote_erasure_task sends them again on every run,
 * instead of anybody being told the service forgot data it still holds.
 *
 * PENDING CONTRACT, to be implemented once the course service publishes it: a DELETE (or POST) on
 * the course service, sent through aiprovider_datacurso's shared transport so it carries site_id,
 * keyed by the site (site_id), the student (the same user identifier the transport sends with
 * /activity/init, or a pseudonym of it agreed with the service), service_id=local_coursedynamicrules
 * so only this plugin's data is erased, and the scope and thread ids described in
 * remote_erasure_client (by thread, or by user when every request of the student is to be erased).
 * It must erase the activity instructions, the request configuration and the
 * generated activity kept for those threads, and answer with a confirmation this client maps to true.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unavailable_remote_erasure_client implements remote_erasure_client {
    #[\Override]
    public function erase(string $serviceid, int $userid, string $scope, array $threadids): bool {
        return false;
    }
}
