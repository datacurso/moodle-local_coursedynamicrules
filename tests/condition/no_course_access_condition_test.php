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

namespace local_coursedynamicrules\condition;

use local_coursedynamicrules\condition\no_course_access\no_course_access_condition;
use local_coursedynamicrules\core\condition;
use local_coursedynamicrules\form\conditions\condition_form;
use stdClass;

/**
 * Tests for the no_course_access condition: input validation and "never accessed" semantics.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\condition\no_course_access\no_course_access_condition
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class no_course_access_condition_test extends \advanced_testcase {
    /**
     * Build a condition instance from raw params.
     *
     * @param array $params Condition params.
     * @param int $courseid Course id.
     * @return no_course_access_condition
     */
    private function create_condition(array $params, int $courseid): no_course_access_condition {
        $record = new stdClass();
        $record->ruleid = 1;
        $record->conditiontype = 'no_course_access';
        $record->params = json_encode($params);

        return new no_course_access_condition($record, $courseid);
    }

    /**
     * Enrol a user with an explicit enrolment start time.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param int $timestart Enrolment start timestamp.
     * @return void
     */
    private function enrol_at(int $courseid, int $userid, int $timestart): void {
        global $DB;
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $manual->enrol_user($instance, $userid, $studentrole, $timestart);
    }

    /**
     * An invalid stored period must not make the condition match a user who accessed recently.
     *
     * @covers ::evaluate
     */
    public function test_evaluate_returns_false_for_invalid_period(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        // User accessed the course just now, so a valid rule would never match.
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => time(),
        ]);

        $condition = $this->create_condition(
            ['periodvalue' => '', 'periodunit' => 'days', 'nexttimeperiod' => time()],
            $course->id
        );

        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
        $this->assertDebuggingCalled();
    }

    /**
     * Data provider of invalid period values.
     *
     * @return array
     */
    public static function invalid_period_provider(): array {
        return [
            'empty' => [''],
            'non numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-5'],
        ];
    }

    /**
     * Invalid period values must be rejected at save time, never persisted.
     *
     * @dataProvider invalid_period_provider
     * @covers ::save_condition
     * @param string $value Invalid period value.
     */
    public function test_save_condition_rejects_invalid_period(string $value): void {
        global $DB;

        $this->resetAfterTest(true);

        $condition = $this->create_condition([], 1);

        $formdata = (object) ['ruleid' => 1, 'periodvalue' => $value, 'periodunit' => 'days'];

        try {
            $condition->save_condition($formdata);
            $this->fail("Expected invalid_parameter_exception for period value '{$value}'");
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records('local_coursedynamicrules_condition'));
        }
    }

    /**
     * Insert a rule row belonging to the given course and return its id.
     *
     * @param int $courseid Course id.
     * @return int Rule id.
     */
    private function create_rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'A rule',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A round-trip create -> edit must persist exactly one row, same id, with the mutated
     * periodvalue/periodunit. The stored 'nexttimeperiod' throttle timestamp is reconciled via
     * adjust_runtime_param() (v2 contract, engram obs #1310/FIX3-10) rather than force-won: this
     * edit LENGTHENS the period (30 days -> 60 weeks), so min(stored, now + new period) must pick
     * the ORIGINAL stored value, since it is already sooner than the newly recomputed deadline. The
     * stored value is seeded far enough in the future here that it is unambiguously still LESS than
     * any possible "now + 60 weeks" recompute, so this genuinely pins the min() outcome rather than
     * merely happening to match it by coincidence of wall-clock timing.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_round_trip_preserves_nexttimeperiod_on_edit(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days']);

        $id = $condition->get_id();
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $storedparams = json_decode($stored->params);
        $this->assertSame(30, $storedparams->periodvalue);

        // Seed the stored throttle strictly LATER than the natural "+30 days" default, but still
        // strictly SOONER than any "+60 weeks" recompute at edit time - this makes the eventual
        // min() outcome unambiguous, instead of relying on "+30 days" happening to already be less
        // than "+60 weeks" by construction alone.
        $originalnexttimeperiod = time() + (45 * DAYSECS);
        $storedparams->nexttimeperiod = $originalnexttimeperiod;
        $DB->set_field(condition::TABLE, 'params', json_encode($storedparams), ['id' => $id]);
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);

        // Preload uses the base default: names are flat (addGroup(..., false)); the stray
        // 'nexttimeperiod' key in $params is inert since no form field is named that way.
        $reflection = new \ReflectionClass(condition_form::class);
        $forminstance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('preload_defaults');
        $method->setAccessible(true);
        $defaults = $method->invoke($forminstance, $storedparams);
        $this->assertSame(30, $defaults['periodvalue']);
        $this->assertSame('days', $defaults['periodunit']);

        // Edit: mutate periodvalue/periodunit (lengthened); nexttimeperiod must survive unchanged
        // because it is still the sooner of the two candidates.
        $editcondition = new no_course_access_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '60', 'periodunit' => 'weeks']);

        $this->assertEquals($id, $editcondition->get_id());
        $this->assertEquals(1, $DB->count_records(condition::TABLE, ['ruleid' => $ruleid]));

        $final = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $finalparams = json_decode($final->params);
        $this->assertSame(60, $finalparams->periodvalue);
        $this->assertSame('weeks', $finalparams->periodunit);
        $this->assertSame(
            $originalnexttimeperiod,
            $finalparams->nexttimeperiod,
            'nexttimeperiod must survive the edit: min(stored, recomputed) picks stored because it is sooner (v2 contract)'
        );
    }

    /**
     * FIX3-10(a): re-saving with the SAME periodvalue/periodunit must preserve 'nexttimeperiod'
     * byte-for-byte - no recompute at all, matching decision v2 (engram obs #1310).
     *
     * @covers ::save_condition
     */
    public function test_save_condition_preserves_nexttimeperiod_when_period_is_unchanged(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days']);

        $id = $condition->get_id();
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $originalnexttimeperiod = json_decode($stored->params)->nexttimeperiod;

        $editcondition = new no_course_access_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days']);

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $this->assertSame($originalnexttimeperiod, $final->nexttimeperiod);
    }

    /**
     * FIX3-10(b): shortening the period must let the throttle advance to the new (earlier) deadline
     * instead of force-winning a stored value that is now far too generous.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_shortens_nexttimeperiod_when_period_is_reduced(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '365', 'periodunit' => 'days']);

        $id = $condition->get_id();

        // Force the stored throttle far into the future, well beyond what a shortened period would
        // compute from now.
        $farfuture = time() + (300 * DAYSECS);
        $storedparams = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $storedparams->nexttimeperiod = $farfuture;
        $DB->set_field(condition::TABLE, 'params', json_encode($storedparams), ['id' => $id]);

        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $before = time();
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '1', 'periodunit' => 'days']);
        $after = time();

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $this->assertLessThan($farfuture, $final->nexttimeperiod);
        $this->assertGreaterThanOrEqual($before + DAYSECS, $final->nexttimeperiod);
        $this->assertLessThanOrEqual($after + DAYSECS, $final->nexttimeperiod);
    }

    /**
     * FIX3-10(c): lengthening the period must keep the stored (earlier) deadline - editing must
     * never make the rule immediately due, and a stored deadline that is already sooner than the
     * newly (longer) computed one must win.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_keeps_stored_nexttimeperiod_when_period_is_lengthened(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '1', 'periodunit' => 'days']);

        $id = $condition->get_id();
        $originalnexttimeperiod = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]))->nexttimeperiod;

        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '365', 'periodunit' => 'days']);

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $this->assertSame($originalnexttimeperiod, $final->nexttimeperiod);
    }

    /**
     * FIX3-10(d1): a stored 'nexttimeperiod' JSON null must NOT silently re-arm the throttle to
     * "due immediately" - property_exists() (not isset()) routes it through adjust_runtime_param(),
     * which recomputes "now + new period" instead.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_recomputes_nexttimeperiod_when_stored_value_is_null(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days']);

        $id = $condition->get_id();
        $storedparams = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $storedparams->nexttimeperiod = null;
        $DB->set_field(condition::TABLE, 'params', json_encode($storedparams), ['id' => $id]);

        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $before = time();
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'weeks']);
        $after = time();

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $this->assertNotNull($final->nexttimeperiod);
        $this->assertGreaterThanOrEqual($before + (30 * WEEKSECS), $final->nexttimeperiod);
        $this->assertLessThanOrEqual($after + (30 * WEEKSECS), $final->nexttimeperiod);
    }

    /**
     * FIX3-10(d2, pinned): a row with NO 'nexttimeperiod' key at all (pre-dating this throttle
     * entirely) is untouched by runtime_param_keys() reconciliation (property_exists() is false),
     * so the freshly-computed insert-only default from save_condition() (time(), i.e. "due now")
     * is what ends up persisted. This is documented, unchanged legacy behaviour - only the JSON
     * NULL case (d1) was hardened by FIX3-10, not the absent-key case.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_uses_default_now_when_nexttimeperiod_key_is_absent(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) [
            'id' => null,
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            'params' => json_encode(['periodvalue' => 30, 'periodunit' => 'days']),
        ];
        $record->id = $DB->insert_record(condition::TABLE, $record);

        $stored = $DB->get_record(condition::TABLE, ['id' => $record->id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $before = time();
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'weeks']);
        $after = time();

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $record->id]));
        $this->assertGreaterThanOrEqual($before, $final->nexttimeperiod);
        $this->assertLessThanOrEqual($after, $final->nexttimeperiod);
    }

    /**
     * FIX4: a legacy row can have periodvalue stored as a numeric STRING (pre-dates the (int) cast
     * in save_condition()) and/or periodunit stored with different casing/whitespace. Editing with
     * the SAME period (from the operator's point of view) must still land in the "unchanged" branch
     * of adjust_runtime_param() and preserve nexttimeperiod byte-for-byte, instead of a strict
     * type/format mismatch defeating the comparison and forcing a needless recompute.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_treats_legacy_string_stored_period_as_unchanged(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $legacynexttimeperiod = time() + (10 * DAYSECS);
        $record = (object) [
            'id' => null,
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            // Legacy-shaped params: periodvalue as a STRING, periodunit with stray whitespace/case.
            'params' => json_encode([
                'periodvalue' => '30',
                'periodunit' => ' Days ',
                'nexttimeperiod' => $legacynexttimeperiod,
            ]),
        ];
        $record->id = $DB->insert_record(condition::TABLE, $record);

        $stored = $DB->get_record(condition::TABLE, ['id' => $record->id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        // Same period from the operator's perspective (30 days), submitted in normalised form.
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days']);

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $record->id]));
        $this->assertSame(
            $legacynexttimeperiod,
            $final->nexttimeperiod,
            'legacy string/whitespace-mismatched period must still be recognised as unchanged'
        );
    }

    /**
     * FIX4: if the recompute ("now + new period") fails - e.g. a periodunit that bypassed form
     * validation and cannot be parsed by strtotime() - adjust_runtime_param() must never let the
     * stored deadline regress to a false timestamp; it must keep whatever was already stored.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_keeps_stored_nexttimeperiod_when_recompute_fails(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) ['id' => null, 'ruleid' => $ruleid, 'conditiontype' => 'no_course_access', 'params' => json_encode([])];
        $condition = new no_course_access_condition($record, $course->id);
        $condition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '10', 'periodunit' => 'days']);

        $id = $condition->get_id();
        $originalnexttimeperiod = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]))->nexttimeperiod;

        // Edit with a DIFFERENT periodvalue (forces the period-changed branch) but a periodunit that
        // clean_param(PARAM_ALPHA) leaves intact yet strtotime() cannot parse - simulating a
        // malformed submission that bypassed the form's own periodunit validation. PHP's strtotime()
        // is surprisingly lenient with short garbage unit words (e.g. "zzz" fuzzy-matches to
        // something rather than failing) - "wibblewobble" is empirically confirmed (via a direct
        // strtotime() probe) to make it return false, which is the actual case this guards against.
        $stored = $DB->get_record(condition::TABLE, ['id' => $id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '20', 'periodunit' => 'wibblewobble']);

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $id]));
        $this->assertSame(
            $originalnexttimeperiod,
            $final->nexttimeperiod,
            'a failed recompute must never regress the stored deadline'
        );
    }

    /**
     * FIX4: condition::upsert() must not throw when the stored 'params' column decodes to
     * something other than a JSON object (e.g. a stray '[]'), even though this condition declares a
     * runtime param key via runtime_param_keys(). property_exists() on a non-object throws a
     * TypeError under PHP 8 - the is_object() guard must short-circuit before that call.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_does_not_throw_when_stored_params_is_not_an_object(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);

        $record = (object) [
            'id' => null,
            'ruleid' => $ruleid,
            'conditiontype' => 'no_course_access',
            // Decodes to an empty PHP array, not a stdClass - is_object() on it is false.
            'params' => '[]',
        ];
        $record->id = $DB->insert_record(condition::TABLE, $record);

        $stored = $DB->get_record(condition::TABLE, ['id' => $record->id], '*', MUST_EXIST);
        $editcondition = new no_course_access_condition($stored, $course->id);
        $editcondition->save_condition((object) ['ruleid' => $ruleid, 'periodvalue' => '10', 'periodunit' => 'days']);

        $final = json_decode($DB->get_field(condition::TABLE, 'params', ['id' => $record->id]));
        $this->assertSame(10, $final->periodvalue);
        $this->assertIsInt($final->nexttimeperiod);
    }

    /**
     * A valid period is persisted as an integer.
     *
     * @covers ::save_condition
     */
    public function test_save_condition_persists_valid_period(): void {
        global $DB;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $ruleid = $this->create_rule($course->id);
        $condition = $this->create_condition([], $course->id);

        $formdata = (object) ['ruleid' => $ruleid, 'periodvalue' => '30', 'periodunit' => 'days'];
        $condition->save_condition($formdata);

        $record = $DB->get_record('local_coursedynamicrules_condition', ['ruleid' => $ruleid], '*', MUST_EXIST);
        $params = json_decode($record->params);

        $this->assertSame(30, $params->periodvalue);
        $this->assertSame('days', $params->periodunit);
    }

    /**
     * A recently enrolled user who never accessed must NOT match before the period elapses.
     *
     * @covers ::evaluate
     */
    public function test_recent_enrolment_never_accessed_does_not_match(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_at($course->id, $user->id, time() - (5 * DAYSECS)); // Enrolled 5 days ago.

        $condition = $this->create_condition(
            ['periodvalue' => '30', 'periodunit' => 'days', 'nexttimeperiod' => 0],
            $course->id
        );
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
    }

    /**
     * A long-enrolled user who never accessed must match once the period has elapsed.
     *
     * @covers ::evaluate
     */
    public function test_old_enrolment_never_accessed_matches(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enrol_at($course->id, $user->id, time() - (40 * DAYSECS)); // Enrolled 40 days ago.

        $condition = $this->create_condition(
            ['periodvalue' => '30', 'periodunit' => 'days', 'nexttimeperiod' => 0],
            $course->id
        );
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertTrue($result);
    }

    /**
     * A user with no enrolment must not match (cannot measure the period).
     *
     * @covers ::evaluate
     */
    public function test_no_enrolment_does_not_match(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user(); // Not enrolled.

        $condition = $this->create_condition(
            ['periodvalue' => '30', 'periodunit' => 'days', 'nexttimeperiod' => 0],
            $course->id
        );
        $result = $condition->evaluate((object) ['courseid' => $course->id, 'userid' => $user->id]);

        $this->assertFalse($result);
    }
}
