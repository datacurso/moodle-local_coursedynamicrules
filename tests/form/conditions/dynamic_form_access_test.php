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

namespace local_coursedynamicrules\form\conditions;

/**
 * The form served over the web service must demand what its own page demands.
 *
 * Every condition screen is gated by the pair - view AND manage - because a role holding only one of
 * them is refused at the page. This form is also reachable through core's dynamic-form web service,
 * and there it asked for the manage capability alone. A role built exactly as the release notes warn
 * administrators about, manage allowed and view prevented, was therefore refused at the page and
 * served by the endpoint, which handed back every grade-tracked activity of the course with each
 * grade item's bounds and scale labels.
 *
 * Not an escalation: the endpoint writes nothing and core still requires course access. It is the
 * inversion the plugin avoids everywhere else - never offer what the page would refuse.
 *
 * @package     local_coursedynamicrules
 * @category    test
 * @covers      \local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dynamic_form_access_test extends \advanced_testcase {
    /**
     * Load the fixture that exposes the protected access check.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../../fixtures/testable_dynamic_grade_in_activity_form.php');
    }

    /**
     * Enrol a user holding exactly the given capability decisions in the course.
     *
     * @param \stdClass $course The course.
     * @param array $capabilities Capability short name => CAP_* constant.
     * @return \stdClass The user.
     */
    private function user_with(\stdClass $course, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'probe' . random_string(6)]);

        foreach ($capabilities as $name => $permission) {
            assign_capability('local/coursedynamicrules:' . $name, $permission, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        return $user;
    }

    /**
     * Run the endpoint's access check as the given user, for the given course.
     *
     * @param \stdClass $user The user.
     * @param \stdClass $course The course.
     * @return void
     */
    private function check_access_as(\stdClass $user, \stdClass $course): void {
        $this->setUser($user);
        $_POST['courseid'] = $course->id;
        try {
            (new \local_coursedynamicrules\form\conditions\testable_dynamic_grade_in_activity_form(
                null,
                null,
                'post',
                '',
                null,
                true,
                ['courseid' => $course->id]
            ))->check_access_for_test();
        } finally {
            unset($_POST['courseid']);
        }
    }

    /**
     * A role holding manage but refused view is refused by the endpoint too.
     *
     * @return void
     */
    public function test_manage_without_view_is_refused(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->user_with($course, [
            'managecondition' => CAP_ALLOW,
            'viewcondition' => CAP_PROHIBIT,
        ]);

        $this->expectException(\required_capability_exception::class);
        $this->check_access_as($user, $course);
    }

    /**
     * A role holding the pair the pages demand is served.
     *
     * @return void
     */
    public function test_the_pair_the_pages_demand_is_served(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->user_with($course, [
            'managecondition' => CAP_ALLOW,
            'viewcondition' => CAP_ALLOW,
        ]);

        $this->check_access_as($user, $course);

        $this->assertTrue(true, 'The access check returned without refusing.');
    }
}
