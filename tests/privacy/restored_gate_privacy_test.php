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
use local_coursedynamicrules\local\aiactivity_key;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * A gate restored with its course stays this plugin's data, even when core stripped its marker.
 *
 * Core re-encodes a module's whole availability tree on restore whenever a sibling condition changed
 * (a completion restriction always does), and availability_user::save() writes only the type and the
 * user ids: the ownership marker is gone. The backup now records, for every gate the plugin wrote,
 * a fingerprint of its action and the ids it lists, and the restore gives the marker back to the one
 * node that matches it - as written, not deduced - so the privacy provider can export and erase it.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \restore_local_coursedynamicrules_plugin
 * @covers     \backup_local_coursedynamicrules_plugin
 * @covers     \local_coursedynamicrules\local\gate_fingerprint
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restored_gate_privacy_test extends \core_privacy\tests\provider_testcase {
    protected function setUp(): void {
        parent::setUp();
        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; the gates live in its nodes.');
        }
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * A restored gate whose marker core stripped is exported and erased like any other gate.
     *
     * CDR-PRIV-002-R1.
     *
     * @return void
     */
    public function test_a_restored_gate_whose_marker_core_stripped_is_exported_and_erased(): void {
        global $DB;

        [$course, $cmid, $actionid] = $this->course_with_rule('enableactivity', true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $gate = enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [(int) $student->id]], $actionid);
        $this->set_tree($cmid, [$gate, $this->completion_on((int) $other->cmid)]);

        $newcourseid = $this->restore_course($this->backup_course($course));
        $newcmid = $this->restored_module_of_action($newcourseid);
        $context = \context_module::instance($newcmid);

        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertContains(
            (int) $context->id,
            $contextids,
            'The restored gate still lists the student, but the provider does not claim it.'
        );

        $approved = new approved_contextlist($student, 'local_coursedynamicrules', $contextids);
        provider::export_user_data($approved);
        $exported = writer::with_context($context)->get_data(
            [get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($exported, 'The restored module is missing from the export.');
        $this->assertSame(1, $exported->restrictions);

        provider::delete_data_for_user($approved);

        foreach ($DB->get_records('course_modules', ['course' => $newcourseid], '', 'id, availability') as $cm) {
            $this->assertNotContains(
                (int) $student->id,
                $this->ids_in((string) $cm->availability),
                'After the erasure a module of the restored course still lists the student.'
            );
        }
    }

    /**
     * A teacher's restriction identical to the plugin's gate is never claimed by the restore.
     *
     * Both nodes list the same student, so both match the fingerprint once core strips the marker:
     * nothing says which one the plugin wrote, and the restore claims neither.
     *
     * CDR-PRIV-002-R1.
     *
     * @return void
     */
    public function test_restore_does_not_claim_a_teachers_identical_restriction(): void {
        global $DB;

        [$course, $cmid, $actionid] = $this->course_with_rule('enableactivity', true);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $gate = enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [(int) $student->id]], $actionid);
        $teachers = (object) ['type' => 'user', 'userids' => [(int) $student->id]];
        $this->set_tree($cmid, [$gate, $teachers, $this->completion_on((int) $other->cmid)]);

        $newcourseid = $this->restore_course($this->backup_course($course));
        $newcmid = $this->restored_module_of_action($newcourseid);
        $context = \context_module::instance($newcmid);

        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertNotContains(
            (int) $context->id,
            $contextids,
            'Two identical nodes match the fingerprint; claiming either could claim the teacher\'s.'
        );

        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));

        $availability = (string) $DB->get_field('course_modules', 'availability', ['id' => $newcmid]);
        $this->assertSame(
            [(int) $student->id, (int) $student->id],
            $this->ids_in($availability),
            'An erasure approved for this plugin removed the student from a restriction it cannot prove it wrote.'
        );
        $this->assertSame(
            [],
            enableactivity_action::owned_user_nodes(json_decode($availability)),
            'The restore marked one of two identical nodes as written by the plugin.'
        );
    }

    /**
     * An AI activity restored on another site is attributed again, through the backup's fingerprint.
     *
     * The key in the ID number is an HMAC keyed with the site identifier, so on another site it
     * verifies nothing. The fingerprint in the backup does not depend on the site: the restore marks
     * the node again and writes the key of the restored action on this site.
     *
     * CDR-PRIV-002-R1.
     *
     * @return void
     */
    public function test_ai_activity_restored_on_another_site_is_reattributed(): void {
        global $DB;

        [$course, , $actionid] = $this->course_with_rule('createaiactivity', false);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $generated = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'idnumber' => aiactivity_key::for_action_user($actionid, (int) $student->id),
        ]);
        $node = enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [(int) $student->id]], $actionid);
        $this->set_tree((int) $generated->cmid, [$node, $this->completion_on((int) $other->cmid)]);

        $backupid = $this->backup_course($course);
        set_config('siteidentifier', 'anothersite' . random_string(24));
        $newcourseid = $this->restore_course($backupid);

        $rule = $DB->get_record('local_coursedynamicrules_rule', ['courseid' => $newcourseid], '*', MUST_EXIST);
        $newaction = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $restoredcm = $DB->get_record(
            'course_modules',
            ['course' => $newcourseid, 'idnumber' => aiactivity_key::for_action_user((int) $newaction->id, (int) $student->id)],
            '*',
            IGNORE_MISSING
        );
        $this->assertNotEmpty($restoredcm, 'The restored activity does not carry the key of the restored action on this site.');

        $usernodes = array_values(array_filter(
            json_decode((string) $restoredcm->availability)->c,
            static fn($child) => ($child->type ?? '') === 'user'
        ));
        $this->assertCount(1, $usernodes);
        $this->assertSame((int) $newaction->id, enableactivity_action::action_id_of($usernodes[0]));
        $this->assertObjectNotHasProperty('sourceadopted', $usernodes[0]);

        $context = \context_module::instance((int) $restoredcm->id);
        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $student->id)->get_contextids());
        $this->assertContains((int) $context->id, $contextids);

        provider::export_user_data(new approved_contextlist($student, 'local_coursedynamicrules', $contextids));
        $exported = writer::with_context($context)->get_data(
            [get_string('privacy:export:activityaccess', 'local_coursedynamicrules')]
        );
        $this->assertSame($restoredcm->idnumber, $exported->activitykey ?? null);
    }

    /**
     * A source course holding one rule with one action, and a page the action may gate.
     *
     * @param string $actiontype The action type.
     * @param bool $listpage Whether the action's params list the page (enable activity).
     * @return array [course, page cmid, action id]
     */
    private function course_with_rule(string $actiontype, bool $listpage): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Restored gate rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $params = $listpage
            ? ['coursemodules' => [['id' => (int) $page->cmid, 'visible' => 1, 'visibleoncoursepage' => 1]]]
            : ['message' => 'Create a page', 'generateimages' => false, 'sectionnum' => 0, 'beforemod' => null];
        $actionid = (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => $actiontype,
            'actiontype' => $actiontype,
            'params' => json_encode($params),
        ]);

        return [$course, (int) $page->cmid, $actionid];
    }

    /**
     * A completion condition on another module: the sibling that makes core re-encode the tree.
     *
     * @param int $cmid The module the completion refers to.
     * @return \stdClass
     */
    private function completion_on(int $cmid): \stdClass {
        return (object) ['type' => 'completion', 'cm' => $cmid, 'e' => 1];
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
     * The module the restored enable-activity action lists.
     *
     * @param int $courseid The restored course.
     * @return int The course module id.
     */
    private function restored_module_of_action(int $courseid): int {
        global $DB;
        $rule = $DB->get_record('local_coursedynamicrules_rule', ['courseid' => $courseid], '*', MUST_EXIST);
        $action = $DB->get_record('local_coursedynamicrules_action', ['ruleid' => $rule->id], '*', MUST_EXIST);

        return (int) json_decode($action->params)->coursemodules[0]->id;
    }

    /**
     * Every user id an availability tree lists, in tree order.
     *
     * @param string $availability The stored JSON.
     * @return int[]
     */
    private function ids_in(string $availability): array {
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
            if (($node->type ?? null) === 'user') {
                foreach ((array) ($node->userids ?? []) as $id) {
                    $found[] = (int) $id;
                }
            }
        };
        $root = json_decode($availability);
        if (is_object($root)) {
            $walk($root);
        }

        return $found;
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
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RG' . random_string(4), $categoryid);
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
