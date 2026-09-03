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
 * The upgrade has to reach the version the installed base actually runs.
 *
 * 1.9.0 and 1.8.2 were built as parallel lines off one commit and numbered independently: the
 * 1.9.0 line reached savepoint 2026083002 on 30 August while main shipped 1.8.2 as 2026090102 on
 * 1 September with no schema step of its own. Merging main into that line produced 1.8.3.
 *
 * Core passes the upgrade function the version RECORDED IN config_plugins
 * (lib/upgradelib.php:757), so on a released 1.8.2 site $oldversion is 2026090102 - GREATER than
 * both of the 1.9.0 line's savepoints. Both guards evaluate false and the activation lock ships
 * reading a column that was never created.
 *
 * This drives the REAL upgrade function rather than a copy of its SQL, because a copy is what let
 * the defect through: tests/upgrade_lock_stamp_test.php re-runs the UPDATE by hand and therefore
 * cannot see a guard that never fires.
 *
 * Falsifier: delete the 2026090200 block from db/upgrade.php and both tests here go red.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_reaches_182_sites_test extends \advanced_testcase {

    /** @var int The version a site running the released 1.8.2 has in config_plugins. */
    private const INSTALLED_ON_182 = 2026090102;

    /**
     * Run the plugin's real upgrade function as core would for a 1.8.2 site.
     *
     * @return void
     */
    private function upgrade_from_182(): void {
        global $CFG;
        // upgradelib is not part of the PHPUnit bootstrap; the savepoint calls live there.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

        // The recorded version has to say 1.8.2 as well, not just the argument: the savepoint
        // refuses to write a version that is not an advance, and a test DB installed from
        // install.xml already sits at the current one. This is what a released 1.8.2 site holds.
        set_config('version', self::INSTALLED_ON_182, 'local_coursedynamicrules');

        xmldb_local_coursedynamicrules_upgrade(self::INSTALLED_ON_182);
    }

    /**
     * A rule that is active on a 1.8.2 site must come out of the upgrade sealed.
     */
    public function test_an_active_rule_on_a_182_site_is_stamped(): void {
        global $DB;
        $this->resetAfterTest(true);

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $make = function (int $active) use ($DB, $courseid): int {
            return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $courseid,
                'name' => 'Rule from a 1.8.2 site',
                'description' => '',
                'active' => $active,
                'timeactivated' => null,
                'timecreated' => 1000,
                'timemodified' => 5000,
            ]);
        };
        $active = $make(1);
        $inactive = $make(0);

        $this->upgrade_from_182();

        $this->assertEquals(5000, $DB->get_field('local_coursedynamicrules_rule', 'timeactivated',
            ['id' => $active]),
            'an active rule on a 1.8.2 site must be sealed, or the lock is void for the whole base');
        $this->assertNull($DB->get_field('local_coursedynamicrules_rule', 'timeactivated',
            ['id' => $inactive]),
            'and an inactive rule stays grandfathered unlocked');
    }

    /**
     * The delete capabilities must reach the editing teacher on a 1.8.2 site too.
     *
     * Same guard, same arithmetic: the block that grants them is 2026083001, below 2026090102.
     */
    public function test_the_delete_capabilities_reach_a_182_site(): void {
        $this->resetAfterTest(true);

        $roles = get_archetype_roles('editingteacher');
        $this->assertNotEmpty($roles, 'the fixture needs at least one editing teacher role');
        $role = reset($roles);
        $system = \context_system::instance();

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            unassign_capability('local/coursedynamicrules:' . $capability, $role->id, $system->id);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->upgrade_from_182();
        accesslib_clear_all_caches_for_unit_testing();

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            $this->assertSame(CAP_ALLOW, $this->permission_of($capability, $role->id),
                "the editing teacher must hold $capability after upgrading from 1.8.2");
        }
    }

    /**
     * The stored permission for one capability on one role, at system level.
     *
     * @param string $capability Short name, without the component prefix.
     * @param int $roleid
     * @return int|null
     */
    private function permission_of(string $capability, int $roleid): ?int {
        global $DB;

        $permission = $DB->get_field('role_capabilities', 'permission', [
            'capability' => 'local/coursedynamicrules:' . $capability,
            'roleid' => $roleid,
            'contextid' => \context_system::instance()->id,
        ]);

        return $permission === false ? null : (int) $permission;
    }
}
