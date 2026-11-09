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

use local_coursedynamicrules\task\remote_erasure_task;

/**
 * The AI requests this plugin made for each student, and their erasure on the course service.
 *
 * Every AI activity starts with /activity/init, and the course service keeps that thread - the
 * activity instructions, the request configuration and the generated activity - on its side. Each
 * thread is recorded in local_coursedynamicrules_aireq against its student and course, so a privacy
 * request can list it and ask the service to erase it (CDR-PRIV-001-R2).
 *
 * An erasure asks the service first and forgets the local rows only once it confirmed. When it did
 * not - which is always the case until the service exposes an erasure endpoint (see
 * unavailable_remote_erasure_client) - the rows stay and an adhoc retry is queued.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_erasure {
    /** @var string The table of recorded requests. */
    public const TABLE = 'local_coursedynamicrules_aireq';

    /** @var string The service id this plugin calls the course service with. */
    public const SERVICE_ID = 'local_coursedynamicrules';

    /**
     * Record a thread the course service opened for a student.
     *
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string|null $threadid The thread id, or null when it is not known.
     * @param int|null $cmid The generated module, when it exists already.
     * @return int The new row id.
     */
    public static function record_request(int $actionid, int $courseid, int $userid, ?string $threadid, ?int $cmid = null): int {
        global $DB;

        return (int) $DB->insert_record(self::TABLE, (object) [
            'actionid' => $actionid,
            'courseid' => $courseid,
            'userid' => $userid,
            'threadid' => $threadid,
            'cmid' => $cmid,
            'timecreated' => \core\di::get(\core\clock::class)->time(),
        ]);
    }

    /**
     * Link a recorded request to the module it generated.
     *
     * @param int $requestid The row.
     * @param int $cmid The generated module.
     * @return void
     */
    public static function attach_module(int $requestid, int $cmid): void {
        global $DB;
        $DB->set_field(self::TABLE, 'cmid', $cmid, ['id' => $requestid]);
    }

    /**
     * The requests recorded for a student, oldest first.
     *
     * @param int $userid The student.
     * @param int|null $courseid Only this course, or null for every course.
     * @return \stdClass[] The rows.
     */
    public static function requests_of(int $userid, ?int $courseid = null): array {
        global $DB;
        $conditions = ['userid' => $userid];
        if ($courseid !== null) {
            $conditions['courseid'] = $courseid;
        }

        return array_values($DB->get_records(self::TABLE, $conditions, 'timecreated ASC, id ASC'));
    }

    /**
     * The students with a request recorded in a course.
     *
     * @param int $courseid The course.
     * @return int[] User ids.
     */
    public static function users_in_course(int $courseid): array {
        global $DB;
        $sql = 'SELECT DISTINCT userid FROM {' . self::TABLE . '} WHERE courseid = :courseid';

        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Ask the service to erase a student's requests, and forget them once it confirmed.
     *
     * @param int $userid The student.
     * @param int|null $courseid Only this course's requests, or null for all of them.
     * @param bool $queueretry Whether to queue a retry when the service does not confirm.
     * @return bool Whether nothing is left to erase.
     */
    public static function erase(int $userid, ?int $courseid = null, bool $queueretry = true): bool {
        global $DB;

        $rows = self::requests_of($userid, $courseid);
        if ($rows === []) {
            return true;
        }
        $threadids = [];
        foreach ($rows as $row) {
            if ($row->threadid !== null && $row->threadid !== '') {
                $threadids[] = (string) $row->threadid;
            }
        }

        if (!self::client()->erase(self::SERVICE_ID, $userid, array_values(array_unique($threadids)))) {
            if ($queueretry) {
                self::queue_retry($userid, $courseid);
            }
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_column($rows, 'id'), SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "id $insql", $params);

        return true;
    }

    /**
     * Queue the adhoc task that retries a student's remote erasure until the service confirms.
     *
     * @param int $userid The student.
     * @param int|null $courseid Only this course's requests, or null for all of them.
     * @return void
     */
    public static function queue_retry(int $userid, ?int $courseid = null): void {
        $task = new remote_erasure_task();
        $task->set_custom_data((object) ['userid' => $userid, 'courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * The client that talks to the course service.
     *
     * @return remote_erasure_client
     */
    public static function client(): remote_erasure_client {
        return \core\di::get(remote_erasure_client::class);
    }
}
