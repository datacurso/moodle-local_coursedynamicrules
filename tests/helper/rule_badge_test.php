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
 * The badge shows "executed" only for a rule the ENGINE switched off, never one a human paused.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\helper\rule_badge
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_badge_test extends \advanced_testcase {
    /**
     * Build a rule row carrying only the fields the badge decision reads.
     *
     * @param array $overrides Field values to set (active, timeactivated, timeautodeactivated,
     *              lastexecutiontime).
     * @return \stdClass
     */
    private function row(array $overrides): \stdClass {
        return (object) ($overrides + [
            'active' => 0,
            'timeactivated' => null,
            'timeautodeactivated' => null,
            'lastexecutiontime' => null,
        ]);
    }

    /**
     * An active rule reads as active, even after it has fired.
     */
    public function test_an_active_rule_is_active_even_after_firing(): void {
        $row = $this->row(['active' => 1, 'timeactivated' => 1000, 'lastexecutiontime' => 2000]);
        $this->assertSame(rule_badge::STATE_ACTIVE, rule_badge::state($row));
    }

    /**
     * A rule the engine switched off after running (the self-deactivation stamp is set) is executed.
     */
    public function test_an_engine_deactivated_rule_is_executed(): void {
        $row = $this->row([
            'active' => 0,
            'timeactivated' => 1000,
            'timeautodeactivated' => 3000,
            'lastexecutiontime' => 3000,
        ]);
        $this->assertSame(rule_badge::STATE_EXECUTED, rule_badge::state($row));
    }

    /**
     * The bug: an event-driven rule that FIRED (lastexecutiontime set) and was then PAUSED BY HAND
     * carries no self-deactivation stamp, so it must read as paused - not executed.
     */
    public function test_a_fired_rule_paused_by_hand_is_paused_not_executed(): void {
        $row = $this->row([
            'active' => 0,
            'timeactivated' => 1000,
            'timeautodeactivated' => null,
            'lastexecutiontime' => 2500,
        ]);
        $this->assertSame(
            rule_badge::STATE_PAUSED,
            rule_badge::state($row),
            'A rule fired then paused by hand is paused; lastexecutiontime alone must not read as executed.'
        );
    }

    /**
     * A sealed rule stopped by hand, never fired, is paused.
     */
    public function test_a_sealed_stopped_never_fired_rule_is_paused(): void {
        $row = $this->row(['active' => 0, 'timeactivated' => 1000]);
        $this->assertSame(rule_badge::STATE_PAUSED, rule_badge::state($row));
    }

    /**
     * A rule that was never activated is inactive - the only editable state.
     */
    public function test_a_never_activated_rule_is_inactive(): void {
        $row = $this->row(['active' => 0, 'timeactivated' => null]);
        $this->assertSame(rule_badge::STATE_INACTIVE, rule_badge::state($row));
    }
}
