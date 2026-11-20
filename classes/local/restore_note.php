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
 * Durable notes of the restrictions a restore could not attribute to the plugin.
 *
 * A restriction the restore left ambiguous (two candidate nodes) or attributed only by deduction (a
 * re-adopted marker) still lists the user, but the privacy provider cannot export or erase it as the
 * plugin's. The restore log said so to the operator; these rows say it to the user, one per user the
 * restriction lists, so their privacy export names the activity (CDR-PRIV-002-R2). A row holds no
 * other user's id. The reconciliation removes the rows of a module it resolves, a privacy erasure
 * removes the user's rows, and deleting the module, the course or the account removes theirs.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_note {
    /** @var string The table. */
    public const TABLE = 'local_coursedynamicrules_restnote';

    /** @var string More than one node matched, and none was attributed. */
    public const REASON_AMBIGUOUS = 'ambiguous';

    /** @var string A node was attributed by deduction, which the privacy provider does not trust. */
    public const REASON_INFERRED = 'inferred';

    /**
     * Record that a restriction of a module lists these users and could not be attributed.
     *
     * Idempotent: a user already noted for the same module, action and reason is not noted twice.
     *
     * @param int $courseid The course of the module.
     * @param int $cmid The module.
     * @param int|null $actionid The restored action the restriction may belong to, if known.
     * @param int[] $userids The users the restriction lists.
     * @param string $reason One of the REASON_ constants.
     * @return void
     */
    public static function record(int $courseid, int $cmid, ?int $actionid, array $userids, string $reason): void {
        global $DB;

        $userids = array_values(array_filter(array_unique(array_map('intval', $userids)), static fn(int $id) => $id > 0));
        if ($userids === []) {
            return;
        }
        // One read of who is already noted, one batched insert of the rest (internal security review,
        // 2026-11-20): a restriction can list a whole cohort.
        $noted = array_map('intval', $DB->get_fieldset_select(
            self::TABLE,
            'userid',
            'cmid = :cmid AND reason = :reason AND ' . ($actionid === null ? 'actionid IS NULL' : 'actionid = :actionid'),
            ['cmid' => $cmid, 'reason' => $reason, 'actionid' => $actionid]
        ));
        $now = time();
        $rows = [];
        foreach (array_diff($userids, $noted) as $userid) {
            $rows[] = (object) [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'actionid' => $actionid,
                'userid' => $userid,
                'reason' => $reason,
                'timecreated' => $now,
            ];
        }
        if ($rows !== []) {
            $DB->insert_records(self::TABLE, $rows);
        }
    }

    /**
     * Forget the notes of a module, of one action or of all of them.
     *
     * @param int $cmid The module.
     * @param int|null $actionid Only this action's notes, or null for every note of the module.
     * @return void
     */
    public static function forget_module(int $cmid, ?int $actionid = null): void {
        global $DB;

        $conditions = ['cmid' => $cmid];
        if ($actionid !== null) {
            $conditions['actionid'] = $actionid;
        }
        $DB->delete_records(self::TABLE, $conditions);
    }

    /**
     * Forget the inferred notes of a module for the given actions, leaving every ambiguous note.
     *
     * @param int $cmid The module.
     * @param int[] $actionids The actions whose deduction was resolved or superseded.
     * @return void
     */
    public static function forget_inferred(int $cmid, array $actionids): void {
        global $DB;

        if ($actionids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $actionids), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            self::TABLE,
            "cmid = :cmid AND reason = :reason AND actionid $insql",
            $params + ['cmid' => $cmid, 'reason' => self::REASON_INFERRED]
        );
    }

    /**
     * Forget the notes of some users on a module, or of every user.
     *
     * @param int $cmid The module.
     * @param int[]|null $userids The users, or null for everyone.
     * @return void
     */
    public static function forget_users_in_module(int $cmid, ?array $userids): void {
        global $DB;

        // A read first: a privacy erasure walks every approved module, and nearly none has a note, so
        // an unconditional delete would add a database write per module to every erasure.
        if (!$DB->record_exists(self::TABLE, ['cmid' => $cmid])) {
            return;
        }
        if ($userids === null) {
            $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
            return;
        }
        if ($userids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "cmid = :cmid AND userid $insql", $params + ['cmid' => $cmid]);
    }

    /**
     * Forget every note of a user.
     *
     * @param int $userid The user.
     * @return void
     */
    public static function forget_user(int $userid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Forget every note of a course.
     *
     * @param int $courseid The course.
     * @return void
     */
    public static function forget_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }

    /**
     * The modules a user has notes on.
     *
     * @param int $userid The user.
     * @return int[]
     */
    public static function modules_of(int $userid): array {
        global $DB;
        $cmids = $DB->get_fieldset_select(self::TABLE, 'DISTINCT cmid', 'userid = :userid', ['userid' => $userid]);

        return array_map('intval', $cmids);
    }

    /**
     * The users noted on a module.
     *
     * @param int $cmid The module.
     * @return int[]
     */
    public static function users_in_module(int $cmid): array {
        global $DB;
        $userids = $DB->get_fieldset_select(self::TABLE, 'DISTINCT userid', 'cmid = :cmid', ['cmid' => $cmid]);

        return array_map('intval', $userids);
    }

    /**
     * The notes of a user on a module, oldest first.
     *
     * @param int $userid The user.
     * @param int $cmid The module.
     * @return \stdClass[] Rows with reason and timecreated.
     */
    public static function of_user_in_module(int $userid, int $cmid): array {
        global $DB;

        return array_values($DB->get_records(
            self::TABLE,
            ['userid' => $userid, 'cmid' => $cmid],
            'timecreated ASC, id ASC',
            'id, reason, timecreated'
        ));
    }
}
