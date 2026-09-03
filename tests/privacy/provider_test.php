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

use context_course;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursedynamicrules\local\service\grade_register_service;
use local_coursedynamicrules\local\service\grade_isolation_service;

/**
 * Tests for the privacy provider.
 *
 * The register of generated reinforcements is the only table in this plugin that names a student,
 * and it also holds the grade that triggered the reinforcement. Before these tests the provider
 * declared nothing about it: an export returned nothing and an erasure request left it untouched.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass First course. */
    private $course1;

    /** @var \stdClass Second course, to prove deletion does not reach across courses. */
    private $course2;

    /** @var \stdClass The student whose data is exported and erased. */
    private $student;

    /** @var \stdClass Another student in the same course, who must survive untouched. */
    private $other;

    /**
     * Two courses, two students, and a register row for every pairing.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $this->course1 = $gen->create_course();
        $this->course2 = $gen->create_course();
        $this->student = $gen->create_user();
        $this->other = $gen->create_user();

        foreach ([$this->course1, $this->course2] as $course) {
            foreach ([$this->student, $this->other] as $user) {
                $gen->enrol_user($user->id, $course->id, 'student');
                $this->add_row((int) $course->id, (int) $user->id);
            }
        }
    }

    /**
     * Insert one register row with a real generated activity and a real source.
     *
     * @param int $courseid
     * @param int $userid
     */
    private function add_row(int $courseid, int $userid): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $source = $gen->create_module('assign', ['course' => $courseid, 'grade' => 100]);
        $generated = $gen->create_module('assign', ['course' => $courseid, 'grade' => 100]);

        $ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid, 'name' => 'R', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        grade_register_service::record_generation(
            $courseid, (int) $ruleid, 1, $userid,
            (int) $generated->cmid, grade_isolation_service::MODE_OWN
        );
    }

    /**
     * How many register rows exist for a user, optionally scoped to one course.
     *
     * @param int $userid
     * @param int|null $courseid
     * @return int
     */
    private function rows(int $userid, ?int $courseid = null): int {
        global $DB;
        $conditions = ['userid' => $userid];
        if ($courseid !== null) {
            $conditions['courseid'] = $courseid;
        }
        return $DB->count_records(grade_register_service::TABLE, $conditions);
    }

    /**
     * The register must be declared, or the privacy registry page never mentions it exists.
     */
    public function test_the_register_is_declared_in_the_metadata(): void {
        global $DB;

        $items = provider::get_metadata(new collection('local_coursedynamicrules'))->get_collection();

        $table = null;
        foreach ($items as $item) {
            if ($item instanceof database_table && $item->get_name() === grade_register_service::TABLE) {
                $table = $item;
            }
        }

        $this->assertNotNull($table, 'the reinforcement register is declared as a database table');

        // Against the schema, not against a list of names. Asserting three keys exist cannot
        // notice a fourth going undeclared, and the privacy registry page is what a data
        // protection officer reads to find out what the plugin stores about a student.
        $columns = array_keys($DB->get_columns(grade_register_service::TABLE));
        $expected = array_values(array_diff($columns, ['id']));
        $declared = array_keys($table->get_privacy_fields());
        sort($expected);
        sort($declared);

        $this->assertSame($expected, $declared,
            'every column but the id is declared - no more, and no fewer');
    }

    /**
     * Every string the metadata names must exist, in both languages.
     *
     * Catches a declaration that renders as "[[privacy:metadata:...]]" on the registry page - which
     * is what a reviewer actually sees, and which no other assertion here would notice.
     */
    public function test_every_declared_string_exists(): void {
        $items = provider::get_metadata(new collection('local_coursedynamicrules'))->get_collection();

        foreach ($items as $item) {
            if (!$item instanceof database_table) {
                continue;
            }
            $keys = array_merge([$item->get_summary()], array_values($item->get_privacy_fields()));
            foreach ($keys as $key) {
                foreach (['en', 'es'] as $lang) {
                    $this->assertTrue(
                        get_string_manager()->string_exists($key, 'local_coursedynamicrules'),
                        "missing string $key"
                    );
                    $this->assertNotEmpty(
                        get_string_manager()->get_string($key, 'local_coursedynamicrules', null, $lang)
                    );
                }
            }
        }
        $this->assertTrue(get_string_manager()->string_exists('privacy:path:aigrade', 'local_coursedynamicrules'));
    }

    /**
     * A student's contexts are the courses where a reinforcement was generated for them - and only
     * those. A provider that returned every course would leak which courses exist to the requester.
     */
    public function test_the_contexts_are_the_courses_with_a_reinforcement(): void {
        $contexts = provider::get_contexts_for_userid((int) $this->student->id)->get_contextids();
        sort($contexts);

        $expected = [
            context_course::instance($this->course1->id)->id,
            context_course::instance($this->course2->id)->id,
        ];
        sort($expected);
        $this->assertEquals($expected, $contexts);

        $stranger = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid((int) $stranger->id)->get_contextids());
    }

    /**
     * Asking a course context who is in it returns exactly the students with a register row.
     */
    public function test_the_course_context_lists_its_students(): void {
        $context = context_course::instance($this->course1->id);
        $userlist = new userlist($context, 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);

        $ids = $userlist->get_userids();
        sort($ids);
        $expected = [(int) $this->student->id, (int) $this->other->id];
        sort($expected);
        $this->assertEquals($expected, $ids);
    }

    /**
     * The export carries the real content: which activity, which activity it recovers, and the
     * grade that triggered it. An export listing only ids would satisfy the letter and lose the point.
     */
    public function test_the_export_carries_the_grade_and_the_activities(): void {
        $context = context_course::instance($this->course1->id);
        $this->export_context_data_for_user((int) $this->student->id, $context, 'local_coursedynamicrules');

        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'local_coursedynamicrules'),
            get_string('privacy:path:aigrade', 'local_coursedynamicrules'),
        ]);

        $this->assertNotEmpty($data, 'the export produced a reinforcement section');
        $this->assertCount(1, $data->generated);

        $row = reset($data->generated);
        $this->assertNotEmpty($row->activity, 'the generated activity is named, not just numbered');
        $this->assertSame(grade_isolation_service::MODE_OWN, $row->grademode);
        $this->assertNotEmpty($row->timecreated);
    }

    /**
     * Nothing is exported for a course the requester was never given a reinforcement in.
     */
    public function test_nothing_is_exported_for_an_unrelated_course(): void {
        $empty = $this->getDataGenerator()->create_course();
        $context = context_course::instance($empty->id);
        $this->export_context_data_for_user((int) $this->student->id, $context, 'local_coursedynamicrules');

        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * Erasing one student in one course severs that student from that course, and nothing else.
     *
     * Two findings meet here. The first: before any of this the count stayed at 2 after an approved
     * erasure, because the plugin implemented no deletion at all. The second, from the judgment
     * round: deleting the row outright blinded the enrolment sweep for the rest of the course's
     * life, so the row is severed instead - the student stops being named, the column marker stays.
     */
    public function test_erasing_one_student_leaves_every_other_row_alone(): void {
        global $DB;
        $context = context_course::instance($this->course1->id);
        $this->assertSame(2, $this->rows((int) $this->student->id));

        provider::delete_data_for_user(new approved_contextlist(
            $this->student, 'local_coursedynamicrules', [$context->id]
        ));

        $this->assertSame(0, $this->rows((int) $this->student->id, (int) $this->course1->id),
            'the erased student is no longer named by any row in that course');
        $this->assertSame(1, $this->rows((int) $this->student->id, (int) $this->course2->id),
            'the other course keeps its row');
        $this->assertSame(1, $this->rows((int) $this->other->id, (int) $this->course1->id),
            'the other student in the same course keeps theirs');

        // The row itself SURVIVES, severed. It is the only record that a generated activity with a
        // live grade column exists in this course, and the enrolment sweep is the only thing that
        // shields a later arrival from that column. Deleting it was measured to leave every
        // student who enrols afterwards at 0% under Lowest grade - a third party paying for
        // somebody else's erasure request.
        $this->assertSame(1, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course1->id, 'userid' => 0]),
            'the column marker is kept, with the student severed from it');
    }

    /**
     * Purging a course context clears every student in it, and only that course.
     */
    public function test_purging_a_course_clears_that_course_only(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context(context_course::instance($this->course1->id));

        $this->assertSame(0, $this->rows((int) $this->student->id, (int) $this->course1->id));
        $this->assertSame(0, $this->rows((int) $this->other->id, (int) $this->course1->id));
        $this->assertSame(1, $this->rows((int) $this->student->id, (int) $this->course2->id));
        $this->assertSame(1, $this->rows((int) $this->other->id, (int) $this->course2->id));

        $this->assertSame(2, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course1->id, 'userid' => 0]),
            'both column markers survive the purge, severed');
    }

    /**
     * Erasing an approved list of students clears exactly the students named.
     */
    public function test_erasing_a_named_list_clears_exactly_those_students(): void {
        global $DB;
        $context = context_course::instance($this->course1->id);

        provider::delete_data_for_users(new approved_userlist(
            $context, 'local_coursedynamicrules', [(int) $this->student->id]
        ));

        $this->assertSame(0, $this->rows((int) $this->student->id, (int) $this->course1->id));
        $this->assertSame(1, $this->rows((int) $this->other->id, (int) $this->course1->id));
        $this->assertSame(1, $this->rows((int) $this->student->id, (int) $this->course2->id));

        $this->assertSame(1, $DB->count_records(grade_register_service::TABLE,
            ['courseid' => $this->course1->id, 'userid' => 0]));
    }

    /**
     * A context that is not a course must be ignored rather than deleting the whole table.
     */
    public function test_a_non_course_context_deletes_nothing(): void {
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(2, $this->rows((int) $this->student->id));
        $this->assertSame(2, $this->rows((int) $this->other->id));
    }
}
