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

/**
 * The 2026112001 step creates the restore note table and queues the reconciliation once.
 *
 * Restores made before this release left deduced (adopted) markers behind. The step queues one
 * reconciliation per course that still carries one, so existing sites get the same treatment as a
 * restore made from now on.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_restore_notes_test extends \advanced_testcase {
    /** @var string The note table. */
    private const TABLE = 'local_coursedynamicrules_restnote';

    /**
     * Put the note table back as install.xml defines it, whatever happened.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $CFG, $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists(new \xmldb_table(self::TABLE))) {
            $dbman->install_one_table_from_xmldb_file($CFG->dirroot . '/local/coursedynamicrules/db/install.xml', self::TABLE);
        }

        parent::tearDown();
    }

    /**
     * A 1.8.15 site without the table gets it, and a course with an adopted marker is queued for reconciliation.
     *
     * CDR-PRIV-002-R2.
     *
     * @return void
     */
    public function test_the_step_creates_the_table_and_queues_courses_with_adopted_markers(): void {
        global $CFG, $DB;
        $this->resetAfterTest(true);
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

        $dbman = $DB->get_manager();
        $dbman->drop_table(new \xmldb_table(self::TABLE));

        $adopted = $this->getDataGenerator()->create_course();
        $clean = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $adopted->id]);
        $plain = $this->getDataGenerator()->create_module('page', ['course' => $clean->id]);
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'user', 'userids' => [2], 'source' => 'local_coursedynamicrules:7', 'sourceadopted' => true]],
            'showc' => [false],
        ]), ['id' => $page->cmid]);
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'user', 'userids' => [2]]],
            'showc' => [false],
        ]), ['id' => $plain->cmid]);

        set_config('version', 2026112000, 'local_coursedynamicrules');
        xmldb_local_coursedynamicrules_upgrade(2026112000);

        $this->assertTrue($dbman->table_exists(new \xmldb_table(self::TABLE)));
        $this->assertSame('2026112001', get_config('local_coursedynamicrules', 'version'));

        $tasks = \core\task\manager::get_adhoc_tasks(\local_coursedynamicrules\task\restore_reconciliation_task::class);
        $courseids = array_map(static fn($task) => (int) $task->get_custom_data()->courseid, $tasks);
        $this->assertSame([(int) $adopted->id], array_values($courseids));
    }
}
