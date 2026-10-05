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
 * Restoring a course creates rules, and creating a rule has to leave a trace.
 *
 * Every other way a rule comes into being emits the plugin's own creation event: the form, the
 * duplication control. Restore did not, and it is the path that creates the most at once - a course
 * copy can land a dozen active, already-sealed rules on a site, automation that will notify students
 * and spend money on generated activities, with nothing in the log saying where any of it came from.
 * An operator asking "who put this rule here?" got no answer at all.
 *
 * The events are emitted once the restore has finished remapping, so what they name is the rule as
 * it will actually run, not the half-remapped row.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @coversNothing
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class restore_audit_trail_test extends \advanced_testcase {
    /**
     * Backup a course the way core does in its own tests.
     *
     * @param \stdClass $course The course.
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
     * Restore a backup into a brand-new course.
     *
     * @param string $backupid The backup.
     * @return int New course id.
     */
    private function restore_course(string $backupid): int {
        global $CFG, $DB, $USER;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $categoryid = $DB->get_field('course_categories', 'id', ['parent' => 0], IGNORE_MULTIPLE);
        $newcourseid = \restore_dbops::create_new_course('Restored', 'RA' . random_string(4), $categoryid);
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

    /**
     * A source course carrying one rule with one condition and one action.
     *
     * @return \stdClass The course.
     */
    private function course_with_a_rule(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id, 'name' => 'Travelling rule', 'description' => 'd', 'active' => 1,
            'lastexecutiontime' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid, 'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 1, 'periodunit' => 'days', 'nexttimeperiod' => 0]),
        ]);
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid, 'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 's', 'messagebody' => 'b',
                'primaryroleids' => [], 'copyroleids' => []]),
        ]);
        return $course;
    }

    /**
     * A restored course reports each rule, condition and action it brought as a creation.
     *
     * @return void
     */
    public function test_a_restore_reports_what_it_created(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $backupid = $this->backup_course($this->course_with_a_rule());

        $sink = $this->redirectEvents();
        $newcourseid = $this->restore_course($backupid);
        $events = $sink->get_events();
        $sink->close();

        $seen = ['rule' => 0, 'condition' => 0, 'action' => 0];
        $context = \context_course::instance($newcourseid);
        foreach ($events as $event) {
            foreach (['rule', 'condition', 'action'] as $kind) {
                $class = '\\local_coursedynamicrules\\event\\' . $kind . '_created';
                if ($event instanceof $class) {
                    $seen[$kind]++;
                    $this->assertEquals(
                        $context->id,
                        $event->contextid,
                        "The {$kind} creation is reported against the restored course."
                    );
                }
            }
        }

        $this->assertSame(['rule' => 1, 'condition' => 1, 'action' => 1], $seen);
    }

    /**
     * The reported ids are the restored rows, not the ids the backup came from.
     *
     * @return void
     */
    public function test_the_reported_ids_are_the_restored_rows(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $backupid = $this->backup_course($this->course_with_a_rule());

        $sink = $this->redirectEvents();
        $newcourseid = $this->restore_course($backupid);
        $events = $sink->get_events();
        $sink->close();

        $restoredruleid = (int) $DB->get_field('local_coursedynamicrules_rule', 'id', ['courseid' => $newcourseid]);
        $this->assertNotEmpty($restoredruleid, 'Precondition: the restore brought the rule.');

        foreach ($events as $event) {
            if ($event instanceof \local_coursedynamicrules\event\rule_created) {
                $this->assertSame($restoredruleid, (int) $event->objectid);
                return;
            }
        }

        $this->fail('The restore reported no rule creation.');
    }
}
