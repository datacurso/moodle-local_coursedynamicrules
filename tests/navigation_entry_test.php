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
require_once($CFG->dirroot . '/local/coursedynamicrules/lib.php');

/**
 * The course navigation entry obeys the same gate as the page it advertises.
 *
 * rules.php requires managerule AND viewrule; an entry offered on managerule alone is a control
 * that is always refused for a manage-only custom role - the seam this plugin's own changelog
 * warns administrators about, reproduced in its own menu. Never offer what would be refused.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     ::local_coursedynamicrules_extend_navigation_course
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class navigation_entry_test extends \advanced_testcase {
    /**
     * Build the nav tree the hook writes into, for a user holding exactly these capabilities.
     *
     * @param string[] $capabilities Capability shortnames to grant.
     * @return \navigation_node The node the hook may have added children to.
     */
    private function navigation_for(array $capabilities): \navigation_node {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $roleid = create_role('Nav probe', 'navprobe' . count($capabilities), '');
        foreach ($capabilities as $capability) {
            assign_capability('local/coursedynamicrules:' . $capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
        $this->setUser($user);

        $node = new \navigation_node(['text' => 'course']);
        local_coursedynamicrules_extend_navigation_course($node, $course, $context);

        return $node;
    }

    /**
     * A role holding only managerule is NOT offered the entry.
     *
     * rules.php refuses that role at the door (it also demands viewrule), so the menu offering it
     * the destination produced a permission error page on every click. The changelog already warns
     * custom-role administrators about the page-level requirement; the menu has to practise it.
     */
    public function test_a_manage_only_role_is_not_offered_the_entry(): void {
        $this->resetAfterTest(true);

        $node = $this->navigation_for(['managerule']);

        $this->assertCount(
            0,
            $node->children,
            'rules.php would refuse this role; a menu entry it cannot use is not navigation, it is a trap.'
        );
    }

    /**
     * A role holding the same pair the page enforces gets the entry.
     */
    public function test_a_role_matching_the_pages_gate_is_offered_the_entry(): void {
        $this->resetAfterTest(true);

        $node = $this->navigation_for(['managerule', 'viewrule']);

        $this->assertCount(1, $node->children, 'The pair rules.php enforces is exactly what earns the entry.');
    }
}
