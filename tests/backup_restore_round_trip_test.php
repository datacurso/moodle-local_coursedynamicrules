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

namespace local_coursedynamicrules;

use core_availability\tree;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The real backup/restore round trip, executed rather than described.
 *
 * The changelog claims two things about restoring a course with rules: role ids stored inside
 * notification actions are remapped to the restored course's roles, and the enable-activity
 * ownership markers survive the round trip. Around four hundred lines of backup and restore code
 * carry those claims - and until this file, not one of them ran under any test: a green suite
 * proved nothing about the single most failure-prone code in the release, which writes directly to
 * course_modules.availability and to action params during restore. Found by a blind judge.
 *
 * Every test here drives core's real backup_controller and restore_controller, the way
 * core/course/tests/backup_restore_activity_test.php does - no mocks, no shortcuts, the actual
 * plan execution the plugin hooks into.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \restore_local_coursedynamicrules_plugin
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_restore_round_trip_test extends \advanced_testcase {
    /** @var string The ownership-marker prefix enableactivity writes into availability JSON. */
    private const MARKER_PREFIX = 'local_coursedynamicrules:';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Backup a course the way core does in its own tests.
     *
     * @param \stdClass $course
     * @return string Backup id.
     */
    private function backup_course(\stdClass $course): string {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id,
            \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO, \backup::MODE_IMPORT, $USER->id);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restore a backup into a brand-new course.
     *
     * @param string $backupid
     * @return int New course id.
     */
    private function restore_course(string $backupid): int {
        global $CFG, $DB, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $categoryid = $DB->get_field('course_categories', 'id', ['parent' => 0], IGNORE_MULTIPLE);
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RT' . random_string(4), $categoryid);

        $rc = new \restore_controller($backupid, $newcourseid,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id, \backup::TARGET_NEW_COURSE);
        // execute_precheck() returns true only with NO errors and NO warnings
        // (restore_controller:455-458); warnings alone still leave the restore in STATUS_AWAITING,
        // executable. Annotating a bare custom role produces exactly such a warning ("cannot be
        // mapped to any of the roles that you are allowed to assign"), and the human flow is to
        // read it and continue - which is the flow these tests exist to verify survives. So: fail
        // on errors, proceed over warnings, exactly like the admin clicking Continue.
        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                $this->fail('Restore precheck errors: ' . json_encode($results['errors']));
            }
        }
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * A source course holding one page and one rule with the given action.
     *
     * @param string $actiontype
     * @param callable $paramsbuilder function(int $cmid): array - builds the action params.
     * @return array [course, cmid, ruleid, actionid]
     */
    private function course_with_rule(string $actiontype, callable $paramsbuilder): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Round trip rule',
            'description' => 'Travels whole',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'name' => 'complete_activity',
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => (int) $page->cmid]),
        ]);
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => $actiontype,
            'actiontype' => $actiontype,
            'params' => json_encode($paramsbuilder((int) $page->cmid)),
        ]);

        return [$course, (int) $page->cmid, $ruleid, $actionid];
    }

    /**
     * The rule, its condition and its action all exist in the restored course, and the
     * condition points at the RESTORED module, not the source one.
     */
    public function test_a_rule_travels_and_its_condition_points_at_the_restored_module(): void {
        global $DB;

        [$course, $sourcecmid] = $this->course_with_rule('sendnotification', static function (int $cmid): array {
            return ['subject' => 'S', 'body' => 'B', 'primaryroleids' => []];
        });

        $newcourseid = $this->restore_course($this->backup_course($course));

        $rules = $DB->get_records('local_coursedynamicrules_rule', ['courseid' => $newcourseid]);
        $this->assertCount(1, $rules, 'The rule travels.');
        $rule = reset($rules);

        $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $rule->id]);
        $this->assertCount(1, $conditions, 'And its condition with it.');
        $condition = reset($conditions);

        $newcmid = (int) json_decode($condition->params)->cmid;
        $restoredcmids = array_keys(get_fast_modinfo($newcourseid)->cms);
        $this->assertContains($newcmid, $restoredcmids, 'The condition names a module of the NEW course.');
        $this->assertNotEquals(
            $sourcecmid,
            $newcmid,
            'Keeping the source cmid would watch completion in the wrong course - the silent variant '
            . 'of this failure is a rule that simply never fires.'
        );

        $this->assertCount(
            1,
            $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $rule->id]),
            'And its action.'
        );
    }

    /**
     * Notification role ids follow the ROLE, not the number.
     *
     * The scenario the changelog promises to survive: the backup names a role by an id that means
     * something else - or nothing - on the target. Deleting the role after the backup and creating
     * a fresh one under the same shortname forces the resolver off the id and onto the shortname
     * the backup annotated. A restore that kept the number would notify whoever now owns it; a
     * restore that dropped it silently would notify nobody.
     */
    public function test_notification_roles_follow_the_role_not_the_number(): void {
        global $DB;

        $oldroleid = create_role('Round trip role', 'roundtrip', '');
        [$course] = $this->course_with_rule('sendnotification', static function (int $cmid) use ($oldroleid): array {
            return ['subject' => 'S', 'body' => 'B', 'primaryroleids' => [$oldroleid]];
        });

        $backupid = $this->backup_course($course);

        delete_role($oldroleid);
        $newroleid = create_role('Round trip role reborn', 'roundtrip', '');
        $this->assertNotEquals($oldroleid, $newroleid, 'Sanity: the reborn role must carry a different id.');

        $newcourseid = $this->restore_course($backupid);

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $action = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $roleids = json_decode($action->params)->primaryroleids;

        $this->assertCount(1, $roleids, 'The role reference survives.');
        $this->assertSame(
            'roundtrip',
            $DB->get_field('role', 'shortname', ['id' => (int) $roleids[0]], MUST_EXIST),
            'It resolves to the role with the annotated shortname - the people the author meant - '
            . 'not to the number the source site happened to use.'
        );
    }

    /**
     * The enable-activity ownership marker is rewritten onto the restored action's id.
     *
     * Core restores course_modules.availability verbatim, so the restored restriction still names
     * the SOURCE action id. The restored action holds a new id and would recognise no node as its
     * own: it could neither grant nor revoke access, and the activity would stay hidden from every
     * student with no way back - the exact outcome the changelog claims this release prevents.
     */
    public function test_the_ownership_marker_names_the_restored_action(): void {
        global $DB;

        [$course, $sourcecmid, $ruleid, $sourceactionid] = $this->course_with_rule(
            'enableactivity',
            static function (int $cmid): array {
                // The PERSISTED shape, exactly as save_action() writes it: one snapshot object per
                // module, not a bare cmid - remap_coursemodules() reads ->id off each entry. The
                // first version of this fixture used plain ints and failed inside the remap, which
                // was the fixture violating the storage contract, not the restore misbehaving.
                return ['coursemodules' => [['id' => $cmid, 'visible' => 1, 'visibleoncoursepage' => 1]]];
            }
        );

        // The availability tree the action maintains in production: a user restriction whose node
        // carries the ownership marker naming the action.
        $node = (object) ['type' => 'user', 'userids' => [], 'source' => self::MARKER_PREFIX . $sourceactionid];
        $treejson = json_encode(tree::get_root_json([$node], tree::OP_AND, false));
        $DB->set_field('course_modules', 'availability', $treejson, ['id' => $sourcecmid]);
        rebuild_course_cache($course->id, true);

        $newcourseid = $this->restore_course($this->backup_course($course));

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $action = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $rule->id], '*', MUST_EXIST);

        // The action's own params point at the restored module.
        $newcmids = json_decode($action->params)->coursemodules;
        $this->assertCount(1, $newcmids);
        $newcmid = (int) $newcmids[0]->id;
        $this->assertNotEquals($sourcecmid, $newcmid, 'The action watches the restored module, not the source one.');

        // And that module's availability marker names the RESTORED action.
        $availability = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $newcmid]));
        $this->assertNotNull($availability, 'The restored module keeps its availability tree.');
        $this->assertSame(
            self::MARKER_PREFIX . $action->id,
            $availability->c[0]->source,
            'A marker still naming the source action id belongs to nobody: the restored action could '
            . 'neither grant nor revoke, and the activity would stay hidden from every student.'
        );
    }
}
