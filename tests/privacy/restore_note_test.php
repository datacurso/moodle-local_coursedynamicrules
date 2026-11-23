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

use core_availability\tree;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * A restore of a backup made before gates were fingerprinted is reconciled, and what it cannot
 * attribute is recorded for the student.
 *
 * A backup older than 1.8.13 carries no gate fingerprint, so the restore can only re-adopt the
 * stripped markers as deduced, which the privacy provider refuses. An adhoc task queued by the
 * restore marks a node as written by the plugin only when it is the one node of the module in the
 * shape the enable-activity action writes and exactly one restored action lists the module; anything
 * else stays unattributed and the student gets a durable note, which the privacy export shows and the
 * privacy erasure removes.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \restore_local_coursedynamicrules_plugin
 * @covers     \local_coursedynamicrules\local\restore_note
 * @covers     \local_coursedynamicrules\local\restore_reconciliation
 * @covers     \local_coursedynamicrules\task\restore_reconciliation_task
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_note_test extends \core_privacy\tests\provider_testcase {
    /** @var string The note table. */
    private const TABLE = 'local_coursedynamicrules_restnote';

    protected function setUp(): void {
        parent::setUp();
        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; the gates live in its nodes.');
        }
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * A pre-fingerprint backup: the unique gate is attributed after reconciliation, the ambiguous one is noted.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_a_pre_fingerprint_backup_is_reconciled_after_restore(): void {
        global $DB;

        $fixture = $this->pre_fingerprint_restore();
        [$student, $other, $uniquecmid, $ambiguouscmid, $actionid] = $fixture;

        $unique = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $uniquecmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($unique), 'The restore itself must only deduce.');
        $this->assertTrue(
            $DB->record_exists(self::TABLE, ['cmid' => $uniquecmid, 'userid' => $student->id, 'reason' => 'inferred']),
            'The deduced gate left no note after the restore.'
        );

        $this->expectOutputRegex('/restore reconciliation of course \d+: 1 restriction\(s\) attributed/');
        $this->runAdhocTasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);

        $unique = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $uniquecmid]));
        $owned = enableactivity_action::owned_user_nodes($unique);
        $this->assertCount(1, $owned, 'The single gate in the plugin\'s shape was not attributed.');
        $this->assertSame($actionid, enableactivity_action::action_id_of($owned[0]));
        $this->assertSame(
            0,
            $DB->count_records(self::TABLE, ['cmid' => $uniquecmid]),
            'The reconciliation resolved the module but kept its note.'
        );

        $ambiguous = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $ambiguouscmid]));
        $this->assertSame([], enableactivity_action::owned_user_nodes($ambiguous), 'An ambiguous module was attributed.');
        $this->assertTrue(
            $DB->record_exists(self::TABLE, ['cmid' => $ambiguouscmid, 'userid' => $student->id, 'reason' => 'ambiguous'])
        );
        $this->assertTrue(
            $DB->record_exists(self::TABLE, ['cmid' => $ambiguouscmid, 'userid' => $other->id, 'reason' => 'ambiguous'])
        );
    }

    /**
     * The user's privacy export lists the activity that could not be attributed, without anyone else's id.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_the_export_shows_the_note_without_other_users_ids(): void {
        [$student, $other, , $ambiguouscmid] = $this->pre_fingerprint_restore();
        $this->expectOutputRegex('/restore reconciliation of course \d+: 1 restriction\(s\) attributed/');
        $this->runAdhocTasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);

        $context = \context_module::instance($ambiguouscmid);
        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertContains((int) $context->id, $contextids, 'The noted activity is not in the student\'s contextlist.');

        provider::export_user_data(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));
        $exported = writer::with_context($context)->get_data(
            [get_string('privacy:export:restorenote', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($exported, 'The export carries no note for the unattributed restriction.');
        $this->assertCount(1, $exported->notes);
        $this->assertSame(
            ['reason', 'explained', 'timecreated'],
            array_keys((array) $exported->notes[0]),
            'A note must say why and when, and name no user.'
        );

        $userids = array_map('intval', $this->users_in($context)->get_userids());
        sort($userids);
        $expected = [(int) $student->id, (int) $other->id];
        sort($expected);
        $this->assertSame($expected, $userids);
    }

    /**
     * A privacy erasure and an account deletion remove the user's note rows, and only theirs.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_deleting_the_user_removes_their_note_rows(): void {
        global $DB;

        [$student, $other, , $ambiguouscmid] = $this->pre_fingerprint_restore();
        $this->expectOutputRegex('/restore reconciliation of course \d+: 1 restriction\(s\) attributed/');
        $this->runAdhocTasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);

        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));
        $this->assertSame(0, $DB->count_records(self::TABLE, ['userid' => $student->id]));
        $this->assertTrue($DB->record_exists(self::TABLE, ['cmid' => $ambiguouscmid, 'userid' => $other->id]));

        delete_user($other);
        // The ambiguous module is reported by the enable-activity action as it always was.
        $this->assertDebuggingCalled();
        $this->assertSame(0, $DB->count_records(self::TABLE, ['userid' => $other->id]));
    }

    /**
     * After the reconciliation, the attributed restriction is exported and erased like any gate.
     *
     * The teacher's ambiguous restriction is never touched: it keeps the student's id, as documented.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_a_reconciled_gate_is_exported_and_erased(): void {
        global $DB;

        [$student, , $uniquecmid, $ambiguouscmid] = $this->pre_fingerprint_restore();
        $this->expectOutputRegex('/restore reconciliation of course \d+: 1 restriction\(s\) attributed/');
        $this->runAdhocTasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);

        $context = \context_module::instance($uniquecmid);
        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertContains((int) $context->id, $contextids, 'The reconciled gate is not in the student\'s contextlist.');

        $approved = new approved_contextlist($student, 'local_coursedynamicrules', $contextids);
        provider::export_user_data($approved);
        $exported = writer::with_context($context)->get_data(
            [get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($exported, 'The reconciled gate is missing from the export.');
        $this->assertSame(1, $exported->restrictions);

        provider::delete_data_for_user($approved);

        $this->assertNotContains(
            (int) $student->id,
            $this->user_ids_in((string) $DB->get_field('course_modules', 'availability', ['id' => $uniquecmid])),
            'After the erasure the reconciled gate still lists the student.'
        );
        $this->assertContains(
            (int) $student->id,
            $this->user_ids_in((string) $DB->get_field('course_modules', 'availability', ['id' => $ambiguouscmid])),
            'The erasure touched a restriction the plugin could not attribute.'
        );
    }

    /**
     * Deleting the module, or the course, removes its notes.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_module_and_course_deletion_remove_the_notes(): void {
        global $DB;

        [, , , $ambiguouscmid] = $this->pre_fingerprint_restore();
        $this->expectOutputRegex('/restore reconciliation of course \d+: 1 restriction\(s\) attributed/');
        $this->runAdhocTasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $ambiguouscmid]);
        $this->assertTrue($DB->record_exists(self::TABLE, ['cmid' => $ambiguouscmid]));

        course_delete_module($ambiguouscmid);
        $this->assertSame(0, $DB->count_records(self::TABLE, ['cmid' => $ambiguouscmid]));

        $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'cmid' => $ambiguouscmid + 1000,
            'actionid' => null,
            'userid' => 2,
            'reason' => 'ambiguous',
            'timecreated' => time(),
        ]);
        delete_course($courseid, false);
        $this->assertSame(0, $DB->count_records(self::TABLE, ['courseid' => $courseid]));
    }

    /**
     * Restore, into a new course, a backup with its gate fingerprints removed.
     *
     * The source course has one enable-activity action listing two pages. The first carries only the
     * plugin's gate beside a completion condition; the second carries the plugin's gate and a
     * teacher's restriction in the same shape, listing the same student and another one. Both
     * students are enrolled in the restored course.
     *
     * @return array [student, other user, unique cmid, ambiguous cmid, restored action id]
     */
    private function pre_fingerprint_restore(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $unique = $generator->create_module('page', ['course' => $course->id]);
        $ambiguous = $generator->create_module('page', ['course' => $course->id]);
        $sibling = $generator->create_module('page', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Pre-fingerprint rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'enableactivity',
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [
                ['id' => (int) $unique->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
                ['id' => (int) $ambiguous->cmid, 'visible' => 1, 'visibleoncoursepage' => 1],
            ]]),
        ]);
        $completion = (object) ['type' => 'completion', 'cm' => (int) $sibling->cmid, 'e' => 1];
        $gate = fn() => enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [(int) $student->id]], $actionid);
        $this->set_tree((int) $unique->cmid, [$gate(), $completion]);
        $teachers = (object) ['type' => 'user', 'userids' => [(int) $student->id, (int) $other->id]];
        $this->set_tree((int) $ambiguous->cmid, [$gate(), $teachers, $completion]);

        $backupid = $this->backup_course($course);
        $this->strip_gates($backupid);
        $newcourseid = $this->restore_course($backupid);
        // The students follow the course, as a restore with its users brings them: the reconciliation
        // attributes only a restriction that lists active students of the course.
        $generator->enrol_user((int) $student->id, $newcourseid, 'student');
        $generator->enrol_user((int) $other->id, $newcourseid, 'student');

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $action = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $cmids = array_map(static fn($cm) => (int) $cm->id, json_decode($action->params)->coursemodules);

        return [$student, $other, $cmids[0], $cmids[1], (int) $action->id];
    }

    /**
     * The users the provider reports in a context.
     *
     * @param \context $context The context.
     * @return \core_privacy\local\request\userlist
     */
    private function users_in(\context $context): \core_privacy\local\request\userlist {
        $userlist = new \core_privacy\local\request\userlist($context, 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);

        return $userlist;
    }

    /**
     * Every user id listed by a user condition anywhere in an availability tree.
     *
     * @param string $availability The availability JSON.
     * @return int[]
     */
    private function user_ids_in(string $availability): array {
        $ids = [];
        $walk = function ($node) use (&$walk, &$ids): void {
            if (!is_object($node)) {
                return;
            }
            if (($node->type ?? null) === 'user') {
                $ids = array_merge($ids, array_map('intval', (array) ($node->userids ?? [])));
            }
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
        };
        $walk(json_decode($availability));

        return $ids;
    }

    /**
     * Store an AND tree of the given nodes on a module.
     *
     * @param int $cmid The module.
     * @param \stdClass[] $nodes The conditions.
     * @return void
     */
    private function set_tree(int $cmid, array $nodes): void {
        global $DB;
        $tree = tree::get_root_json($nodes, tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
        rebuild_course_cache((int) $DB->get_field('course_modules', 'course', ['id' => $cmid]), true);
    }

    /**
     * Remove the gates element from the backup, which is what a backup made before 1.8.13 looks like.
     *
     * @param string $backupid The backup id.
     * @return void
     */
    private function strip_gates(string $backupid): void {
        global $CFG;
        $path = $CFG->tempdir . '/backup/' . $backupid . '/course/course.xml';
        $xml = (string) file_get_contents($path);
        $stripped = preg_replace('#<coursedynamicrules_gates>.*?</coursedynamicrules_gates>#s', '', $xml, -1, $count);
        $this->assertSame(1, $count, 'The backup carried no gates element to strip.');
        $this->assertStringContainsString('<coursedynamicrules_gate ', $xml, 'The backup recorded no gate.');
        file_put_contents($path, $stripped);
    }

    /**
     * Back a course up the way core does in its own tests.
     *
     * @param \stdClass $course The course.
     * @return string The backup id.
     */
    private function backup_course(\stdClass $course): string {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restore a backup into a brand-new course.
     *
     * @param string $backupid The backup id.
     * @return int The new course id.
     */
    private function restore_course(string $backupid): int {
        global $CFG, $DB, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $categoryid = $DB->get_field('course_categories', 'id', ['parent' => 0], IGNORE_MULTIPLE);
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RN' . random_string(4), $categoryid);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
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
}
