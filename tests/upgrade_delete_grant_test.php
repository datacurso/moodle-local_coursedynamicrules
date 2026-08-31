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
require_once($CFG->dirroot . '/local/coursedynamicrules/db/upgrade.php');

/**
 * The upgrade step that lets an editing teacher delete what they can create.
 *
 * db/access.php cannot deliver this to an existing site: core's update_capabilities() applies
 * archetype defaults only to capabilities it is seeing for the first time, and the three delete
 * capabilities shipped releases ago as manager-only. These tests exercise the upgrade function
 * against the two situations a real site can be in - a role that simply never had the capability,
 * and a role where an administrator explicitly decided something. The upgrade must serve the first
 * and must not overrule the second.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     ::local_coursedynamicrules_upgrade_grant_component_deletion
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_delete_grant_test extends \advanced_testcase {
    /**
     * A pre-upgrade editing-teacher role: the archetype, without the three delete capabilities.
     *
     * create_role() does not copy archetype defaults, so a freshly created role IS the state an
     * existing site's role is in with respect to a capability it never received.
     *
     * @return int Role id.
     */
    private function legacy_editingteacher_role(): int {
        $roleid = create_role('Legacy editing teacher', 'legacyeditor', '', 'editingteacher');

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            $this->assertNotEmpty(
                get_capability_info('local/coursedynamicrules:' . $capability),
                'Sanity: the capability must exist to be grantable.'
            );
        }

        return $roleid;
    }

    /**
     * A role that never had the capabilities receives all three.
     */
    public function test_an_existing_editingteacher_role_receives_the_three_deletes(): void {
        global $DB;
        $this->resetAfterTest(true);

        $roleid = $this->legacy_editingteacher_role();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            $this->assertFalse(
                has_capability('local/coursedynamicrules:' . $capability, $context, $user),
                "Sanity: before the upgrade the role must lack {$capability}, or this test proves nothing."
            );
        }

        local_coursedynamicrules_upgrade_grant_component_deletion();

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            $this->assertTrue(
                has_capability('local/coursedynamicrules:' . $capability, $context, $user),
                "After the upgrade an editing-teacher-archetype role holds {$capability}."
            );
        }
    }

    /**
     * An explicit administrator decision survives the upgrade.
     *
     * A site that prohibited deletion on some editing-teacher role decided something on purpose.
     * An upgrade that overwrites it would silently widen what a role can destroy - the exact kind
     * of unannounced behaviour change the changelog discipline exists to prevent.
     */
    public function test_an_explicit_prohibition_is_not_overwritten(): void {
        $this->resetAfterTest(true);

        $roleid = $this->legacy_editingteacher_role();
        $systemcontext = \context_system::instance();
        assign_capability('local/coursedynamicrules:deleterule', CAP_PROHIBIT, $roleid, $systemcontext->id);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);

        local_coursedynamicrules_upgrade_grant_component_deletion();

        $this->assertFalse(
            has_capability('local/coursedynamicrules:deleterule', $context, $user),
            'The administrator said no to this role; the upgrade must not say yes over them.'
        );
        $this->assertTrue(
            has_capability('local/coursedynamicrules:deletecondition', $context, $user),
            'While the capabilities the administrator never touched still receive the new default.'
        );
    }
}
