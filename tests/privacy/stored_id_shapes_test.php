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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;

/**
 * Which stored values name a student, and therefore whom this provider may speak for.
 *
 * The provider answers one question about every value in a restriction's user list: is this a
 * student's id? What it answers decides who appears in a data export, whose id an approved erasure
 * removes, and who is listed as a data subject of that activity - so both kinds of wrong answer are
 * expensive, and they are expensive in opposite ways.
 *
 * Claiming somebody the list does not name means erasing an id inside a request approved for this
 * component, and attesting about a person whose data is not there. NOT claiming somebody the list
 * does name is worse and quieter: their data stops being exported and erased while tool_dataprivacy
 * reports the request completed.
 *
 * The reference is core. availability_user decides access with a LOOSE in_array() over the values
 * exactly as stored, so a value is a student's id when core would let that student in because of
 * it. Measured against core over seventeen stored forms, that leaves exactly two deliberate
 * departures, both here: a stored `true` and a stored object make core admit somebody through loose
 * comparison, and neither is anybody's id. A provider may not report a person as a data subject
 * because a piece of malformed data happens to compare equal to their id; that is an access defect
 * of core's, not a record of personal data.
 *
 * None of these shapes can be produced by this plugin - every writer re-indexes and writes ids - nor
 * through the interface, whose picker only ever emits real user ids. On the reference site on
 * 2026-09-17 all 34 stored ids across 19 restrictions were integers or plain numeric strings, and
 * none was malformed. These tests therefore fix a contract rather than repair an observed failure.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stored_id_shapes_test extends \core_privacy\tests\provider_testcase {
    /**
     * Put a restriction carrying this plugin's marker on a module, with a hand-built user list.
     *
     * Raw JSON on purpose: the shapes under test are the ones a builder would normalise away.
     *
     * @param int $cmid The module.
     * @param string $userlistjson The value of the node's userids key, as it is stored.
     * @return void
     */
    private function store_marked_list(int $cmid, string $userlistjson): void {
        global $DB;
        $marker = enableactivity_action::marker_prefix() . '1';
        $DB->set_field(
            'course_modules',
            'availability',
            '{"op":"&","c":[{"type":"user","userids":' . $userlistjson . ',"source":"' . $marker . '"}],"showc":[false]}',
            ['id' => $cmid]
        );
        rebuild_course_cache($DB->get_field('course_modules', 'course', ['id' => $cmid]), true);
    }

    /**
     * Whether core itself lets this user into the module, which is the reference these tests use.
     *
     * @param int $cmid The module.
     * @param int $userid The user.
     * @return bool
     */
    private function core_lets_in(int $cmid, int $userid): bool {
        $information = '';
        $cm = get_fast_modinfo($GLOBALS['DB']->get_field('course_modules', 'course', ['id' => $cmid]))
            ->get_cm($cmid);
        return (new \core_availability\info_module($cm))->is_available($information, false, $userid);
    }

    /**
     * Whether the provider reports this user as a data subject of that module.
     *
     * @param int $cmid The module.
     * @param int $userid The user.
     * @return bool
     */
    private function provider_claims(int $cmid, int $userid): bool {
        $userlist = new userlist(\context_module::instance($cmid), 'local_coursedynamicrules');
        provider::get_users_in_context($userlist);
        return in_array($userid, array_map('intval', $userlist->get_userids()), true);
    }

    /**
     * Skip when availability_user is absent, because core is the reference these tests compare to.
     *
     * Without that plugin core does not evaluate the restriction at all - it ignores a restriction
     * whose plugin is unavailable, so EVERY user gets in. That does not merely break the comparison:
     * it would make "core lets this student in" true for the wrong reason, and the test that asserts
     * it would pass while measuring nothing. It is a third-party plugin and a declared dependency of
     * this one, but the CI pipeline does not install it.
     *
     * @return void
     */
    private function require_the_reference(): void {
        if (!\core_plugin_manager::instance()->get_plugin_info('availability_user')) {
            $this->markTestSkipped('availability_user is not installed; core would let everybody in.');
        }
    }

    /**
     * A value that merely starts with a student's id is not that student.
     *
     * Core compares loosely against the value as stored, and under PHP 8 "501abc" does not equal
     * 501 - so that student never gets in because of it. Reading it as the number it starts with
     * makes this provider speak for somebody the restriction does not name.
     *
     * @return void
     */
    public function test_a_value_that_merely_starts_with_an_id_does_not_name_that_student(): void {
        $this->resetAfterTest(true);
        $this->require_the_reference();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_marked_list((int) $module->cmid, '["' . (int) $student->id . 'abc"]');

        $this->assertFalse(
            $this->core_lets_in((int) $module->cmid, (int) $student->id),
            'Precondition: core must really be keeping this student out, or the test proves nothing.'
        );

        $this->assertFalse(
            $this->provider_claims((int) $module->cmid, (int) $student->id),
            'The provider reported a student as a data subject of an activity whose restriction does '
                . 'not name them: it read a malformed value as the number it happens to start with.'
        );

        $this->assertSame(
            [],
            provider::get_contexts_for_userid((int) $student->id)->get_contextids(),
            'The same student appears as holding data in that context, which an approved erasure '
                . 'would then act on.'
        );
    }

    /**
     * The guest is not a data subject because a stored value happens to equal one.
     *
     * A stored `true` compares equal to any user id under core's loose comparison, so core does let
     * somebody in - but `true` is nobody's id. Reporting user 1 here would attest that this plugin
     * holds the guest's personal data in that activity, which it does not.
     *
     * @return void
     */
    public function test_the_guest_is_not_a_data_subject_because_a_value_compares_equal_to_one(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_marked_list((int) $module->cmid, '[true]');

        $guestid = (int) $GLOBALS['CFG']->siteguest;
        $this->assertGreaterThan(0, $guestid, 'Precondition: the site must have a guest account.');

        $this->assertFalse(
            $this->provider_claims((int) $module->cmid, $guestid),
            'The guest was reported as a data subject of this activity because a malformed value '
                . 'casts to their user id. The plugin holds no data about them there.'
        );
    }

    /**
     * Every numeric way of writing a student's id still names that student.
     *
     * The guard on the repair rather than on the defect, and the one that matters most in practice:
     * ids are stored as strings far more often than as integers - 33 of the 34 on the reference site
     * on 2026-09-17 - and core accepts any numeric string equal to the id, whitespace, sign and
     * decimal point included. A rule that only accepted plain digits would stop claiming these
     * students while core kept letting them in: their data would silently stop being exported and
     * erased. That rule was drafted during this work and this test is what rejects it.
     *
     * @return void
     */
    public function test_every_numeric_way_of_writing_an_id_still_names_the_student(): void {
        $this->resetAfterTest(true);
        $this->require_the_reference();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $id = (int) $student->id;

        $forms = [
            'integer' => (string) $id,
            'plain string' => '"' . $id . '"',
            'leading zero' => '"0' . $id . '"',
            'leading space' => '" ' . $id . '"',
            'trailing space' => '"' . $id . ' "',
            'explicit sign' => '"+' . $id . '"',
            'decimal point' => '"' . $id . '.0"',
        ];

        foreach ($forms as $name => $stored) {
            $this->store_marked_list((int) $module->cmid, '[' . $stored . ']');

            $this->assertTrue(
                $this->core_lets_in((int) $module->cmid, $id),
                "Precondition ({$name}): core must let this student in, or the comparison below proves nothing."
            );

            $this->assertTrue(
                $this->provider_claims((int) $module->cmid, $id),
                "A student core lets in ({$name}) is not reported as a data subject: their data would "
                    . 'stop being exported and erased while the request is reported as completed.'
            );
        }
    }

    /**
     * An erasure does not leave behind an id that names somebody the restriction never named.
     *
     * The write side of the same question. The erasure rewrites the node from what it read, so a
     * reader that turns a malformed value into a number writes that number back as a real grant -
     * `true` becomes user 1, and the guest ends up genuinely listed in an activity's restrictions.
     *
     * @return void
     */
    public function test_an_erasure_does_not_write_back_an_id_the_restriction_never_named(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->store_marked_list((int) $module->cmid, '[' . (int) $student->id . ',true]');

        $user = $DB->get_record('user', ['id' => $student->id], '*', MUST_EXIST);
        $contextlist = provider::get_contexts_for_userid((int) $student->id);
        provider::delete_data_for_user(
            new approved_contextlist($user, 'local_coursedynamicrules', $contextlist->get_contextids())
        );

        $node = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $module->cmid]))->c[0];
        $remaining = array_values((array) ($node->userids ?? []));

        $this->assertSame(
            [],
            array_values(array_filter($remaining, static function ($value): bool {
                return is_int($value) || (is_string($value) && is_numeric($value));
            })),
            'The erasure wrote back a real user id derived from a value that named nobody, granting '
                . 'that account access to the activity.'
        );
    }
}
