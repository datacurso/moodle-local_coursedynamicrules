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
 * The AI request table has to reach sites already running the released 1.8.12.
 *
 * 1.8.12 was released as 2026110900 while the request table (CDR-PRIV-001-R2) was being built on
 * a parallel line whose first step was numbered 2026110900 too. Core passes the upgrade function
 * the version recorded in config_plugins, so on a 1.8.12 site a step guarded by
 * `$oldversion < 2026110900` never fires and the table is never created. The table step therefore
 * runs above the release, and it has to be a no-op on a site that already has the table.
 *
 * This drives the real upgrade function, as tests/upgrade_reaches_182_sites_test.php does.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_reaches_1812_sites_test extends \advanced_testcase {
    /** @var int The version a site running the released 1.8.12 has in config_plugins. */
    private const INSTALLED_ON_1812 = 2026110900;

    /** @var string The request table. */
    private const TABLE = 'local_coursedynamicrules_aireq';

    /**
     * Put the request table back as install.xml defines it, whatever happened.
     *
     * resetAfterTest() rolls back data, not schema: a test that dropped the table or its column
     * and failed before the upgrade re-created it would break every later test in this database.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $CFG, $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::TABLE);
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/local/coursedynamicrules/db/install.xml',
                self::TABLE
            );
        }
        $field = $this->erasure_field();
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = $this->erasure_index();
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        parent::tearDown();
    }

    /**
     * The erasure column as install.xml declares it.
     *
     * @return \xmldb_field
     */
    private function erasure_field(): \xmldb_field {
        return new \xmldb_field('erasurerequested', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');
    }

    /**
     * The erasure index as install.xml declares it.
     *
     * @return \xmldb_index
     */
    private function erasure_index(): \xmldb_index {
        return new \xmldb_index('erasurerequested', XMLDB_INDEX_NOTUNIQUE, ['erasurerequested']);
    }

    /**
     * Run the plugin's real upgrade function as core would for a 1.8.12 site.
     *
     * @return void
     */
    private function upgrade_from_1812(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

        // The savepoint refuses to write a version that is not an advance over the recorded one.
        set_config('version', self::INSTALLED_ON_1812, 'local_coursedynamicrules');

        xmldb_local_coursedynamicrules_upgrade(self::INSTALLED_ON_1812);
    }

    /**
     * A 1.8.12 site has no request table: the upgrade creates it with the erasure column.
     */
    public function test_a_1812_site_gets_the_request_table(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::TABLE);
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table), 'the fixture only means anything without the table');

        $this->upgrade_from_1812();

        $this->assertTrue($dbman->table_exists($table), 'the upgrade has to CREATE the table on a 1.8.12 site');
        $this->assertTrue($dbman->field_exists($table, $this->erasure_field()));
        $this->assertTrue($dbman->index_exists($table, $this->erasure_index()));
        $this->assertSame('2026111000', get_config('local_coursedynamicrules', 'version'));
    }

    /**
     * A table without the erasure column (an early build of the request table) gets the column.
     */
    public function test_a_table_without_the_erasure_column_gets_it(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::TABLE);
        $dbman->drop_index($table, $this->erasure_index());
        $dbman->drop_field($table, $this->erasure_field());
        $this->assertFalse($dbman->field_exists($table, $this->erasure_field()));

        $this->upgrade_from_1812();

        $this->assertTrue($dbman->field_exists($table, $this->erasure_field()));
        $this->assertTrue($dbman->index_exists($table, $this->erasure_index()));
    }

    /**
     * On a site that already has the table, the step keeps every row and only stamps deleted courses.
     */
    public function test_an_existing_table_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest(true);

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $row = fn(int $course, ?int $requested) => (int) $DB->insert_record(self::TABLE, (object) [
            'actionid' => 1,
            'courseid' => $course,
            'userid' => $userid,
            'threadid' => 'thread-' . $course,
            'cmid' => null,
            'timecreated' => 1000,
            'erasurerequested' => $requested,
        ]);
        $live = $row($courseid, null);
        $alreadyrequested = $row($courseid, 777);
        $orphan = $row($courseid + 1000, null);

        $this->upgrade_from_1812();

        $this->assertSame(3, $DB->count_records(self::TABLE), 'no existing request may be lost');
        $this->assertNull($DB->get_field(self::TABLE, 'erasurerequested', ['id' => $live]));
        $this->assertEquals(777, $DB->get_field(self::TABLE, 'erasurerequested', ['id' => $alreadyrequested]));
        $this->assertNotNull(
            $DB->get_field(self::TABLE, 'erasurerequested', ['id' => $orphan]),
            'a request of a deleted course is due for erasure'
        );
    }
}
