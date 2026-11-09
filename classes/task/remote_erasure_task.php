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
 * Sends the pending erasures of students' AI requests to the course service, on every run.
 *
 * A privacy request, an account deletion and a course deletion stamp the rows of
 * local_coursedynamicrules_aireq whose erasure was asked for (remote_erasure). This task sends, on
 * every run, what is stamped and not yet confirmed, and the rows go only once the service confirmed.
 * The stamp is the only memory of the request, so it is never given up: a scheduled task runs again
 * whatever happened before, where an adhoc task stops after core's attempt limit (about 34 hours)
 * and is purged four weeks later. The course service has no erasure endpoint yet, so every run today
 * leaves everything pending.
 *
 * It never fails because the service did not confirm: that is the expected state until the endpoint
 * exists, and the pending rows say so, both here and in the privacy export.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure_task extends \core\task\scheduled_task {
    /** @var int The most requests sent to the service in one run; the rest wait for the next run. */
    public const MAX_SENT_PER_RUN = 200;

    #[\Override]
    public function get_name() {
        return get_string('remote_erasure_task', 'local_coursedynamicrules');
    }

    #[\Override]
    public function execute() {
        $sent = 0;
        $confirmed = 0;
        foreach (remote_erasure::users_pending() as $userid) {
            if ($sent >= self::MAX_SENT_PER_RUN) {
                break;
            }
            $result = remote_erasure::send_pending($userid);
            if ($result === null) {
                continue;
            }
            $sent++;
            if ($result) {
                $confirmed++;
            }
        }
        mtrace("local_coursedynamicrules remote erasure: {$sent} sent, {$confirmed} confirmed by the course service.");
    }
}
