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
 * The AI requests this plugin made for each student, and their erasure on the course service.
 *
 * Every AI activity starts with /activity/init, and the course service keeps that thread - the
 * activity instructions, the request configuration and the generated activity - on its side. Each
 * thread is recorded in local_coursedynamicrules_aireq against its student and course, so a privacy
 * request can list it and ask the service to erase it (CDR-PRIV-001-R2).
 *
 * An erasure is durable. Requesting it stamps the rows (erasurerequested) before anything is sent;
 * the rows are deleted only once the service confirmed, and the scheduled remote_erasure_task sends
 * every stamped row again on each run until it does. Nothing depends on a task surviving: an adhoc
 * task gives up after core's attempt limit, a stamp in the table does not. The production client is
 * course_service_remote_erasure_client (POST /privacy/erase on the course service). What is
 * sent for which rows - by thread, or by user once every request of the student is to be erased - is
 * described in remote_erasure_client.
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
            'timecreated' => self::now(),
            'erasurerequested' => null,
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
     * The requests recorded for a student in courses that no longer exist, oldest first.
     *
     * Their course context went with the course, so the privacy provider reaches them through the
     * student's own context.
     *
     * @param int $userid The student.
     * @return \stdClass[] The rows.
     */
    public static function orphaned_requests_of(int $userid): array {
        global $DB;
        $sql = 'SELECT q.*
                  FROM {' . self::TABLE . '} q
             LEFT JOIN {course} c ON c.id = q.courseid
                 WHERE q.userid = :userid AND c.id IS NULL
              ORDER BY q.timecreated ASC, q.id ASC';

        return array_values($DB->get_records_sql($sql, ['userid' => $userid]));
    }

    /**
     * Record that the erasure of a student's requests was asked for.
     *
     * Only rows not already stamped are stamped, so the first request keeps its date.
     *
     * @param int $userid The student.
     * @param int[]|null $courseids Only these courses' requests, or null for all of them.
     * @return void
     */
    public static function request_erasure(int $userid, ?array $courseids = null): void {
        global $DB;
        $select = 'userid = :userid AND erasurerequested IS NULL';
        $params = ['userid' => $userid];
        if ($courseids !== null) {
            if ($courseids === []) {
                return;
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $courseids), SQL_PARAMS_NAMED);
            $select .= " AND courseid $insql";
            $params += $inparams;
        }
        $DB->set_field_select(self::TABLE, 'erasurerequested', self::now(), $select, $params);
    }

    /**
     * Record that the erasure of every request of a course was asked for, as when it is deleted.
     *
     * @param int $courseid The course.
     * @return void
     */
    public static function request_course_erasure(int $courseid): void {
        global $DB;
        $DB->set_field_select(
            self::TABLE,
            'erasurerequested',
            self::now(),
            'courseid = :courseid AND erasurerequested IS NULL',
            ['courseid' => $courseid]
        );
    }

    /**
     * Request the erasure of a student's requests and send it to the service now.
     *
     * @param int $userid The student.
     * @param int|null $courseid Only this course's requests, or null for all of them.
     * @return bool Whether nothing of that scope is left pending.
     */
    public static function erase(int $userid, ?int $courseid = null): bool {
        self::request_erasure($userid, $courseid === null ? null : [$courseid]);
        self::send_pending($userid);

        return !self::has_pending($userid, $courseid);
    }

    /**
     * Send what is pending erasure for a student, and forget the rows the service confirmed.
     *
     * When every request of the student is pending, one SCOPE_USER request covers them all, rows
     * without a thread id included. Otherwise only the pending rows with a thread id are sent, by
     * thread; pending rows without one wait (see remote_erasure_client). Serialised per student, so
     * the scheduled task and a privacy request never send the same rows twice at once.
     *
     * @param int $userid The student.
     * @return bool|null True when the service confirmed, false when it did not or could not be asked,
     *     null when there was nothing that could be sent.
     */
    public static function send_pending(int $userid): ?bool {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('local_coursedynamicrules')
            ->get_lock('remote_erasure_' . $userid, 0);
        if (!$lock) {
            return false;
        }
        try {
            $rows = self::requests_of($userid);
            $pending = array_values(array_filter($rows, fn(\stdClass $row): bool => $row->erasurerequested !== null));
            if ($pending === []) {
                return null;
            }
            if (count($pending) === count($rows)) {
                $scope = remote_erasure_client::SCOPE_USER;
                $send = $pending;
            } else {
                $scope = remote_erasure_client::SCOPE_THREADS;
                $send = array_values(array_filter($pending, fn(\stdClass $row): bool => self::thread_of($row) !== null));
                if ($send === []) {
                    return null;
                }
            }
            $threadids = array_values(array_unique(array_filter(array_map([self::class, 'thread_of'], $send))));

            try {
                $confirmed = self::client()->erase(self::SERVICE_ID, $userid, $scope, $threadids);
            } catch (\Throwable $e) {
                debugging('local_coursedynamicrules: the remote erasure of user ' . $userid . ' failed, it stays pending: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                return false;
            }
            if (!$confirmed) {
                return false;
            }

            [$insql, $params] = $DB->get_in_or_equal(array_column($send, 'id'), SQL_PARAMS_NAMED);
            $DB->delete_records_select(self::TABLE, "id $insql", $params);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a student has requests pending erasure.
     *
     * @param int $userid The student.
     * @param int|null $courseid Only this course, or null for every course.
     * @return bool
     */
    public static function has_pending(int $userid, ?int $courseid = null): bool {
        global $DB;
        $select = 'userid = :userid AND erasurerequested IS NOT NULL';
        $params = ['userid' => $userid];
        if ($courseid !== null) {
            $select .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        return $DB->record_exists_select(self::TABLE, $select, $params);
    }

    /**
     * The students with requests pending erasure, the longest waiting first.
     *
     * @return int[] User ids.
     */
    public static function users_pending(): array {
        global $DB;
        $sql = 'SELECT userid, MIN(erasurerequested) AS firstrequested
                  FROM {' . self::TABLE . '}
                 WHERE erasurerequested IS NOT NULL
              GROUP BY userid
              ORDER BY MIN(erasurerequested) ASC, userid ASC';

        return array_map('intval', array_keys($DB->get_records_sql_menu($sql)));
    }

    /**
     * The client that talks to the course service.
     *
     * @return remote_erasure_client
     */
    public static function client(): remote_erasure_client {
        return \core\di::get(remote_erasure_client::class);
    }

    /**
     * The thread id of a row, or null when none was recorded.
     *
     * @param \stdClass $row The row.
     * @return string|null
     */
    private static function thread_of(\stdClass $row): ?string {
        return $row->threadid === null || $row->threadid === '' ? null : (string) $row->threadid;
    }

    /**
     * The current time, from core's clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
