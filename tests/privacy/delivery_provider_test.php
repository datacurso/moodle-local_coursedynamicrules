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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\helper\delivery_ledger;

/**
 * The inactivity delivery ledger names students, so the privacy provider declares, exports and erases it.
 *
 * Each row says "this student was notified for this inactivity milestone of this rule". The rule
 * belongs to a course, so the row lives in that course's context.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delivery_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * A course with one inactivity rule and a delivery for each of two students.
     *
     * @return array [stdClass course, int ruleid, int conditionid, stdClass student, stdClass other]
     */
    private function create_course_with_deliveries(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $course->id,
            'name' => 'Inactivity reminder',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $conditionid = (int) $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'course_inactivity',
            'params' => json_encode([
                'intervaltype' => 'recurring',
                'timeintervals' => '1',
                'intervalunit' => 'days',
                'basedatetype' => 'coursestart',
            ]),
        ]);
        foreach ([$student, $other] as $user) {
            delivery_ledger::record($ruleid, $conditionid, (int) $user->id, delivery_ledger::milestone_key(1000, 87400));
        }

        return [$course, $ruleid, $conditionid, $student, $other];
    }

    /**
     * The ledger is declared with the personal fields it stores.
     */
    public function test_the_ledger_is_declared(): void {
        $items = provider::get_metadata(new collection('local_coursedynamicrules'))->get_collection();
        $tables = array_filter($items, static fn($item) => $item->get_name() === delivery_ledger::TABLE);

        $this->assertCount(1, $tables);
        $fields = reset($tables)->get_privacy_fields();
        $this->assertEqualsCanonicalizing(['ruleid', 'conditionid', 'userid', 'milestonekey', 'timecreated'], array_keys($fields));
        foreach ($fields as $identifier) {
            $this->assertNotEmpty(get_string($identifier, 'local_coursedynamicrules'));
        }
        $this->assertNotEmpty(get_string(reset($tables)->get_summary(), 'local_coursedynamicrules'));
    }

    /**
     * The course holding the rule is one of the student's contexts, and the student is one of its users.
     */
    public function test_the_course_is_found_both_ways(): void {
        $this->resetAfterTest(true);
        [$course, , , $student, $other] = $this->create_course_with_deliveries();
        $coursecontext = \context_course::instance($course->id);

        $contextids = provider::get_contexts_for_userid((int) $student->id)->get_contextids();
        $this->assertContainsEquals($coursecontext->id, $contextids);

        $userlist = new userlist($coursecontext, 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int) $student->id, (int) $other->id], $userlist->get_userids());

        $stranger = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int) $stranger->id)->get_contextids());
    }

    /**
     * The export lists the student's deliveries with the rule name and the milestone.
     */
    public function test_the_export_lists_the_deliveries(): void {
        $this->resetAfterTest(true);
        [$course, , , $student] = $this->create_course_with_deliveries();
        $coursecontext = \context_course::instance($course->id);

        provider::export_user_data(new approved_contextlist($student, 'local_coursedynamicrules', [$coursecontext->id]));

        $data = writer::with_context($coursecontext)->get_data(
            [get_string('privacy:export:inactivitydeliveries', 'local_coursedynamicrules')]
        );
        $this->assertNotEmpty($data);
        $this->assertCount(1, $data->deliveries);
        $delivery = (array) $data->deliveries[0];
        $this->assertSame('Inactivity reminder', $delivery['rule']);
        $this->assertArrayHasKey('milestone', $delivery);
        $this->assertArrayHasKey('timecreated', $delivery);
    }

    /**
     * Erasing one user removes only that user's rows.
     */
    public function test_erasing_a_user_removes_only_their_deliveries(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, , , $student, $other] = $this->create_course_with_deliveries();
        $coursecontext = \context_course::instance($course->id);

        provider::delete_data_for_user(new approved_contextlist($student, 'local_coursedynamicrules', [$coursecontext->id]));

        $this->assertSame(0, $DB->count_records(delivery_ledger::TABLE, ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records(delivery_ledger::TABLE, ['userid' => $other->id]));
    }

    /**
     * Erasing a list of users in the course removes only those users' rows.
     */
    public function test_erasing_a_list_of_users_is_scoped(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, , , $student, $other] = $this->create_course_with_deliveries();
        $coursecontext = \context_course::instance($course->id);

        provider::delete_data_for_users(new approved_userlist($coursecontext, 'local_coursedynamicrules', [(int) $other->id]));

        $this->assertSame(1, $DB->count_records(delivery_ledger::TABLE, ['userid' => $student->id]));
        $this->assertSame(0, $DB->count_records(delivery_ledger::TABLE, ['userid' => $other->id]));
    }

    /**
     * Erasing the whole course context removes its rows and leaves other courses alone.
     */
    public function test_erasing_the_course_context_removes_its_deliveries(): void {
        global $DB;

        $this->resetAfterTest(true);
        [$course, $ruleid] = $this->create_course_with_deliveries();
        [, $keptruleid] = $this->create_course_with_deliveries();

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertSame(0, $DB->count_records(delivery_ledger::TABLE, ['ruleid' => $ruleid]));
        $this->assertSame(2, $DB->count_records(delivery_ledger::TABLE, ['ruleid' => $keptruleid]));
    }
}
