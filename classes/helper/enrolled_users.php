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

namespace local_coursedynamicrules\helper;

/**
 * Bounded walk over the active enrolled users of a course, ids only, one page at a time.
 *
 * The scheduled tasks evaluate every enrolled user of every course that has an active rule (two
 * of them every minute, see db/tasks.php), and the rule engine reads exactly one field of each of
 * them: the id. Loading every user record at once (get_enrolled_users(..., 'u.*', ...)) therefore
 * holds in memory a full copy of a course's user table for the whole run.
 *
 * This helper yields, at rest, the same population as get_enrolled_users(..., $onlyactive = true)
 * - active enrolments only, each user once however many enrolments they hold - but as a generator
 * that fetches pages of ids, so the memory held at any moment is one page, not one course.
 *
 * Pages are keyed on the last id seen ("WHERE u.id > :lastid ORDER BY u.id"), not on an offset.
 * An offset counts rows that precede it, and that count moves when a user before the cursor is
 * unenrolled while the task runs: the next page then starts one row late and one user is never
 * evaluated. A keyset cursor does not depend on how many rows precede it, so a removal behind it
 * cannot shift it.
 *
 * It is NOT a snapshot. Every page re-evaluates the enrolment, so a user unenrolled or suspended
 * AHEAD of the cursor during the run is not yielded, and a user enrolled ahead of it (any new
 * account, since ids grow) is yielded in the same run, where a single query would have waited for
 * the next one. The "now" used for enrolment time windows is fixed once, by get_enrolled_sql().
 * The tasks must not assume otherwise.
 *
 * On the front page course the enrolment join degenerates to every non-deleted user of the site,
 * for get_enrolled_users() and for this helper alike.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolled_users {
    /**
     * Yield the id of every active enrolled user of the course, in ascending id order.
     *
     * Each yielded value is an object with a single int property, "id", so the rule engine can keep
     * reading $user->id unchanged. Keys are a running counter, not user ids.
     *
     * The page size is validated here, outside the generator, so a bad value fails at the call and
     * not at the first iteration somewhere else, or never if the result is not iterated.
     *
     * @param \context_course $context The course context.
     * @param int $pagesize How many ids to fetch per query. Must be at least one.
     * @return \Generator<int, \stdClass> Objects carrying only "id".
     * @throws \coding_exception When the page size is below one.
     */
    public static function ids(\context_course $context, int $pagesize): \Generator {
        if ($pagesize < 1) {
            throw new \coding_exception('The page size must be at least one.', "Got {$pagesize}.");
        }

        return self::walk($context, $pagesize);
    }

    /**
     * The paged walk itself.
     *
     * @param \context_course $context The course context.
     * @param int $pagesize Validated page size.
     * @return \Generator<int, \stdClass>
     */
    private static function walk(\context_course $context, int $pagesize): \Generator {
        global $DB;

        // Active enrolments only; core already restricts to u.deleted = 0 and de-duplicates with
        // DISTINCT, so a user with two enrolments appears once.
        [$enrolledsql, $params] = get_enrolled_sql($context, '', 0, true);
        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN ($enrolledsql) je ON je.id = u.id
                 WHERE u.id > :lastid
              ORDER BY u.id";

        $lastid = 0;
        do {
            // Merged so the cursor wins should core ever emit a parameter of the same name.
            $page = $DB->get_records_sql($sql, array_merge($params, ['lastid' => $lastid]), 0, $pagesize);
            // Rows are keyed by the first column, so the page count relies on u.id
            // being unique per row: keep u.id as the first and only selected column.
            foreach ($page as $user) {
                $lastid = (int) $user->id;
                $user->id = $lastid;
                yield $user;
            }
        } while (count($page) === $pagesize);
    }
}
