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
 * The upgrade step that creates the inactivity delivery ledger on a site coming from 2026092300.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_delivery_table_test extends \advanced_testcase {
    /**
     * MDL-UNIT-010: the step creates the table with its unique key, and running it again is harmless.
     */
    public function test_the_upgrade_creates_the_delivery_table(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

        $this->resetAfterTest(true);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursedynamicrules_delivery');

        // A site on 2026092300 has no ledger yet.
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table));

        local_coursedynamicrules_upgrade_add_delivery_table($dbman);
        $this->assertTrue($dbman->table_exists($table));

        // Idempotent: a site that already has it loses nothing.
        local_coursedynamicrules_upgrade_add_delivery_table($dbman);
        $this->assertTrue($dbman->table_exists($table));

        $row = ['ruleid' => 1, 'conditionid' => 2, 'userid' => 3, 'milestonekey' => '10:20', 'timecreated' => time()];
        $DB->insert_record('local_coursedynamicrules_delivery', (object) $row);
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_coursedynamicrules_delivery', (object) $row);
    }
}
