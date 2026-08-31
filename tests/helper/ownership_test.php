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
 * Tests for cross-course ownership validation.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\ownership
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ownership_test extends \advanced_testcase {
    /** @var int Course A id. */
    private int $coursea;

    /** @var int Course B id (attacker's course). */
    private int $courseb;

    /** @var int Rule id in course A. */
    private int $ruleid;

    /** @var int Condition id in course A's rule. */
    private int $conditionid;

    /** @var int Action id in course A's rule. */
    private int $actionid;

    /** @var int A second rule id in course A (same course, different rule). */
    private int $otherruleid;

    /**
     * Set up two courses and a rule (with a condition and an action) in course A, plus a second
     * rule in course A used to prove a component's ruleid is checked, not just its course.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);

        $this->coursea = $this->getDataGenerator()->create_course()->id;
        $this->courseb = $this->getDataGenerator()->create_course()->id;

        $this->ruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->coursea,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->conditionid = $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $this->ruleid,
            'conditiontype' => 'complete_activity',
            'params' => json_encode(['cmid' => 1]),
        ]);
        $this->actionid = $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $this->ruleid,
            'actiontype' => 'sendnotification',
            'params' => json_encode(['messagesubject' => 's']),
        ]);

        $this->otherruleid = $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $this->coursea,
            'name' => 'Another rule in the same course',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A rule is returned when it belongs to the requested course.
     *
     * @covers ::get_rule
     */
    public function test_get_rule_returns_owned_rule(): void {
        $rule = ownership::get_rule($this->ruleid, $this->coursea);
        $this->assertEquals($this->ruleid, $rule->id);
    }

    /**
     * Loading a rule that belongs to another course is rejected.
     *
     * @covers ::get_rule
     */
    public function test_get_rule_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_rule($this->ruleid, $this->courseb);
    }

    /**
     * Reports whether a rule belongs to the given course.
     *
     * @covers ::rule_belongs_to_course
     */
    public function test_rule_belongs_to_course(): void {
        $this->assertTrue(ownership::rule_belongs_to_course($this->ruleid, $this->coursea));
        $this->assertFalse(ownership::rule_belongs_to_course($this->ruleid, $this->courseb));
    }

    /**
     * A condition is returned when its rule belongs to the course.
     *
     * @covers ::get_condition
     */
    public function test_get_condition_returns_owned(): void {
        $condition = ownership::get_condition($this->conditionid, $this->coursea, $this->ruleid);
        $this->assertEquals($this->conditionid, $condition->id);
    }

    /**
     * Loading a condition whose rule belongs to another course is rejected.
     *
     * @covers ::get_condition
     */
    public function test_get_condition_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_condition($this->conditionid, $this->courseb, $this->ruleid);
    }

    /**
     * A condition belonging to a DIFFERENT rule in the SAME course must be rejected: the request's
     * ruleid is part of the ownership contract, not just the course id (G8).
     *
     * @covers ::get_condition
     */
    public function test_get_condition_rejects_mismatched_ruleid_same_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_condition($this->conditionid, $this->coursea, $this->otherruleid);
    }

    /**
     * An action is returned when its rule belongs to the course.
     *
     * @covers ::get_action
     */
    public function test_get_action_returns_owned(): void {
        $action = ownership::get_action($this->actionid, $this->coursea, $this->ruleid);
        $this->assertEquals($this->actionid, $action->id);
    }

    /**
     * Loading an action whose rule belongs to another course is rejected.
     *
     * @covers ::get_action
     */
    public function test_get_action_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_action($this->actionid, $this->courseb, $this->ruleid);
    }

    /**
     * An action belonging to a DIFFERENT rule in the SAME course must be rejected: the request's
     * ruleid is part of the ownership contract, not just the course id (G8).
     *
     * @covers ::get_action
     */
    public function test_get_action_rejects_mismatched_ruleid_same_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        ownership::get_action($this->actionid, $this->coursea, $this->otherruleid);
    }

    /**
     * A create (no submitted id) resolves to 0 so the caller inserts a new rule.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_returns_zero_for_create(): void {
        $this->setAdminUser();
        $context = \context_course::instance($this->coursea);
        $this->assertSame(0, ownership::resolve_writable_ruleid(0, $this->coursea, $context));
        $this->assertSame(0, ownership::resolve_writable_ruleid('', $this->coursea, $context));
    }

    /**
     * An update targeting an owned rule resolves to that rule id.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_returns_owned_id(): void {
        $this->setAdminUser();
        $this->assertSame(
            $this->ruleid,
            ownership::resolve_writable_ruleid($this->ruleid, $this->coursea, \context_course::instance($this->coursea))
        );
    }

    /**
     * An update targeting a foreign course's rule (tampered hidden id) is rejected.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_resolve_writable_ruleid_rejects_foreign_course(): void {
        $this->expectException(\dml_missing_record_exception::class);
        $this->setAdminUser();
        ownership::resolve_writable_ruleid($this->ruleid, $this->courseb, \context_course::instance($this->courseb));
    }

    /**
     * Grant a user exactly one of this plugin's capabilities in the course, nothing else.
     *
     * A non-editing teacher is the vehicle because that archetype holds none of them, so whatever
     * the test adds is the whole story.
     *
     * @param string $capability Frankenstyle capability name.
     * @return \context_course The course context the tests decide against.
     */
    private function user_holding_only(string $capability): \context_course {
        global $DB;

        $context = \context_course::instance($this->coursea);
        $user = $this->getDataGenerator()->create_and_enrol(
            $DB->get_record('course', ['id' => $this->coursea]),
            'teacher'
        );
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability($capability, CAP_ALLOW, $teacherrole, $context->id, true);
        $this->setUser($user);

        return $context;
    }

    /**
     * A role allowed only to CREATE cannot smuggle an update through the hidden form id.
     *
     * The page decides its capability from the URL: no ?id means creating, so createrule is
     * checked. But the write target is the FORM's hidden id, a client-controlled field, and course
     * ownership alone used to be the only validation on it. A user allowed to create but denied
     * updaterule could GET the page without an id - passing the create check - and POST the id of
     * an existing rule in the same course. The denied update went through.
     *
     * The capability therefore has to be decided HERE, on the id that will actually be written,
     * not on the id the URL advertised. Deciding it at the page reads one value and acts on
     * another - the exact seam this suite exists to close.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_a_create_only_role_cannot_update_through_the_hidden_id(): void {
        $context = $this->user_holding_only('local/coursedynamicrules:createrule');

        $this->expectException(\required_capability_exception::class);
        ownership::resolve_writable_ruleid($this->ruleid, $this->coursea, $context);
    }

    /**
     * The mirror: a role allowed only to UPDATE cannot create by posting id=0.
     *
     * @covers ::resolve_writable_ruleid
     */
    public function test_an_update_only_role_cannot_create_through_a_zero_id(): void {
        $context = $this->user_holding_only('local/coursedynamicrules:updaterule');

        $this->expectException(\required_capability_exception::class);
        ownership::resolve_writable_ruleid(0, $this->coursea, $context);
    }

    /**
     * Omitting the context is a fatal error, not a silent skip of the capability check.
     *
     * The first version of this method made the context optional, with a comment claiming that was
     * "so old call sites fail loudly in review rather than silently". Two independent reviewers
     * caught that the mechanism does the exact opposite: an optional parameter is what lets a
     * caller omit the argument and run the write path with NO capability check at all - silently,
     * guided by prose asserting the reverse. A required parameter is what fails loudly: PHP refuses
     * the call before any query runs.
     *
     * This test is the executable form of that argument. If somebody makes the parameter optional
     * again, the two-argument call below stops throwing and this goes red.
     */
    public function test_omitting_the_context_is_refused_not_skipped(): void {
        $this->setAdminUser();

        $this->expectException(\ArgumentCountError::class);
        ownership::resolve_writable_ruleid($this->ruleid, $this->coursea);
    }
}
