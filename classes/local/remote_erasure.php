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
 * Each request is recorded BEFORE /activity/init is called, under a random client request id (a
 * UUID v4) that is sent with the call as client_request_id; the service stores it before any run.
 * The thread id is filled in from the answer. When the answer never arrives the row stays, with its
 * key and no thread id, so the request can still be listed and erased by key.
 *
 * An erasure is durable. Requesting it stamps the rows (erasurerequested) before anything is sent;
 * the rows are deleted only once the service confirmed AND an export of the same threads and keys
 * (remote_export_client) returns nothing for them, and the scheduled remote_erasure_task sends every
 * stamped row again on each run until then. Every failed or unverified attempt is counted on the rows
 * (erasureattempts, erasurefailedat); when the count reaches FAILURE_THRESHOLD the event
 * remote_erasure_failed is fired once, and the plugin settings page lists the erasures pending longer
 * than the remoteerasurependingdays setting. Nothing depends on a task surviving: an adhoc task gives
 * up after core's attempt limit, a stamp in the table does not. The production client is
 * course_service_remote_erasure_client (POST /privacy/erase on the course service). What is sent for
 * which rows - by thread and key, or by user once every request of the student is to be erased - is
 * described in remote_erasure_client, together with what the verification cannot see.
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

    /** @var int Consecutive failed attempts after which remote_erasure_failed is fired, once. */
    public const FAILURE_THRESHOLD = 3;

    /** @var int Default days after which a pending erasure is listed for the administrator. */
    public const DEFAULT_PENDING_DAYS = 7;

    /** @var int The most overdue students listed on the settings page. */
    public const MAX_OVERDUE_LISTED = 50;

    /**
     * Record a request made to the course service for a student.
     *
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string|null $threadid The thread id, or null when it is not known (yet).
     * @param int|null $cmid The generated module, when it exists already.
     * @param string|null $clientrequestid The client request id sent with /activity/init, or null when none was.
     * @return int The new row id.
     */
    public static function record_request(
        int $actionid,
        int $courseid,
        int $userid,
        ?string $threadid,
        ?int $cmid = null,
        ?string $clientrequestid = null
    ): int {
        global $DB;

        return (int) $DB->insert_record(self::TABLE, (object) [
            'actionid' => $actionid,
            'courseid' => $courseid,
            'userid' => $userid,
            'threadid' => $threadid,
            'clientrequestid' => $clientrequestid,
            'cmid' => $cmid,
            'timecreated' => self::now(),
            'erasurerequested' => null,
            'erasureattempts' => 0,
            'erasurefailedat' => null,
        ]);
    }

    /**
     * A new client request id: a random UUID v4, which names nobody.
     *
     * @return string
     */
    public static function new_client_request_id(): string {
        return \core\uuid::generate();
    }

    /**
     * Link a recorded request to the thread the course service answered with.
     *
     * Generation and erasure take different locks (the erasure lock is never held across the
     * /activity/init call), so an erasure may have deleted the row while the call was in flight. The
     * thread then exists on the service with no row here: it is recorded again, already pending
     * erasure, so the next erasure run removes it (internal security review, 2026-11-20). A row still pending erasure simply
     * gets its thread id, which the next run sends.
     *
     * @param int $requestid The row recorded before the call.
     * @param string $threadid The thread id.
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string|null $clientrequestid The client request id the row was recorded under.
     * @return int The id of the row that now carries the thread: $requestid, or the re-recorded row.
     */
    public static function attach_thread(
        int $requestid,
        string $threadid,
        int $actionid,
        int $courseid,
        int $userid,
        ?string $clientrequestid
    ): int {
        global $DB;
        if ($DB->record_exists(self::TABLE, ['id' => $requestid])) {
            $DB->set_field(self::TABLE, 'threadid', $threadid, ['id' => $requestid]);
            return $requestid;
        }

        return self::record_pending_erasure($actionid, $courseid, $userid, $threadid, null, $clientrequestid);
    }

    /**
     * Link a recorded request to the module it generated, once the generation is over.
     *
     * After attach_thread() the generation keeps using the thread for minutes (the stream, the plan
     * approval, the result), and the service may write to it all along. An erasure verified in that
     * window deletes the row while the thread is still in use. When the row is gone, or an erasure of
     * the student is in flight right now (it may delete a row it stamped), the request is recorded
     * again with its thread, key and module, already pending erasure, so the next erasure run removes
     * what the generation wrote after it. A row stamped but not being sent stays pending and gets the
     * module (internal security review, 2026-11-20).
     *
     * @param int $requestid The row carrying the thread.
     * @param int $cmid The generated module.
     * @param string $threadid The thread id.
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string|null $clientrequestid The client request id the row was recorded under.
     * @return int The id of the row that now carries the module: $requestid, or the re-recorded row.
     */
    public static function attach_module(
        int $requestid,
        int $cmid,
        string $threadid,
        int $actionid,
        int $courseid,
        int $userid,
        ?string $clientrequestid
    ): int {
        if (self::settle_row($requestid, $userid, $cmid)) {
            return $requestid;
        }

        return self::record_pending_erasure($actionid, $courseid, $userid, $threadid, $cmid, $clientrequestid);
    }

    /**
     * Keep a thread erasable when its generation failed after the service opened it.
     *
     * The same window as attach_module(): an erasure may have removed the row while the failed
     * generation was still using the thread. The request is then recorded again with its thread and
     * key, already pending erasure (internal security review, 2026-11-20). A row still there is left
     * as it is.
     *
     * @param int $requestid The row carrying the thread.
     * @param string $threadid The thread id.
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string|null $clientrequestid The client request id the row was recorded under.
     * @return int The id of the row that now carries the thread: $requestid, or the re-recorded row.
     */
    public static function release_thread(
        int $requestid,
        string $threadid,
        int $actionid,
        int $courseid,
        int $userid,
        ?string $clientrequestid
    ): int {
        if (self::settle_row($requestid, $userid, null)) {
            return $requestid;
        }

        return self::record_pending_erasure($actionid, $courseid, $userid, $threadid, null, $clientrequestid);
    }

    /**
     * Whether a request row still stands for its thread once the generation is over, linking its module.
     *
     * Checked under the student's erasure lock, so no erasure can delete the row between the check
     * and the end of the generation. When that lock is busy an erasure is being sent and may delete
     * the row once verified: the row then does not count.
     *
     * @param int $requestid The row.
     * @param int $userid The student.
     * @param int|null $cmid The generated module to link, or null.
     * @return bool False when the row is gone or an erasure of the student is in flight.
     */
    private static function settle_row(int $requestid, int $userid, ?int $cmid): bool {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_coursedynamicrules')
            ->get_lock('remote_erasure_' . $userid, 5);
        if (!$lock) {
            return false;
        }
        try {
            if (!$DB->record_exists(self::TABLE, ['id' => $requestid])) {
                return false;
            }
            if ($cmid !== null) {
                $DB->set_field(self::TABLE, 'cmid', $cmid, ['id' => $requestid]);
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Record a request again, already pending erasure.
     *
     * @param int $actionid The createaiactivity action.
     * @param int $courseid The course.
     * @param int $userid The student.
     * @param string $threadid The thread id.
     * @param int|null $cmid The generated module, when it exists.
     * @param string|null $clientrequestid The client request id.
     * @return int The new row id.
     */
    private static function record_pending_erasure(
        int $actionid,
        int $courseid,
        int $userid,
        string $threadid,
        ?int $cmid,
        ?string $clientrequestid
    ): int {
        global $DB;
        $id = self::record_request($actionid, $courseid, $userid, $threadid, $cmid, $clientrequestid);
        $DB->set_field(self::TABLE, 'erasurerequested', self::now(), ['id' => $id]);

        return $id;
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
     * Send what is pending erasure for a student, verify it, and forget the rows the service erased.
     *
     * When every request of the student is pending, one SCOPE_USER request covers them all, rows
     * without a thread id or key included. Otherwise only the pending rows with a thread id or a
     * client request id are sent, by thread and key; pending rows with neither wait (see
     * remote_erasure_client). An "erased" answer is then checked with an export of the same threads
     * and keys: the rows are deleted only when it returns no request and no thread for them, and a row
     * known only by its key only when the answer carried erased_thread_ids (a service that understands
     * keys; otherwise the row stays pending and a failed attempt is counted on it). A refused
     * or failed erasure, an export that still returns something and an export that cannot be retrieved
     * all keep the rows pending and count a failed attempt on them. Serialised per student, so the
     * scheduled task and a privacy request never send the same rows twice at once.
     *
     * @param int $userid The student.
     * @return bool|null True when the service confirmed and the export verified it, false when not or
     *     when it could not be asked, null when there was nothing that could be sent.
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
                $send = array_values(array_filter(
                    $pending,
                    fn(\stdClass $row): bool => self::thread_of($row) !== null || self::key_of($row) !== null
                ));
                if ($send === []) {
                    return null;
                }
            }
            $threadids = array_values(array_unique(array_filter(array_map([self::class, 'thread_of'], $send))));
            $keys = array_values(array_unique(array_filter(array_map([self::class, 'key_of'], $send))));

            try {
                $result = self::client()->erase(self::SERVICE_ID, $userid, $scope, $threadids, $keys);
            } catch (\Throwable $e) {
                debugging('local_coursedynamicrules: the remote erasure of user ' . $userid . ' failed, it stays pending: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
                $result = new remote_erasure_result(false);
            }
            if (!$result->confirmed || !self::verified($userid, $threadids, $keys)) {
                self::record_failure($userid, $send);
                return false;
            }

            // A service that does not answer erased_thread_ids ignores keys: for a row known only by its
            // key, its "erased" and its empty export prove nothing. Such rows stay pending.
            $unproven = $result->keysunderstood ? [] : array_values(array_filter(
                $send,
                fn(\stdClass $row): bool => self::thread_of($row) === null && self::key_of($row) !== null
            ));
            $erased = array_diff(array_column($send, 'id'), array_column($unproven, 'id'));
            if ($erased !== []) {
                [$insql, $params] = $DB->get_in_or_equal(array_values($erased), SQL_PARAMS_NAMED);
                $DB->delete_records_select(self::TABLE, "id $insql", $params);
            }
            if ($unproven !== []) {
                self::record_failure($userid, $unproven);
                return false;
            }

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
     * The students whose erasure has been pending longer than the configured number of days.
     *
     * @param int|null $days The days, or null for the remoteerasurependingdays setting.
     * @param int $limit The most students returned (the settings page shows at most this many).
     * @return \stdClass[] One item per student, the longest waiting first: userid, requests (pending),
     *     firstrequested, attempts (the most consecutive failures of a row) and lastfailure (or null).
     */
    public static function overdue_pending(?int $days = null, int $limit = self::MAX_OVERDUE_LISTED): array {
        global $DB;
        $sql = 'SELECT userid, COUNT(1) AS requests, MIN(erasurerequested) AS firstrequested,
                       MAX(erasureattempts) AS attempts, MAX(erasurefailedat) AS lastfailure
                  FROM {' . self::TABLE . '}
                 WHERE erasurerequested IS NOT NULL
              GROUP BY userid
                HAVING MIN(erasurerequested) <= :cutoff
              ORDER BY MIN(erasurerequested) ASC, userid ASC';

        return array_values($DB->get_records_sql($sql, ['cutoff' => self::overdue_cutoff($days)], 0, $limit));
    }

    /**
     * How many students' erasures have been pending longer than the configured number of days.
     *
     * The settings page lists at most MAX_OVERDUE_LISTED of them and says how many more there are.
     *
     * @param int|null $days The days, or null for the remoteerasurependingdays setting.
     * @return int
     */
    public static function count_overdue_pending(?int $days = null): int {
        global $DB;
        $sql = 'SELECT COUNT(1)
                  FROM (SELECT userid
                          FROM {' . self::TABLE . '}
                         WHERE erasurerequested IS NOT NULL
                      GROUP BY userid
                        HAVING MIN(erasurerequested) <= :cutoff) overdue';

        return (int) $DB->count_records_sql($sql, ['cutoff' => self::overdue_cutoff($days)]);
    }

    /**
     * The time before which a pending erasure counts as overdue.
     *
     * @param int|null $days The days, or null for the remoteerasurependingdays setting.
     * @return int
     */
    private static function overdue_cutoff(?int $days): int {
        return self::now() - ($days ?? self::pending_days()) * DAYSECS;
    }

    /**
     * The remoteerasurependingdays setting, at least one day.
     *
     * @return int
     */
    public static function pending_days(): int {
        $days = (int) get_config('local_coursedynamicrules', 'remoteerasurependingdays');
        return $days > 0 ? $days : self::DEFAULT_PENDING_DAYS;
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
     * The client request id of a row, or null when none was recorded.
     *
     * @param \stdClass $row The row.
     * @return string|null
     */
    private static function key_of(\stdClass $row): ?string {
        $key = $row->clientrequestid ?? null;
        return $key === null || $key === '' ? null : (string) $key;
    }

    /**
     * Whether an export of the erased threads and keys returns nothing of them any more.
     *
     * Rows with neither a thread id nor a key (recorded by the upgrade) cannot be named in an export;
     * when only such rows were erased there is nothing to verify, and the "erased" answer stands. An
     * export that cannot be retrieved, or that lacks the requests or threads list, verifies nothing.
     *
     * @param int $userid The student.
     * @param string[] $threadids The erased threads.
     * @param string[] $keys The erased client request ids.
     * @return bool
     */
    private static function verified(int $userid, array $threadids, array $keys): bool {
        if ($threadids === [] && $keys === []) {
            return true;
        }
        try {
            $data = \core\di::get(remote_export_client::class)->export(self::SERVICE_ID, $userid, $threadids, $keys);
        } catch (\Throwable $e) {
            $data = null;
        }
        // Only an answer carrying both lists can show that nothing is left (internal security review,
        // 2026-11-20): one missing either list verifies nothing.
        if (!is_array($data) || !is_array($data['requests'] ?? null) || !is_array($data['threads'] ?? null)) {
            return false;
        }

        return $data['requests'] === [] && $data['threads'] === [];
    }

    /**
     * Count a failed or unverified attempt on the rows, and report the crossing of FAILURE_THRESHOLD.
     *
     * The event is fired only by the attempt that brings a row to the threshold, so a student whose
     * erasure keeps failing produces one event, not one per run.
     *
     * @param int $userid The student.
     * @param \stdClass[] $rows The rows that were sent.
     * @return void
     */
    private static function record_failure(int $userid, array $rows): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_column($rows, 'id'), SQL_PARAMS_NAMED);
        $params['now'] = self::now();
        $DB->execute(
            'UPDATE {' . self::TABLE . '}
                SET erasureattempts = erasureattempts + 1, erasurefailedat = :now
              WHERE id ' . $insql,
            $params
        );

        $attempts = array_map(fn(\stdClass $row): int => (int) ($row->erasureattempts ?? 0) + 1, $rows);
        if (!in_array(self::FAILURE_THRESHOLD, $attempts, true)) {
            return;
        }
        \local_coursedynamicrules\event\remote_erasure_failed::create([
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => ['attempts' => max($attempts), 'requests' => count($rows)],
        ])->trigger();
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
