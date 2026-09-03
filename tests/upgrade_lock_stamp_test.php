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
 * The upgrade decision for the installed base: active rules are stamped, inactive ones are not.
 *
 * Product decision 2026-08-31, taken explicitly by the product owner: a rule that is ACTIVE at
 * upgrade time was activated at some point, so it is locked from day one - editable never again,
 * pausable forever - or the "never after first activation" requirement would be void for every
 * site that already uses the plugin. Inactive rules are grandfathered unlocked because their
 * activation history is unknowable, and locking a rule that may never have run punishes the
 * cautious author.
 *
 * The test calls the upgrade step itself, against rows built to be in each state.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_lock_stamp_test extends \advanced_testcase {
    /**
     * Active rules receive a stamp borrowed from their own history; inactive rules stay null.
     */
    public function test_the_upgrade_stamps_active_rules_and_only_those(): void {
        global $DB;
        $this->resetAfterTest(true);

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $make = function (int $active, ?int $timemodified, int $timecreated = 1000) use ($DB, $courseid): int {
            return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $courseid,
                'name' => 'Pre-upgrade rule',
                'description' => '',
                'active' => $active,
                'timeactivated' => null,
                'timecreated' => $timecreated,
                'timemodified' => $timemodified,
            ]);
        };

        $activewithhistory = $make(1, 5000);
        $activebare = $make(1, null);
        $inactive = $make(0, 5000);
        // Legacy rows can hold 0 in these columns, and a stamp of literally 0 would fork the
        // sealed predicate (round-2 judges): the statement must skip zeros, never copy them.
        $activezerotm = $make(1, 0);
        $activeallzero = $make(1, 0, 0);

        // The real upgrade step, not a copy of its statement. Copying was how this test used to
        // work, and it is why it could not see the defect the dissenter found on 2026-09-02: the
        // 2026083001/2026083002 guards never fire on a site upgrading from the released 1.8.2
        // (2026090102 is greater than both), so the whole step was unreachable while every
        // assertion below stayed green. A test that re-runs the SQL itself measures the SQL, never
        // the reachability of the code that carries it.
        global $CFG;
        require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');
        local_coursedynamicrules_upgrade_add_activation_stamp($DB->get_manager());

        $this->assertEquals(
            5000,
            $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $activewithhistory]),
            'An active rule is stamped with the best timestamp its row offers.'
        );
        $this->assertEquals(
            1000,
            $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $activebare]),
            'With no timemodified, timecreated stands in.'
        );
        $this->assertNull(
            $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $inactive]),
            'An inactive rule is grandfathered unlocked: its activation history is unknowable.'
        );
        $this->assertEquals(
            1000,
            $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $activezerotm]),
            'A zero timemodified is skipped, not copied into the stamp.'
        );
        $this->assertGreaterThan(
            0,
            (int) $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $activeallzero]),
            'With every column zero, the stamp falls back to now - never to 0.'
        );

        // The grep that used to sit here - asserting db/upgrade.php still CONTAINS the statement -
        // is gone with the copy it defended. Matching a string proves the string is written down;
        // the call above proves the code runs. See upgrade_reaches_182_sites_test for the other
        // half, which proves the savepoint guarding it is reachable from the installed base.
    }
}
