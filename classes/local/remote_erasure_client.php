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
 * implementation is unavailable_remote_erasure_client until the course service exposes an erasure
 * endpoint; see that class for the expected contract.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface remote_erasure_client {
    /**
     * Erase the student's threads of one calling plugin on the course service.
     *
     * @param string $serviceid The calling plugin's service id (always local_coursedynamicrules here).
     * @param int $userid The student, as this site knows them.
     * @param string[] $threadids The threads recorded for the student; may be empty for requests
     *     recorded before thread ids were kept, which the service must then find by user.
     * @return bool True only when the service confirmed the erasure.
     */
    public function erase(string $serviceid, int $userid, array $threadids): bool;
}
