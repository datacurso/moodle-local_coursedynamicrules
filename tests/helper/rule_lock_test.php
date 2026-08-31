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

namespace local_coursedynamicrules\helper;

/**
 * The lock that makes a rule unmodifiable after its first activation.
 *
 * Product requirement 2026-08-31: a rule may be edited only until it is activated for the FIRST
 * time - never again. The fact is one nullable column, timeactivated, and this helper is its one
 * door: it stamps the moment, answers whether a rule is locked, and refuses mutations on locked
 * rules. Pausing and reactivating stay allowed forever, which is why nothing here ever clears the
 * stamp.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\rule_lock
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_lock_test extends \advanced_testcase {
    /** @var int Course the rules live in. */
    private int $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
    }

    /**
     * Insert a rule row.
     *
     * @param int $active
     * @param int|null $timeactivated
     * @return int Rule id.
     */
    private function rule(int $active, ?int $timeactivated = null): int {
        global $DB;

        return (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->courseid,
            'name' => 'Lock probe',
            'description' => '',
            'active' => $active,
            'timeactivated' => $timeactivated,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Stamping an active, never-stamped rule records the moment once.
     *
     * @covers ::stamp_if_active
     * @covers ::is_locked
     */
    public function test_stamping_an_active_rule_locks_it(): void {
        $ruleid = $this->rule(1);

        $this->assertFalse(rule_lock::is_locked($ruleid), 'Sanity: born unlocked.');
        rule_lock::stamp_if_active($ruleid);
        $this->assertTrue(rule_lock::is_locked($ruleid), 'Active and stamped: locked.');
    }

    /**
     * An INACTIVE rule is never stamped - the stamp records activation, not saving.
     *
     * The caller is every write path that touches 'active'; making the helper read the state
     * itself (instead of trusting a $wasactive/$nowactive pair from the caller) is what makes a
     * wrong caller harmless: stamping is conditional on what the ROW says, atomically.
     *
     * @covers ::stamp_if_active
     */
    public function test_an_inactive_rule_is_not_stamped(): void {
        $ruleid = $this->rule(0);

        rule_lock::stamp_if_active($ruleid);

        $this->assertFalse(rule_lock::is_locked($ruleid), 'Saving an inactive rule must not lock it.');
    }

    /**
     * The stamp survives pause and reactivation untouched - once means once.
     *
     * The requirement's whole point: pausing is allowed forever, and coming back from a pause is
     * NOT a new first activation. A stamp that moved would also quietly re-arm the first-activation
     * warning, telling the user a lie about what is about to become unmodifiable (it already is).
     *
     * @covers ::stamp_if_active
     */
    public function test_pause_and_reactivate_keep_the_original_stamp(): void {
        global $DB;

        $ruleid = $this->rule(1);
        rule_lock::stamp_if_active($ruleid);
        $original = (int) $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $ruleid]);
        $this->assertGreaterThan(0, $original);

        // Pause, then reactivate later; each transition calls the stamp as the write paths will.
        $DB->set_field('local_coursedynamicrules_rule', 'active', 0, ['id' => $ruleid]);
        rule_lock::stamp_if_active($ruleid);
        $DB->set_field('local_coursedynamicrules_rule', 'active', 1, ['id' => $ruleid]);
        $DB->set_field('local_coursedynamicrules_rule', 'timemodified', time() + 100, ['id' => $ruleid]);
        rule_lock::stamp_if_active($ruleid);

        $this->assertSame(
            $original,
            (int) $DB->get_field('local_coursedynamicrules_rule', 'timeactivated', ['id' => $ruleid]),
            'Reactivation is not a first activation: the stamp never moves.'
        );
    }

    /**
     * require_unlocked() refuses a locked rule and passes an unlocked one.
     *
     * @covers ::require_unlocked
     */
    public function test_require_unlocked_refuses_a_locked_rule(): void {
        $unlocked = $this->rule(0);
        rule_lock::require_unlocked($unlocked);

        $locked = $this->rule(1, time());
        $this->expectException(\moodle_exception::class);
        rule_lock::require_unlocked($locked);
    }

    /**
     * On a locked rule, only the pause/reactivate write survives sanitisation.
     *
     * Freezing form fields is cosmetics: a stale tab, opened before the rule locked, still submits
     * the full payload and editrule's update_record() would write it wholesale. The server has to
     * re-decide at write time, and the whitelist lives HERE so the rule of what a locked rule
     * accepts has one owner: id, active and timemodified pass; everything else is taken from the
     * row as it stands.
     *
     * @covers ::sanitise_locked_write
     */
    public function test_a_locked_write_keeps_only_the_active_toggle(): void {
        global $DB;

        $ruleid = $this->rule(1, time());
        $stale = (object) [
            'id' => $ruleid,
            'name' => 'Renamed from a stale tab',
            'description' => 'Should never land',
            'active' => 0,
            'timemodified' => 12345,
        ];

        $clean = rule_lock::sanitise_locked_write($stale);

        $this->assertSame('Lock probe', $clean->name, 'The stored name wins on a locked rule.');
        $this->assertSame('', $clean->description, 'The stored description wins too.');
        $this->assertEquals(0, $clean->active, 'Pausing is the one thing a locked rule still accepts.');
        $this->assertEquals(12345, $clean->timemodified);

        // And an unlocked rule is untouched by design: the helper refuses to sanitise it, because
        // calling this on an unlocked rule would silently discard a legitimate edit.
        $freeid = $this->rule(0);
        $this->expectException(\coding_exception::class);
        rule_lock::sanitise_locked_write((object) ['id' => $freeid, 'name' => 'x']);
    }

    /**
     * Every mutation path consults the lock - the wiring half of the coverage.
     *
     * Same shape and same reason as page_gate's wiring test: the lock's behaviour is proven above
     * with real rules, but no effect test can see from outside a page script whether the page
     * still makes the call. Delete rule_lock from any of these files and this names it.
     *
     * @coversNothing
     */
    public function test_every_mutation_path_consults_the_lock(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/coursedynamicrules/';
        $expected = [
            // Creating a component on a locked rule is refused at the ?type= branch - the menu is
            // hidden too, but a URL is not a menu.
            'conditions.php' => ['rule_lock::require_unlocked('],
            'actions.php' => ['rule_lock::require_unlocked('],
            // Deleting a component IS modifying the rule.
            'deletecondition.php' => ['rule_lock::require_unlocked('],
            'deleteaction.php' => ['rule_lock::require_unlocked('],
            // The save path sanitises a locked rule's payload and stamps after an activation write.
            'editrule.php' => ['rule_lock::sanitise_locked_write(', 'rule_lock::stamp_if_active('],
        ];

        $missing = [];
        foreach ($expected as $file => $calls) {
            $content = file_get_contents($root . $file);
            foreach ($calls as $call) {
                if (strpos($content, $call) === false) {
                    $missing[] = "$file no longer calls $call)";
                }
            }
        }

        $this->assertSame([], $missing, 'A mutation path stopped consulting the lock.');
    }
}
