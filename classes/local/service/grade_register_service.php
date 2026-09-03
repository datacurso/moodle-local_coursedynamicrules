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

/**
 * The record of which activities this plugin generated, and for whom.
 *
 * It answers two questions, and they have DIFFERENT lifetimes - which is the whole shape of this
 * class:
 *
 * - Has this student already been given a reinforcement by this action? Asked BEFORE the paid AI
 *   call, so a rule that keeps matching does not keep generating and keep charging. This is
 *   personal (it names a student) and it is only meaningful while the action exists.
 * - Which activities in this course did we generate, and how does each one count? The enrolment
 *   sweep needs that: a student who arrives later has to be shielded from every one of them, and
 *   nothing in the course itself marks them out. This half is NOT personal and it has to survive
 *   everything except the course itself.
 *
 * So the rows are never deleted for either of those reasons - they are SEVERED. Deleting the action
 * clears `actionid`; a privacy erasure clears `userid`. Either way the course-and-column half
 * survives and the sweep keeps working, because a deleted rule does not delete the graded column it
 * created. Measured before this was fixed: delete the rule, enrol one student, and their course
 * total reads 0% under Lowest grade against a baseline of 80%.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_register_service {
    /** @var string The register table. */
    public const TABLE = 'local_coursedynamicrules_aigrade';

    /**
     * Whether this action has already generated an activity for this student.
     *
     * Asked before the AI call, not after: the cost of a duplicate is a paid generation plus a
     * second activity the student never asked for.
     *
     * @param int $actionid
     * @param int $userid
     * @return bool
     */
    public static function already_generated(int $actionid, int $userid): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['actionid' => $actionid, 'userid' => $userid]);
    }

    /**
     * Record that an activity was generated.
     *
     * Always inserts. The caller has already asked already_generated(), and a second row for the
     * same pair means two activities really do exist - hiding that by updating in place would make
     * the register disagree with the course.
     *
     * @param int $courseid
     * @param int $ruleid
     * @param int $actionid
     * @param int $userid The one student the activity was generated for.
     * @param int $cmid The generated course module.
     * @param string $grademode One of the grade_isolation_service::MODE_* constants.
     * @return int The new row id.
     */
    public static function record_generation(
        int $courseid,
        int $ruleid,
        int $actionid,
        int $userid,
        int $cmid,
        string $grademode
    ): int {
        global $DB;

        return (int) $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'ruleid' => $ruleid,
            'actionid' => $actionid,
            'userid' => $userid,
            'cmid' => $cmid,
            'grademode' => grade_isolation_service::clean_mode($grademode),
            'timecreated' => time(),
        ]);
    }

    /**
     * Every generated activity recorded for a course.
     *
     * @param int $courseid
     * @return \stdClass[] Keyed by row id, carrying cmid, userid and grademode.
     */
    public static function generated_in_course(int $courseid): array {
        global $DB;

        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'id ASC',
            'id, cmid, userid, grademode');
    }

    /**
     * The display name of an activity, for putting in front of the teacher.
     *
     * @param int $cmid
     * @return string Empty when the module is gone.
     */
    public static function activity_name(int $cmid): string {
        $cm = get_coursemodule_from_id(null, $cmid, 0, false, IGNORE_MISSING);

        return $cm ? format_string($cm->name) : '';
    }

    /**
     * Release an action's duplicate guard, keeping the column marker.
     *
     * The action is gone, so blocking regeneration on its behalf is meaningless - but the
     * activities it created are still in the course with live grade columns, and the enrolment
     * sweep is the only thing that shields a later arrival from them. Deleting these rows was
     * measured to leave every subsequent student at 0% under Lowest grade, permanently, because
     * the sweep's course gate then answers no forever.
     *
     * `actionid = 0` matches no real action, so already_generated() stops finding these rows -
     * which is exactly what "the action is gone" should mean.
     *
     * @param int $actionid
     */
    public static function forget_action(int $actionid): void {
        global $DB;

        $DB->set_field(self::TABLE, 'actionid', 0, ['actionid' => $actionid]);
    }

    /**
     * Forget who a course's reinforcements were generated for, keeping the column markers.
     *
     * Used by the privacy provider. Severing rather than deleting for the same reason as
     * forget_action(): the column outlives the record, and the sweep needs to know it exists.
     * Without a recipient the row can no longer spare anybody, so "counts for its recipient"
     * degrades to "counts for nobody" - the safe direction, and the honest one: after an erasure
     * the plugin genuinely no longer knows who to spare.
     *
     * @param int $courseid
     * @param int[] $userids Empty means every student in the course.
     */
    public static function forget_users(int $courseid, array $userids = []): void {
        global $DB;

        if (empty($userids)) {
            $DB->set_field(self::TABLE, 'userid', 0, ['courseid' => $courseid]);

            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'u');
        $params['courseid'] = $courseid;
        $DB->set_field_select(self::TABLE, 'userid', 0, "courseid = :courseid AND userid $insql", $params);
    }
}
