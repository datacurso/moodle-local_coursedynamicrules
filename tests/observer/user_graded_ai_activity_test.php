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

namespace local_coursedynamicrules\observer;

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursedynamicrules\action\createaiactivity\testable_createaiactivity_action;
use local_coursedynamicrules\task\rule_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/../fixtures/routed_createaiactivity_action.php');

/**
 * Repeated grade events create one AI activity per student (CDR-SEC-006).
 *
 * A regrade after the queued evaluation ran evaluates the rule again - which is right for a
 * notification and was wrong for the AI action: each regrade paid for and created another
 * activity. Driven end to end through the real grade observer and adhoc task.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
 * @covers     \local_coursedynamicrules\observer\user_graded
 * @covers     \local_coursedynamicrules\task\rule_task
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_graded_ai_activity_test extends \advanced_testcase {
    /**
     * Reset the testable action static seams after every test.
     */
    protected function tearDown(): void {
        testable_createaiactivity_action::reset();
        parent::tearDown();
    }

    /**
     * Skip when the AI companion plugins are absent, as they are on a CI checkout of this plugin alone.
     *
     * @return void
     */
    private function require_ai_stack(): void {
        foreach (['aiprovider_datacurso', 'local_coursegen'] as $component) {
            if (!\core_plugin_manager::instance()->get_plugin_info($component)) {
                $this->markTestSkipped($component . ' is not installed; the AI activity action requires it.');
            }
        }
    }

    /**
     * An AI client double that counts the generations it is asked for.
     *
     * @param int|null $inits Incremented on every POST /activity/init.
     * @return ai_course_api
     */
    private function counting_api_client(?int &$inits): ai_course_api {
        $inits = 0;
        $client = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['request', 'get_base_url'])
            ->getMock();
        $client->method('request')->willReturnCallback(function ($method, $path) use (&$inits) {
            if ($method === 'POST' && $path === '/activity/init') {
                $inits++;
                return ['thread_id' => 'thread-' . $inits, 'status' => 'pending'];
            }
            return [];
        });
        $client->method('get_base_url')->willReturn('https://ai.example.test/api/v1/');

        return $client;
    }

    /**
     * A rule "grade of the assignment >= 50" whose action generates an AI page.
     *
     * @param int $courseid Course id.
     * @param int $cmid Assignment course module.
     * @param \grade_item $item Assignment grade item.
     * @return void
     */
    private function insert_ai_rule(int $courseid, int $cmid, \grade_item $item): void {
        global $DB;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => 'AI reinforcement',
            'description' => 'test',
            'active' => 1,
            'lastexecutiontime' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $ruleid,
            'conditiontype' => 'grade_in_activity',
            'params' => json_encode(['cmid' => $cmid, 'gradeitemsconditions' => [
                'gradegte_0' => ['gradeitem' => (int) $item->id, 'itemnumber' => 0, 'condition' => 'gradegte', 'value' => 50],
            ]]),
        ]);
        // The routed type loads the testable action through the real rule_component_loader.
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'actiontype' => 'routedcreateaiactivity',
            'params' => json_encode([
                'message' => 'Create a page about fractions',
                'generateimages' => false,
                'sectionnum' => 0,
                'beforemod' => null,
            ]),
        ]);
    }

    /**
     * MindFree validation: grade, run the queue, regrade later, run it again - one AI activity.
     */
    public function test_repeated_grade_events_create_one_ai_activity(): void {
        global $DB;
        $this->require_ai_stack();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // The grade condition only reads activities with automatic completion.
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'grade' => 100,
        ]);
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'itemnumber' => 0,
        ]);
        $this->insert_ai_rule((int) $course->id, (int) $assign->cmid, $item);

        testable_createaiactivity_action::$client = $this->counting_api_client($inits);
        testable_createaiactivity_action::$streamevent = [
            'type' => 'completed',
            'result' => [
                'action' => 'create',
                'resource_type' => 'page',
                'parameters' => [
                    'modulename' => 'page',
                    'name' => 'AI reinforcement page',
                    'introeditor' => ['text' => '<p>Intro</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                    'page' => ['text' => '<p>Content</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
                    'display' => 5,
                    'printintro' => 0,
                    'printlastmodified' => 1,
                    'visible' => 1,
                    'cmidnumber' => '',
                ],
            ],
        ];

        $item->update_final_grade($student->id, 70);
        $this->runAdhocTasks(rule_task::class);
        $this->assertSame(1, $inits, 'Sanity: the first grade must generate the activity.');

        $item->update_final_grade($student->id, 85);
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc', ['classname' => '\\' . rule_task::class]),
            'Sanity: the regrade must queue a new evaluation, or nothing below is exercised.'
        );
        $this->runAdhocTasks(rule_task::class);

        $this->assertSame(1, $inits, 'A regrade must not pay for a second generation.');
        $this->assertEquals(1, $DB->count_records('page', ['course' => $course->id]), 'One AI activity per student.');
    }
}
