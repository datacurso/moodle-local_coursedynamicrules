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

namespace local_coursedynamicrules\backup;

use backup;
use backup_controller;
use local_coursedynamicrules\local\service\grade_register_service;
use local_coursedynamicrules\local\service\grade_isolation_service;
use restore_controller;
use restore_dbops;

/**
 * Backup and restore of the generated-reinforcement register.
 *
 * The register is what stops a student being given the same reinforcement twice. Before this it was
 * absent from the backup entirely, so a restored course generated - and paid for - a second
 * reinforcement for every student who already had one.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \backup_local_coursedynamicrules_plugin
 * @covers     \restore_local_coursedynamicrules_plugin
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class aigrade_backup_test extends \advanced_testcase {
    /**
     * A course with a rule, an AI action, two modules and one register row for a real student.
     *
     * @param int|null $cmidoverride Store this cmid instead of the generated module's.
     * @return array [course, student, actionid, generatedcmid, sourcecmid]
     */
    private function seed(?int $cmidoverride = null): array {
        global $DB;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $source = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $generated = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'Refuerzo', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $actionid = $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid, 'name' => 'A', 'actiontype' => 'createaiactivity',
            'params' => json_encode(['prompt' => 'x']), 'lastexecutiontime' => 0,
        ]);

        $rowid = grade_register_service::record_generation(
            (int) $course->id, (int) $ruleid, (int) $actionid, (int) $student->id,
            $cmidoverride ?? (int) $generated->cmid, grade_isolation_service::MODE_OWN
        );
        unset($rowid);

        return [$course, $student, (int) $actionid, (int) $generated->cmid, (int) $source->cmid];
    }

    /**
     * Back a course up and restore it, returning the destination course id.
     *
     * @param \stdClass $course Course to back up.
     * @param bool $userinfo Whether the operator asked for user data.
     * @param int|null $into Restore into this course id (adding) instead of a new one.
     * @return int
     */
    private function roundtrip(\stdClass $course, bool $userinfo = true, ?int $into = null): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $bc = new backup_controller(
            backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($userinfo);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $dir = 'test-restore-' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'),
            $CFG->tempdir . '/backup/' . $dir);

        $target = backup::TARGET_NEW_COURSE;
        if ($into === null) {
            $into = restore_dbops::create_new_course(
                'Restaurado', 'rest' . uniqid(), (int) $course->category);
        } else {
            $target = backup::TARGET_EXISTING_ADDING;
        }

        $rc = new restore_controller($dir, $into, backup::INTERACTIVE_NO,
            backup::MODE_GENERAL, $USER->id, $target);

        // Course-level data - every rule this plugin owns - lives in course.xml, and core only
        // processes that file for a new course or when the operator ticks "overwrite course
        // configuration" (restore_course_task.class.php:70). Merging into an existing course
        // without it restores no rules at all, so there would be nothing for this test to observe.
        if ($target !== backup::TARGET_NEW_COURSE) {
            $setting = $rc->get_plan()->get_setting('overwrite_conf');
            $setting->set_status(\base_setting::NOT_LOCKED);
            $setting->set_value(true);
        }

        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return (int) $into;
    }

    /**
     * The register travels, and every id in it points at this site's restored objects.
     *
     * The falsifier this exists for: a restore that copies the row verbatim keeps the SOURCE cmid,
     * which on the destination either names nothing or names an unrelated activity.
     */
    public function test_the_register_survives_and_its_ids_are_remapped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $student, , $generatedcmid, $sourcecmid] = $this->seed();
        $new = $this->roundtrip($course);

        $rows = $DB->get_records(grade_register_service::TABLE, ['courseid' => $new]);
        $this->assertCount(1, $rows, 'the marker came across');

        $row = reset($rows);
        $this->assertSame((int) $student->id, (int) $row->userid, 'it still names the same student');
        $this->assertSame(grade_isolation_service::MODE_OWN, $row->grademode);

        $this->assertNotEquals($generatedcmid, (int) $row->cmid, 'the module id was remapped, not copied');
        $this->assertSame($new, (int) $DB->get_field('course_modules', 'course', ['id' => $row->cmid]),
            'the generated activity it names lives in the restored course');
        unset($sourcecmid);

        $this->assertSame($new, (int) $DB->get_field('local_coursedynamicrules_rule', 'courseid',
            ['id' => $row->ruleid]), 'and it hangs off the restored rule');
    }

    /**
     * A copy made WITHOUT user data still knows the column exists, without naming anybody.
     *
     * This test used to assert the opposite - zero register rows - and called that correct. It was
     * the defect written down as intent: the generated activity is ordinary course content, so it
     * travels in every copy, import and duplication, and its grade column travels with it. With no
     * row recording that column, the enrolment sweep's course gate answers no forever and nobody
     * who ever enrols in the copy is excluded from it. Measured at 0% under Lowest grade.
     *
     * So the row travels, severed: it says a column exists, and does not say who it was for. That
     * is not personal data, and it is exactly what a user-free copy should carry.
     */
    public function test_a_user_free_copy_keeps_the_column_marker_without_naming_anybody(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course, $student] = $this->seed();
        $new = $this->roundtrip($course, false);

        $rows = $DB->get_records(grade_register_service::TABLE, ['courseid' => $new]);
        $this->assertCount(1, $rows, 'the column marker came across');

        $row = reset($rows);
        $this->assertSame(0, (int) $row->userid,
            'and it names nobody - there is no user data in a user-free copy');
        $this->assertNotEquals(0, (int) $row->cmid);
        $this->assertSame($new, (int) $DB->get_field('course_modules', 'course', ['id' => $row->cmid]));
        $this->assertSame(0, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $new, 'userid' => $student->id]),
            'the original recipient is not named anywhere in the copy');
    }

    /**
     * And a student in that copy is actually protected, which is the point of carrying the marker.
     *
     * The falsifier for the whole of C2: both judges measured this at 0% under Lowest grade and
     * 40% under Mean before the marker travelled.
     */
    public function test_a_student_in_a_user_free_copy_keeps_their_grade(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');

        [$course] = $this->seed();
        $new = $this->roundtrip($course, false);
        $newcourse = get_course($new);

        $student = $this->getDataGenerator()->create_and_enrol($newcourse, 'student');

        // The restored baseline: the first assign in the copy that is not the reinforcement.
        $marker = $DB->get_record(grade_register_service::TABLE, ['courseid' => $new]);
        $baseitem = null;
        foreach (\grade_item::fetch_all(['courseid' => $new, 'itemtype' => 'mod']) as $item) {
            $cm = get_coursemodule_from_instance($item->itemmodule, (int) $item->iteminstance,
                $new, false, IGNORE_MISSING);
            if ($cm && (int) $cm->id !== (int) $marker->cmid) {
                $baseitem = $item;
                break;
            }
        }
        $this->assertNotNull($baseitem, 'the copy has a baseline activity to score on');
        $baseitem->update_final_grade($student->id, 80);

        $root = \grade_category::fetch_course_category($new);
        $root->aggregation = GRADE_AGGREGATE_MIN;
        $root->aggregateonlygraded = 0;
        $root->update();
        grade_regrade_final_grades($new);

        $courseitem = \grade_item::fetch_course_item($new);
        $gg = \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $student->id]);
        $max = $gg ? (float) $gg->get_grade_max() : 0.0;
        $pct = ($gg && $gg->finalgrade !== null && $max > 0)
            ? round((float) $gg->finalgrade / $max * 100, 1) : null;

        $this->assertEqualsWithDelta(80.0, $pct, 0.05,
            'the reinforcement column in the copy does not reach them');
    }

    /**
     * A marker whose generated activity did not come across is dropped, not kept pointing at nothing.
     *
     * Keeping it would deny the student a reinforcement they no longer have.
     */
    public function test_a_marker_with_no_activity_is_dropped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$course] = $this->seed(999999);
        $new = $this->roundtrip($course);

        $this->assertSame(0, $DB->count_records(grade_register_service::TABLE, ['courseid' => $new]));
    }

    /**
     * Restoring into a course that already has its own markers must not disturb them.
     *
     * The remapping pass looks up every module id through the restore's mapping table. The rows that
     * already lived in the destination carry live ids that resolve to nothing there - so a pass
     * scoped to the whole course, rather than to the rows this restore created, would delete every
     * one of them and silently re-open the door to duplicate reinforcements.
     *
     * This is the only test that can catch that, and it needs "overwrite course configuration" on:
     * without it core never reads course.xml into an existing course, no rule arrives, and the
     * remapping pass has nothing to be wrong about.
     */
    public function test_restoring_into_a_course_leaves_its_existing_markers_alone(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$source] = $this->seed();
        [$destination, $existingstudent] = $this->seed();

        $before = $DB->get_records(grade_register_service::TABLE, ['courseid' => $destination->id]);
        $this->assertCount(1, $before);
        $survivor = reset($before);

        $this->roundtrip($source, true, (int) $destination->id);

        $after = $DB->get_record(grade_register_service::TABLE, ['id' => $survivor->id]);
        $this->assertNotFalse($after, 'the marker that was already there still exists');
        $this->assertSame((int) $survivor->cmid, (int) $after->cmid, 'and still points where it did');
        $this->assertSame((int) $existingstudent->id, (int) $after->userid);

        $this->assertSame(2, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $destination->id]), 'the incoming marker was added alongside it');
    }
}
