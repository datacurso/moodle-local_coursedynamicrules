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
 * Backward compatibility for a course backup taken before the plugin's backup elements were
 * renamed to avoid a course-level naming collision with another local plugin (both plugins used
 * to register a bare "rules" element, among others, and Moodle's backup optigroup requires those
 * names to be unique across every local plugin's course structure, at any nesting depth).
 *
 * A real archive already sitting on a site's disk was produced under the OLD element names
 * ("rules", "rule", "conditions", "condition", "actions", "action", "notificationroles",
 * "notificationrole") and can never be re-taken. Restore must keep understanding that shape
 * forever, alongside the current one - which is exactly what the "_legacy" restore_path_element
 * entries in restore_local_coursedynamicrules_plugin exist for.
 *
 * This test cannot install a second plugin to reproduce the original collision (that was verified
 * manually against a real site with local_notificationsagent installed). What it CAN verify, and
 * does, is the half of the fix that is otherwise silent forever if it regresses: that a backup
 * carrying the old element names still restores every rule, condition, action and notification
 * role correctly.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \restore_local_coursedynamicrules_plugin
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_restore_legacy_element_names_test extends \advanced_testcase {
    /** @var array<string, string> Current element name => the pre-rename element name. */
    private const LEGACY_NAMES = [
        'coursedynamicrules_notificationroles' => 'notificationroles',
        'coursedynamicrules_notificationrole' => 'notificationrole',
        'coursedynamicrules_conditions' => 'conditions',
        'coursedynamicrules_condition' => 'condition',
        'coursedynamicrules_actions' => 'actions',
        'coursedynamicrules_action' => 'action',
        'coursedynamicrules_rules' => 'rules',
        'coursedynamicrules_rule' => 'rule',
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Backup a course into the temp backup directory, the way core does in its own tests.
     *
     * @param \stdClass $course
     * @return string Backup id, also the temp directory name under $CFG->tempdir/backup/.
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
     * Rewrite every current-format element tag in course.xml back to its pre-rename name, in place.
     *
     * Longest names are mapped first via a single regex alternation so a prefix like
     * "coursedynamicrules_condition" can never be matched inside "coursedynamicrules_conditions"
     * before the longer name gets its turn.
     *
     * @param string $backupid
     * @return void
     */
    private function rewrite_to_legacy_element_names(string $backupid): void {
        global $CFG;

        $path = $CFG->tempdir . '/backup/' . $backupid . '/course/course.xml';
        $this->assertFileExists($path, 'The course structure file must exist to rewrite it.');

        $xml = file_get_contents($path);
        $pattern = '/\b(' . implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            array_keys(self::LEGACY_NAMES)
        )) . ')\b/';

        $rewritten = preg_replace_callback($pattern, function (array $m): string {
            return self::LEGACY_NAMES[$m[1]];
        }, $xml);

        // Sanity: every current-format tag must be gone, proving the fixture is genuinely
        // old-shaped and not accidentally still matching the modern restore_path_element entries.
        foreach (array_keys(self::LEGACY_NAMES) as $modern) {
            $this->assertStringNotContainsString($modern, $rewritten, "Rewrite left '$modern' behind.");
        }

        file_put_contents($path, $rewritten);
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
        $newcourseid = \restore_dbops::create_new_course('Restored legacy', 'RTL' . random_string(4), $categoryid);

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
     * A rule, its condition, its notification action and the role it names all restore correctly
     * from a backup carrying the pre-rename element names.
     */
    public function test_a_pre_rename_backup_restores_rule_condition_action_and_notification_role(): void {
        global $DB;

        $roleid = create_role('Legacy round trip role', 'legacyroundtrip', '');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);

        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Legacy format rule',
            'description' => 'Restored from an old-shaped backup',
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
            'params' => json_encode(['subject' => 'S', 'body' => 'B', 'primaryroleids' => [$roleid]]),
        ]);

        $backupid = $this->backup_course($course);
        $this->rewrite_to_legacy_element_names($backupid);

        $newcourseid = $this->restore_course($backupid);

        $rules = $DB->get_records('local_coursedynamicrules_rule', ['courseid' => $newcourseid]);
        $this->assertCount(1, $rules, 'The rule travels from the old-format backup.');
        $rule = reset($rules);
        $this->assertSame('Legacy format rule', $rule->name);

        $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $rule->id]);
        $this->assertCount(1, $conditions, 'And its condition with it.');

        $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $rule->id]);
        $this->assertCount(1, $actions, 'And its action.');
        $action = reset($actions);

        $roleids = json_decode($action->params)->primaryroleids;
        $this->assertCount(1, $roleids, 'The notification role reference survives too.');
        $this->assertSame(
            'legacyroundtrip',
            $DB->get_field('role', 'shortname', ['id' => (int) $roleids[0]], MUST_EXIST),
            'The role resolves through the annotated shortname, exactly like the modern-format path.'
        );
    }
}
