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

namespace local_coursedynamicrules\privacy;

use context;
use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\local\service\grade_register_service;

/**
 * Privacy provider for local_coursedynamicrules.
 *
 * Rules, conditions and actions hold course configuration only - no personal data. The one table
 * that does name a student is the reinforcement register: it records that a named student had an
 * activity generated for them, and which activity it was.
 *
 * The plugin also transfers course and user context to an external AI service when the "create AI
 * activity" action runs, declared here so administrators can account for it. User-linked side
 * effects that stay inside Moodle (messages, adhoc tasks, activity availability) live in core
 * subsystems that declare their own privacy metadata.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    /**
     * Describe the personal data this plugin holds and the data leaving Moodle.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            grade_register_service::TABLE,
            [
                'courseid' => 'privacy:metadata:aigrade:courseid',
                'ruleid' => 'privacy:metadata:aigrade:ruleid',
                'actionid' => 'privacy:metadata:aigrade:actionid',
                'userid' => 'privacy:metadata:aigrade:userid',
                'cmid' => 'privacy:metadata:aigrade:cmid',
                'grademode' => 'privacy:metadata:aigrade:grademode',
                'timecreated' => 'privacy:metadata:aigrade:timecreated',
            ],
            'privacy:metadata:aigrade'
        );

        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'userid' => 'privacy:metadata:datacurso_ai:userid',
                'courseid' => 'privacy:metadata:datacurso_ai:courseid',
                'courseurl' => 'privacy:metadata:datacurso_ai:courseurl',
                'prompt' => 'privacy:metadata:datacurso_ai:prompt',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }

    /**
     * Courses where a reinforcement activity was generated for this student.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {" . grade_register_service::TABLE . "} ag
                  JOIN {context} ctx ON ctx.instanceid = ag.courseid AND ctx.contextlevel = :courselevel
                 WHERE ag.userid = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Students who had a reinforcement activity generated in this course.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {' . grade_register_service::TABLE . '} WHERE courseid = :courseid',
            ['courseid' => $context->instanceid]
        );
    }

    /**
     * Export the register rows for the approved courses.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            $rows = $DB->get_records(
                grade_register_service::TABLE,
                ['courseid' => $context->instanceid, 'userid' => $userid],
                'timecreated ASC'
            );
            if (!$rows) {
                continue;
            }

            $data = [];
            foreach ($rows as $row) {
                $data[] = (object) [
                    'activity' => grade_register_service::activity_name((int) $row->cmid),
                    'grademode' => $row->grademode,
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $row->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_coursedynamicrules'),
                    get_string('privacy:path:aigrade', 'local_coursedynamicrules'),
                ],
                (object) ['generated' => $data]
            );
        }
    }

    /**
     * Forget who every reinforcement in a course was generated for.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof context_course) {
            return;
        }

        grade_register_service::forget_users((int) $context->instanceid);
    }

    /**
     * Forget who one student's reinforcements were generated for, in the approved courses.
     *
     * Erasure SEVERS the row - it clears `userid` - rather than deleting it, and the reason is not
     * tidiness. The row's other half is not personal at all: it records that a generated activity
     * with a live grade column exists in this course, and that record is the only thing the
     * enrolment sweep has to shield later arrivals from that column. Deleting it was measured to
     * leave every student who enrols afterwards at 0% under Lowest grade against a baseline of 80%,
     * permanently - a third party paying for somebody else's erasure request.
     *
     * Two consequences are deliberate. The rule may generate for that student again, because the
     * marker that prevented it is exactly what the request asked us to destroy. And a reinforcement
     * that counted for its recipient stops counting for anybody, because without a recipient the
     * plugin no longer knows who to spare - the safe direction, and the honest one.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            grade_register_service::forget_users((int) $context->instanceid, [$userid]);
        }
    }

    /**
     * Forget who several students' reinforcements were generated for, in one course.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        grade_register_service::forget_users((int) $context->instanceid, $userids);
    }
}
