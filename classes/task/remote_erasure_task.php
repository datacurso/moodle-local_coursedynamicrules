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

namespace local_coursedynamicrules\task;

use local_coursedynamicrules\local\remote_erasure;

/**
 * Retries the erasure of a student's AI requests on the course service.
 *
 * Queued by the privacy provider when the service did not confirm an erasure, and by the
 * user_deleted observer. It fails while the service does not confirm, so core runs it again with its
 * usual back-off; the local rows are kept, and stay exportable, until the service confirms.
 *
 * Custom data: {userid: int, courseid: int|null}.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure_task extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task:remoteerasure', 'local_coursedynamicrules');
    }

    #[\Override]
    public function execute() {
        $data = $this->get_custom_data();
        $userid = (int) ($data->userid ?? 0);
        if ($userid <= 0) {
            return;
        }
        $courseid = isset($data->courseid) ? (int) $data->courseid : null;

        if (!remote_erasure::erase($userid, $courseid, false)) {
            throw new \moodle_exception('error_remote_erasure_pending', 'local_coursedynamicrules', '', $userid);
        }
    }
}
