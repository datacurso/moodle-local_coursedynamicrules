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

namespace local_coursedynamicrules\core;

/**
 * set_active() records the moment the ENGINE switches a rule off, so the badge can tell that state
 * apart from a manual pause.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\core\rule::set_active
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_self_deactivation_test extends \advanced_testcase {
    /**
     * Insert an active, sealed rule and return a rule instance for it.
     *
     * @return array{0: rule, 1: int} The rule instance and its id.
     */
    private function active_rule(): array {
        global $DB;

        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $record = (object) [
            'courseid' => $courseid,
            'name' => 'Self-deactivation probe',
            'description' => '',
            'active' => 1,
            'timeactivated' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = (int) $DB->insert_record('local_coursedynamicrules_rule', $record);

        return [new rule($record, []), $record->id];
    }

    /**
     * The engine switching a rule off stamps timeautodeactivated.
     */
    public function test_engine_deactivation_stamps_the_moment(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$rule, $ruleid] = $this->active_rule();

        $rule->set_active(false);

        $row = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(0, $row->active);
        $this->assertNotNull($row->timeautodeactivated, 'Engine deactivation must record the moment.');
        $this->assertGreaterThan(0, (int) $row->timeautodeactivated);
    }

    /**
     * Reactivating clears the stamp, so a later manual pause never reads as a stale "executed".
     */
    public function test_reactivation_clears_the_stamp(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$rule, $ruleid] = $this->active_rule();

        $rule->set_active(false);
        $rule->set_active(true);

        $row = $DB->get_record('local_coursedynamicrules_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->active);
        $this->assertNull($row->timeautodeactivated, 'Reactivation must clear the engine self-deactivation stamp.');
    }
    /**
     * The engine switching a rule off is the one state change no event covered: the nine audit
     * events are all fired from human actions (editrule.php, conditions.php, actions.php and the
     * duplicator), so an administrator asking the logs "who stopped this rule?" found nothing at
     * all. It gets its own event type rather than reusing rule_updated, because the whole point of
     * the question is telling an engine write apart from a person's edit.
     *
     * @covers \local_coursedynamicrules\event\rule_autodeactivated
     */
    public function test_engine_deactivation_is_audited(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$rule, $ruleid] = $this->active_rule();
        $courseid = (int) $DB->get_field('local_coursedynamicrules_rule', 'courseid', ['id' => $ruleid]);

        $sink = $this->redirectEvents();
        $rule->set_active(false);
        $events = $sink->get_events();
        $sink->close();

        $stops = array_values(array_filter(
            $events,
            fn($e) => $e instanceof \local_coursedynamicrules\event\rule_autodeactivated
        ));
        $this->assertCount(1, $stops, 'The engine stopping a rule must leave exactly one trace.');
        $this->assertSame($ruleid, (int) $stops[0]->objectid);
        $this->assertSame($courseid, (int) $stops[0]->courseid);
        $this->assertNotEmpty($stops[0]->get_description());
    }

    /**
     * And the trace must not name a person who did nothing.
     *
     * The engine's only caller is a scheduled task, and cron runs as a copy of the site admin
     * (lib/classes/cron.php:659,671), while core defaults an event's actor to $USER->id
     * (lib/classes/event/base.php:204). Left alone, a log report asked "who stopped this rule?"
     * answers with the administrator's name - the same answer a real administrator action would
     * give, which is the very confusion this event exists to remove. Core reserves a value for
     * exactly this case: USER_OTHER, "when actor is not an actual user but system, cli or cron"
     * (base.php:93-96), used by the grade engine at lib/grade/grade_item.php:885 and
     * lib/grade/grade_category.php:681.
     *
     * @covers \local_coursedynamicrules\event\rule_autodeactivated
     */
    public function test_the_engine_stop_is_attributed_to_the_system_not_a_person(): void {
        global $USER;

        $this->resetAfterTest(true);
        // Stand in for cron, which runs as a copy of the site administrator.
        $this->setAdminUser();

        [$rule] = $this->active_rule();

        $sink = $this->redirectEvents();
        $rule->set_active(false);
        $events = $sink->get_events();
        $sink->close();

        $stops = array_values(array_filter(
            $events,
            fn($e) => $e instanceof \local_coursedynamicrules\event\rule_autodeactivated
        ));
        $this->assertCount(1, $stops, 'Precondition: the stop is audited exactly once.');
        $this->assertSame(
            \core\event\base::USER_OTHER,
            (int) $stops[0]->userid,
            'An engine write must be attributed to the system, not to whoever cron happens to run as.'
        );
        $this->assertNotSame(
            (int) $USER->id,
            (int) $stops[0]->userid,
            'The administrator did nothing: the log must not name them.'
        );
    }

    /**
     * Neither is stopping a rule that was already stopped. Nobody stopped anything, so the log must
     * not claim someone did: the entry means "the engine switched this off", and an engine that
     * wrote 0 over a 0 changed nothing.
     *
     * @covers \local_coursedynamicrules\core\rule::set_active
     */
    public function test_stopping_an_already_stopped_rule_is_not_audited(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$rule] = $this->active_rule();
        $rule->set_active(false);

        $sink = $this->redirectEvents();
        $rule->set_active(false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(
            0,
            array_filter(
                $events,
                fn($e) => $e instanceof \local_coursedynamicrules\event\rule_autodeactivated
            ),
            'A rule that was already off was not stopped again; the log must not say it was.'
        );
    }

    /**
     * Reactivation is not a deactivation: it must not leave the trace that means "the engine
     * stopped this", or the log would answer the auditor's question with the opposite of the truth.
     *
     * @covers \local_coursedynamicrules\core\rule::set_active
     */
    public function test_reactivation_is_not_audited_as_a_stop(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        [$rule] = $this->active_rule();
        $rule->set_active(false);

        $sink = $this->redirectEvents();
        $rule->set_active(true);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(
            0,
            array_filter(
                $events,
                fn($e) => $e instanceof \local_coursedynamicrules\event\rule_autodeactivated
            ),
            'Switching a rule back on must not be recorded as an engine stop.'
        );
    }
}
