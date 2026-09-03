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

namespace local_coursedynamicrules\local\service;

use context_course;
use grade_category;
use grade_item;

/**
 * Keeps a generated activity's grade from reaching anybody it was not generated for.
 *
 * One guarantee, one mechanism: the activity's column counts for the student it was generated for,
 * and for nobody else.
 *
 * The problem it solves is not the plugin's doing. A generated activity is restricted to one
 * student through `course_modules.availability`, and the gradebook never reads that field: the new
 * grade item is a column for the WHOLE course, empty for everybody it was hidden from. Whether that
 * empty column costs those students points depends on "Exclude empty grades", which any teacher can
 * untick - and under "Lowest grade" it takes their course total to zero. Measured on a student who
 * had 80/100: 40% under Mean, 0% under Lowest grade.
 *
 * The mechanism is per-student exclusion - `grade_grades.excluded`, the same flag a teacher can set
 * by hand in the Single view report. Core drops excluded items in the normalisation loop of
 * grade_category::aggregate_grades() (lib/grade/grade_category.php:701), which runs BEFORE the
 * aggregation method is chosen at :1039, so it holds for all nine methods rather than for the
 * handful that read a coefficient.
 *
 * Three things about it were measured rather than reasoned, and each one decided the implementation:
 *
 * - Grade CATEGORIES cannot do this. They configure a whole column for everybody at once and have
 *   no per-student dimension at all; putting the item in one and leaning on coefficients protects
 *   under four of the nine methods. Worse, excluding a student inside a category leaves that
 *   category empty for them, and the empty category rolls up as a ZERO one level higher - which is
 *   why {@see self::pin_to_root()} exists.
 * - Creating the row with update_final_grade($userid, null) - the way core's Single view does it -
 *   sets `overridden`, and grade_update() then refuses to touch that grade forever. Rows are
 *   inserted directly with overridden = 0 instead.
 * - Doing it one student at a time through the grade API costs about 26 database reads each. The
 *   bulk path costs two or three for the whole cohort and protects identically.
 *
 * Two configurations of the one mechanism, which is the whole product surface:
 *
 * - MODE_NOGRADE: everybody is excluded, the recipient included. The activity may still be graded
 *   by the module and the teacher can still use that grade as feedback, but it counts for nobody.
 * - MODE_OWN: everybody except the recipient is excluded. It counts for them as an ordinary
 *   activity - pending work while they have not done it, exactly like any other activity they have
 *   not done, and a grade once they have.
 *
 * What this service removes is the anomaly: the column reaching people who cannot see it.
 *
 * A student who enrols, is reactivated, or is given a gradebook role after generation has no row,
 * so the empty column counts against them. That is swept up by
 * {@see \local_coursedynamicrules\observer\user_enrolled}.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_isolation_service {
    /** @var string The generated activity's grade counts for nobody. Default. */
    public const MODE_NOGRADE = 'nograde';

    /** @var string It counts for the one student it was generated for, and for nobody else. */
    public const MODE_OWN = 'own';

    /**
     * Every mode a rule may store.
     *
     * @return string[]
     */
    public static function modes(): array {
        return [self::MODE_NOGRADE, self::MODE_OWN];
    }

    /**
     * Normalise a stored or submitted mode, falling back to the safe default.
     *
     * Rules saved before this option existed carry no value at all, and the fallback has to be the
     * mode that cannot reach anybody rather than the one that reproduces the old behaviour.
     *
     * @param mixed $value
     * @return string
     */
    public static function clean_mode($value): string {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::modes(), true) ? $value : self::MODE_NOGRADE;
    }

    /**
     * Turn the form's single yes/no into a stored mode.
     *
     * @param mixed $hasgrade Whether the activity's grade should count for its recipient.
     * @return string
     */
    public static function mode_from_choice($hasgrade): string {
        return empty($hasgrade) ? self::MODE_NOGRADE : self::MODE_OWN;
    }

    /**
     * Ask the module to be created ungraded - a request, not the guarantee.
     *
     * Setting the module's own grade setting is the only way to stop a column existing at all:
     * patching the grade item afterwards does not hold, because a module rewrites its own item from
     * its own settings every time it pushes grades (mod/assign/lib.php:1044, mod/quiz/lib.php:680).
     *
     * But `grade` is not a field every module reads, and the set of modules that can arrive here
     * is not closed: local_coursegen's validate_mod_existence() accepts ANY installed, enabled
     * module that has a mod_form.php (create_mod_service.php:117-130), so its two handler families
     * - mod_parameters/ and mod_settings/ - are a convenience, not a whitelist.
     *
     * Measured on the ones that matter: assign, quiz, lesson and h5pactivity honour `grade` and
     * produce no grade item at all when it is 0 (h5pactivity explicitly, at
     * mod/h5pactivity/classes/local/grader.php:90-99). scorm ignores it entirely and goes by
     * `grademethod` and `maxgrade` (mod/scorm/lib.php:677-689). folder, imscp, resource, url and
     * label never create an item either way.
     *
     * So this is best effort and nothing more, in BOTH directions: it works for some modules and
     * is inert for others, and a teacher can undo it afterwards by setting a maximum grade. The
     * guarantee that the column reaches nobody it should not is the per-student exclusion in
     * apply(), plus {@see \local_coursedynamicrules\observer\grade_item_created} for the column
     * that appears later - neither of which cares whether the module complied.
     *
     * @param array $parameters The module parameters about to be handed to the creation service.
     * @param string $mode One of the MODE_* constants.
     * @return array The parameters, with grading switched off when the mode calls for it.
     */
    public static function prepare_payload(array $parameters, string $mode): array {
        if (self::clean_mode($mode) !== self::MODE_NOGRADE) {
            return $parameters;
        }
        $parameters['grade'] = 0;

        return $parameters;
    }

    /**
     * Shield everybody the activity was not generated for.
     *
     * Safe to call repeatedly: exclusion is idempotent and never touches a grade that already
     * exists, so re-running it cannot destroy work.
     *
     * @param int $courseid Course the activity belongs to.
     * @param string $modname Module name, e.g. 'scorm'.
     * @param int $instance Module instance id.
     * @param string $mode One of the MODE_* constants.
     * @param int $recipientuserid The student the activity was generated for. Zero spares nobody,
     *            which is the safe direction: the column then counts for no one instead of everyone.
     * @return int Students shielded, 0 when the activity has nothing to grade, or a NEGATIVE count
     *             when gradable items exist and nobody could be resolved to shield - a failure the
     *             caller has to surface rather than read as "no work needed".
     */
    public static function apply(
        int $courseid,
        string $modname,
        int $instance,
        string $mode = self::MODE_NOGRADE,
        int $recipientuserid = 0
    ): int {
        $items = self::gradable_items($courseid, $modname, $instance);

        if (empty($items)) {
            // Resources such as folder, resource or imscp produce no grade item at all: there is no
            // column, so there is nothing for anybody to be shielded from.
            return 0;
        }

        $users = self::gradebook_users($courseid);
        if (empty($users)) {
            return -count($items);
        }

        // Under "no grade" the module was asked not to produce a column and produced one anyway
        // (which is the normal outcome for scorm and h5pactivity). Shielding everybody, the
        // recipient included, delivers what was asked for regardless: the column counts for nobody.
        $keep = self::clean_mode($mode) === self::MODE_OWN ? ($recipientuserid ?: null) : null;

        $shielded = 0;
        foreach ($items as $item) {
            // The shield is only valid at the top level, so put it there rather than trusting it
            // already is. None of the five module types local_coursegen generates places its own
            // item in a category, and grade_update() cannot either - `categoryid` is not among the
            // fields it accepts (lib/gradelib.php:69). The live route is the AI payload: coursegen
            // hands `parameters` to add_moduleinfo() unfiltered, and add_moduleinfo() honours a
            // `gradecat` key (course/modlib.php:259-281) - with gradecat = -1 core even creates the
            // category. Measured: without this call the bystander goes to 40% under Mean and 0%
            // under Lowest grade.
            self::pin_to_root($item);
            $shielded += self::exclude_all_but($item, $users, $keep);
        }

        return $shielded;
    }

    /**
     * Put a generated activity's grade item back at the top level, where the shield works.
     *
     * The exclusion only holds while the item is a direct child of the course. Inside a grade
     * category the excluded item leaves that category empty for the student, and the category's own
     * total then rolls up as a zero one level higher - measured at 40% under Mean and 0% under
     * Lowest grade for a student who had 80%, with every exclusion row still present and correct.
     *
     * This runs at GENERATION only. It does not react to a teacher filing the activity away
     * afterwards, and that is deliberate: an observer that undid the move was measured to orphan
     * the grade item, because core calls set_parent() and then move_after_sortorder() on the same
     * in-memory object (grade/edit/tree/index.php:141-147) and set_parent() fires the event before
     * it returns - so the stale write restored a category id the observer had already deleted, and
     * every gradebook page in the course then threw. The hole it defended against is Moodle's own
     * behaviour for any excluded grade filed into a category, with or without this plugin, and the
     * form's help says so. The cure was worse than the disease.
     *
     * A category the activity was BORN in and left with no children is deleted, which is safe here
     * because nothing outside this call is holding a stale reference to it. An empty category has a
     * null total, and a null total that is not excluded counts as a ZERO for everybody under
     * "Exclude empty grades" off. A category that still holds other activities is left as it is.
     *
     * @param grade_item $item
     * @return bool Whether the item had to be moved.
     */
    public static function pin_to_root(grade_item $item): bool {
        $root = grade_category::fetch_course_category((int) $item->courseid);
        if (!$root || (int) $item->categoryid === (int) $root->id) {
            return false;
        }

        $left = grade_category::fetch(['id' => (int) $item->categoryid, 'courseid' => (int) $item->courseid]);

        if (!$left) {
            // The item points at a category that no longer exists, so set_parent() would die
            // dereferencing the missing parent (lib/grade/grade_item.php:1578-1581) and the failure
            // would be swallowed by whoever called us. Write the parent directly instead: an
            // orphaned item makes every gradebook page in the course throw, so repairing it matters
            // more than going through the API.
            $item->categoryid = (int) $root->id;
            $item->update();
            $item->force_regrading();

            return true;
        }

        // set_parent() refuses category and course items; only 'mod' items ever reach here.
        if (!$item->set_parent((int) $root->id)) {
            return false;
        }

        if ($left && !$left->is_course_category() && empty($left->get_children())) {
            $left->delete('local_coursedynamicrules');
        }

        debugging(
            get_string('error_grade_item_moved_back', 'local_coursedynamicrules', $item->itemname),
            DEBUG_DEVELOPER
        );

        return true;
    }

    /**
     * Whether a grade item sits directly under the course, which is the only place the shield works.
     *
     * @param grade_item $item
     * @return bool
     */
    public static function is_at_root(grade_item $item): bool {
        $root = grade_category::fetch_course_category((int) $item->courseid);

        return $root && (int) $item->categoryid === (int) $root->id;
    }

    /**
     * Shield a set of students from a single grade item.
     *
     * Writes `grade_grades` rows directly rather than going through update_final_grade(): that call
     * sets `overridden`, and an overridden grade can never again be written by the module (both
     * measured). It also costs about 26 reads per student, against two or three for this whole loop.
     *
     * An existing row keeps its grade untouched. Only the exclusion flag is set, and only when it is
     * not set already, so this is safe to run over and over.
     *
     * @param grade_item $item
     * @param int[] $userids Every user the gradebook aggregates for.
     * @param int|null $keep The one user to leave alone, if any.
     * @return int Rows written.
     */
    public static function exclude_all_but(grade_item $item, array $userids, ?int $keep): int {
        global $DB;

        // Unique, because a repeated id would otherwise be written twice - counted twice on the
        // update branch, and queued twice on the insert branch where the second copy collides with
        // the first inside the same statement.
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return 0;
        }

        $now = time();

        // Scoped to the users at hand rather than the whole column: the enrolment sweep asks about
        // exactly one student, and pulling a 500-row gradebook column to inspect one of them would
        // make the cheap path expensive again. Most of these rows usually DO exist: core creates
        // them for every enrolled user during any regrade (grade_category.php:655-668), and
        // creating the module leaves a regrade pending.
        $existing = static::read_existing_shields((int) $item->id, $userids);

        $inserts = [];
        $written = 0;

        foreach ($userids as $userid) {
            if ($keep !== null && $userid === (int) $keep) {
                continue;
            }

            if (isset($existing[$userid])) {
                if (empty($existing[$userid]->excluded)) {
                    // Nothing else on the row is touched: a grade already recorded here belongs to
                    // whoever earned it, and excluding it is not a reason to erase it.
                    $DB->set_field('grade_grades', 'excluded', $now, ['id' => $existing[$userid]->id]);
                    $written++;
                }
                continue;
            }

            $inserts[] = (object) [
                'itemid' => $item->id,
                'userid' => $userid,
                'rawgrademax' => $item->grademax,
                'rawgrademin' => $item->grademin,
                'rawscaleid' => $item->scaleid,
                'excluded' => $now,
                'overridden' => 0,
                'locked' => 0,
                'locktime' => 0,
                'hidden' => 0,
                'aggregationstatus' => 'unknown',
                'aggregationweight' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }

        if ($inserts) {
            $written += static::insert_shields($inserts);
        }

        if ($written) {
            $item->force_regrading();
        }

        return $written;
    }

    /**
     * Read the grade_grades rows that already exist for these users on this item.
     *
     * A method of its own, and reached through static:: rather than self::, for one reason: it is
     * the first half of a read-then-write, and the window between the two halves is where the only
     * concurrency hazard in this class lives. A test cannot open that window through the public API
     * - array_unique() in exclude_all_but() removes a duplicated id before anything is queued, so
     * feeding the same user twice produces no collision at all. Overriding this in a subclass is
     * how racing_grade_isolation_service (tests/fixtures/) inserts a colliding row at exactly the
     * moment production would lose the race.
     *
     * @param int $itemid
     * @param int[] $userids Already unique.
     * @return \stdClass[] Keyed by userid, carrying id and excluded.
     */
    protected static function read_existing_shields(int $itemid, array $userids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        return $DB->get_records_select('grade_grades',
            "itemid = :itemid AND userid $insql",
            array_merge(['itemid' => $itemid], $inparams),
            '', 'userid, id, excluded');
    }

    /**
     * Write the missing shield rows, surviving a row somebody else inserted first.
     *
     * grade_grades carries a unique key on (userid, itemid) (lib/db/install.xml:2070), and core
     * inserts the missing rows itself during any regrade (grade_category.php:655-668). Creating the
     * module sets needsupdate, so a regrade is PENDING at exactly the moment this runs: a teacher
     * opening the grader report, or a student loading the course page, is enough to land one between
     * the read above and this write.
     *
     * insert_records() batches into multi-row INSERTs, so a single collision aborts the whole chunk
     * - measured: every row of the batch is lost. The batch is still attempted first, because it
     * costs one query for the whole course; only when it actually collides does this fall back to
     * one row at a time, and a row that now exists is flagged instead of inserted.
     *
     * @param \stdClass[] $inserts
     * @return int Rows actually shielded.
     */
    protected static function insert_shields(array $inserts): int {
        global $DB;

        try {
            $DB->insert_records('grade_grades', $inserts);

            return count($inserts);
        } catch (\dml_exception $e) {
            // Somebody wrote at least one of these while we were deciding. Fall through.
            unset($e);
        }

        $written = 0;
        foreach ($inserts as $row) {
            $existing = $DB->get_record('grade_grades',
                ['itemid' => $row->itemid, 'userid' => $row->userid], 'id, excluded');

            if ($existing) {
                if (empty($existing->excluded)) {
                    $DB->set_field('grade_grades', 'excluded', $row->excluded, ['id' => $existing->id]);
                    $written++;
                }
                continue;
            }

            try {
                $DB->insert_record('grade_grades', $row);
                $written++;
            } catch (\dml_exception $e) {
                // Lost the race on this one row. Every other student still gets shielded, which is
                // the point of not batching here.
                unset($e);
            }
        }

        return $written;
    }

    /**
     * Every user the gradebook computes a total for, in one query.
     *
     * Core's own definition, from graded_users_iterator::init() (grade/lib.php:148-152): enrolled,
     * holding one of the $CFG->gradebookroles in the course or above it. Two details are load
     * bearing and were both wrong at first:
     *
     * - `onlyactive` is false, matching the iterator's own default (grade/lib.php:93). Hard-coding
     *   true left suspended and expired enrolments out of the shield while
     *   grade_category::generate_grades() kept aggregating them - it reads grade_grades rows and
     *   applies no enrolment filter at all - so their totals were quietly wrong and nothing would
     *   ever revisit them.
     * - What is NOT lifted is that method's opening call to export_verify_grades(), which throws the
     *   moment grades need regrading: exactly the state a course is in immediately after this plugin
     *   creates an activity in it. get_gradable_users() goes through the same iterator and is
     *   unusable here for the same reason.
     *
     * Erring wide is the safe direction: an extra exclusion row costs a row, a missing one costs
     * somebody their grade.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function gradebook_users(int $courseid): array {
        global $CFG, $DB;

        $roles = array_filter(array_map('trim', explode(',', (string) $CFG->gradebookroles)), 'strlen');
        if (empty($roles)) {
            // A site with no gradebook roles configured has no student totals to protect.
            return [];
        }

        $context = context_course::instance($courseid);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'grbr');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal(
            $context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'rel');
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, '', 0, false);

        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN ($enrolsql) je ON je.id = u.id
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE ra.roleid $rolesql
                   AND ra.contextid $ctxsql
                   AND u.deleted = 0";

        return array_map('intval', array_keys($DB->get_records_sql(
            $sql, array_merge($enrolparams, $roleparams, $ctxparams))));
    }

    /**
     * Whether one user is among those the gradebook computes a total for.
     *
     * One row, not the whole cohort: the enrolment sweep asks about a single student on every
     * enrolment and every role assignment on the site, and fetching a 1500-student list to answer
     * that would make the cheap path expensive again.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function is_gradebook_user(int $courseid, int $userid): bool {
        global $CFG, $DB;

        $roles = array_filter(array_map('trim', explode(',', (string) $CFG->gradebookroles)), 'strlen');
        if (empty($roles) || !$userid) {
            return false;
        }

        $context = context_course::instance($courseid);
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'grbr');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal(
            $context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'rel');
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, '', 0, false);

        $sql = "SELECT 1
                  FROM {user} u
                  JOIN ($enrolsql) je ON je.id = u.id
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE u.id = :theuser
                   AND ra.roleid $rolesql
                   AND ra.contextid $ctxsql
                   AND u.deleted = 0";

        return $DB->record_exists_sql($sql, array_merge(
            $enrolparams, $roleparams, $ctxparams, ['theuser' => $userid]));
    }

    /**
     * The grade items an activity contributes to aggregation.
     *
     * A module may create several: a rated forum creates two ('rating' and 'whole forum'), a
     * workshop two, and a folder none. Items with no grade type never take part in aggregation
     * (lib/grade/grade_item.php:1720-1735 filters on GRADE_TYPE_VALUE), so they are skipped.
     *
     * @param int $courseid
     * @param string $modname
     * @param int $instance
     * @return grade_item[]
     */
    public static function gradable_items(int $courseid, string $modname, int $instance): array {
        $items = grade_item::fetch_all([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instance,
        ]) ?: [];

        return array_filter($items, function (grade_item $item): bool {
            return in_array((int) $item->gradetype, [GRADE_TYPE_VALUE, GRADE_TYPE_SCALE], true);
        });
    }
}
