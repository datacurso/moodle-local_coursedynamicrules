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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The documented transport limit: rules travel to a NEW course, but a restore in merge mode over an
 * EXISTING course does not carry them, because core only processes the course-plugin data where the
 * plugin stores its rules on a new-course (or overwrite) restore.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_merge_import_test extends \advanced_testcase {
    /**
     * A source course with one active rule (one condition, one notification action).
     *
     * @return \stdClass The source course.
     */
    private function source_course_with_rule(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Source rule',
            'description' => 'Should not travel in merge mode',
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
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'sendnotification',
            'actiontype' => 'sendnotification',
            'params' => json_encode(['subject' => 'S', 'body' => 'B', 'primaryroleids' => []]),
        ]);

        return $course;
    }

    /**
     * Back up a course the way core does in its own tests.
     *
     * @param \stdClass $course
     * @return string Backup id.
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
     * MDL-INT-013: restoring over an existing course in merge mode does not bring the rules.
     */
    public function test_merge_restore_over_existing_course_does_not_bring_rules(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $source = $this->source_course_with_rule();
        $backupid = $this->backup_course($source);

        // A separate, pre-existing destination course with no rules of its own.
        $destination = $this->getDataGenerator()->create_course();
        $this->assertSame(
            0,
            $DB->count_records('local_coursedynamicrules_rule', ['courseid' => $destination->id]),
            'Sanity: the destination starts with no rules.'
        );

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $rc = new \restore_controller(
            $backupid,
            $destination->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                $this->fail('Restore precheck errors: ' . json_encode($results['errors']));
            }
        }
        $rc->execute_plan();
        $rc->destroy();

        $this->assertSame(
            0,
            $DB->count_records('local_coursedynamicrules_rule', ['courseid' => $destination->id]),
            'Merge-mode restore over an existing course must not carry the rules of the source course.'
        );
    }
}
