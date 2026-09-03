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

namespace local_coursedynamicrules\privacy;

use core_availability\tree;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');

/**
 * When a granted user is deleted from the site, their id must not linger inside the plugin's
 * access restriction on the managed activities.
 *
 * KNOWN DEFECT (materialises the privacy step of MDL-E2E-011, [Pendiente:fail], privacy
 * category): today the "enable activity" action writes the student's id into each managed
 * module's user restriction, and nothing removes it when the user is deleted from the site — the
 * id remains as an inert orphan reference. This test asserts the CORRECT behaviour (deletion
 * scrubs the id from the plugin's own restriction node) and MUST FAIL until that cleanup exists.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\action\enableactivity\enableactivity_action
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class granted_user_deletion_test extends \advanced_testcase {
    /**
     * MDL-E2E-011: deleting a granted user removes their id from the managed activity's restriction.
     */
    public function test_deleting_a_granted_user_scrubs_their_id_from_the_restriction(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $grantee = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Seed the plugin's own (empty) user restriction on the module, as save_action() does.
        $tree = tree::get_root_json([(object) ['type' => 'user', 'userids' => []]], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $page->cmid]);

        $record = (object) [
            'ruleid' => (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
                'courseid' => $course->id, 'name' => 'Enable rule', 'active' => 1,
                'timecreated' => time(), 'timemodified' => time(),
            ]),
            'actiontype' => 'enableactivity',
            'params' => json_encode(['coursemodules' => [(object) ['id' => $page->cmid]]]),
        ];
        $action = new enableactivity_action($record, $course->id);
        $action->execute((object) ['courseid' => $course->id, 'userid' => $grantee->id]);

        // Sanity: the grant put the student's id into the restriction.
        $granted = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertContains(
            (int) $grantee->id,
            $granted->c[0]->userids,
            'Sanity: execute() must have granted access, or this test proves nothing.'
        );

        // The site deletes the user.
        delete_user($DB->get_record('user', ['id' => $grantee->id], '*', MUST_EXIST));

        $after = json_decode($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertNotContains(
            (int) $grantee->id,
            $after->c[0]->userids,
            'A deleted user\'s id must not linger inside the plugin\'s access restriction.'
        );
    }
}
