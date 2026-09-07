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
 * The threshold reader resolves both the stable new shape and the legacy id-keyed shape whose id
 * Moodle recreated out from under the stored condition.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\helper\grade_condition_thresholds
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_condition_thresholds_test extends \advanced_testcase {
    /**
     * The new shape (entries carry itemnumber) resolves straight onto the itemnumber.
     */
    public function test_new_shape_resolves_by_itemnumber(): void {
        $stored = (object) [
            'gradegte_0' => (object) ['gradeitem' => 999, 'itemnumber' => 0, 'condition' => 'gradegte', 'value' => 50],
        ];

        $map = grade_condition_thresholds::by_itemnumber($stored, [0]);

        $this->assertArrayHasKey(0, $map);
        $this->assertNotNull($map[0]->gradegte);
        $this->assertEquals(50, $map[0]->gradegte->value);
        $this->assertNull($map[0]->gradelt);
    }

    /**
     * The bug's real data: a threshold saved under a grade_item id (3738) that no longer exists,
     * on a single-item activity, must resolve to itemnumber 0 - reviving the orphaned condition.
     */
    public function test_legacy_id_keyed_shape_revives_by_position_on_single_item(): void {
        $stored = (object) [
            'gradegte_3738' => (object) ['gradeitem' => 3738, 'condition' => 'gradegte', 'value' => 50],
        ];

        // The activity now has one grade item at itemnumber 0 (its id, whatever it is, is irrelevant).
        $map = grade_condition_thresholds::by_itemnumber($stored, [0]);

        $this->assertArrayHasKey(0, $map);
        $this->assertNotNull($map[0]->gradegte, 'A legacy threshold keyed by a dead id must revive on itemnumber 0.');
        $this->assertEquals(50, $map[0]->gradegte->value);
    }

    /**
     * Both operators on one legacy item stay together on the same itemnumber.
     */
    public function test_legacy_both_operators_group_on_one_itemnumber(): void {
        $stored = (object) [
            'gradegte_3738' => (object) ['gradeitem' => 3738, 'condition' => 'gradegte', 'value' => 40],
            'gradelt_3738' => (object) ['gradeitem' => 3738, 'condition' => 'gradelt', 'value' => 90],
        ];

        $map = grade_condition_thresholds::by_itemnumber($stored, [0]);

        $this->assertEquals(40, $map[0]->gradegte->value);
        $this->assertEquals(90, $map[0]->gradelt->value);
    }

    /**
     * A legacy multi-item activity maps distinct ids to itemnumbers by first-seen position.
     */
    public function test_legacy_multi_item_maps_by_position(): void {
        $stored = (object) [
            'gradegte_500' => (object) ['gradeitem' => 500, 'condition' => 'gradegte', 'value' => 10],
            'gradegte_501' => (object) ['gradeitem' => 501, 'condition' => 'gradegte', 'value' => 20],
        ];

        $map = grade_condition_thresholds::by_itemnumber($stored, [0, 1]);

        $this->assertEquals(10, $map[0]->gradegte->value);
        $this->assertEquals(20, $map[1]->gradegte->value);
    }

    /**
     * A threshold of 0 is a real threshold and must survive normalisation.
     */
    public function test_a_zero_threshold_is_preserved(): void {
        $stored = (object) [
            'gradegte_0' => (object) ['gradeitem' => 1, 'itemnumber' => 0, 'condition' => 'gradegte', 'value' => 0],
        ];

        $map = grade_condition_thresholds::by_itemnumber($stored, [0]);

        $this->assertNotNull($map[0]->gradegte);
        $this->assertEquals(0, $map[0]->gradegte->value);
    }

    /**
     * Empty or malformed stored data yields an empty map, never a warning.
     */
    public function test_empty_stored_data_is_an_empty_map(): void {
        $this->assertSame([], grade_condition_thresholds::by_itemnumber(null, [0]));
        $this->assertSame([], grade_condition_thresholds::by_itemnumber((object) [], [0]));
        $this->assertSame([], grade_condition_thresholds::by_itemnumber('garbage', [0]));
    }
}
