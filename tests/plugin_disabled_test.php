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

use local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form;
use local_coursedynamicrules\task\rule_task;

/**
 * Entry points of local_coursedynamicrules when the plugin is switched off in the Datacurso AI provider.
 *
 * Disabled: the course navigation entry is hidden, the pages and the dynamic form refuse with
 * plugindisabled, the observers queue no rule execution and the tasks skip.
 * Enabled (no state saved in the provider): every entry point behaves as before.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursedynamicrules\observer\user_graded
 * @covers     \local_coursedynamicrules\observer\course_module_completion_updated
 * @covers     \local_coursedynamicrules\task\rule_task
 * @covers     \local_coursedynamicrules\task\no_complete_activity_task
 * @covers     \local_coursedynamicrules\task\no_course_access_task
 * @covers     \local_coursedynamicrules\task\course_inactivity_task
 * @covers     \local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form
 * @covers     ::local_coursedynamicrules_extend_navigation_course
 */
final class plugin_disabled_test extends \advanced_testcase {
    /**
     * Switch the plugin off in the provider.
     *
     * @return void
     */
    private function disable_plugin(): void {
        set_config('plugin_enabled_local_coursedynamicrules', '0', 'aiprovider_datacurso');
    }

    /**
     * Build the course navigation as admin and return the URLs of the nodes the plugin added.
     *
     * @return string[]
     */
    private function navigation_urls(): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/coursedynamicrules/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $navigation = new \navigation_node(['text' => 'Course', 'type' => \navigation_node::TYPE_COURSE]);
        local_coursedynamicrules_extend_navigation_course($navigation, $course, \context_course::instance($course->id));

        $urls = [];
        foreach ($navigation->children as $child) {
            $urls[] = $child->action instanceof \moodle_url ? $child->action->out_omit_querystring() : '';
        }
        return $urls;
    }

    /**
     * The course navigation entry is added when enabled and hidden when disabled.
     *
     * @return void
     */
    public function test_navigation_entry_follows_the_state(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();

        $rulesurl = (new \moodle_url('/local/coursedynamicrules/rules.php'))->out_omit_querystring();
        $this->assertContains($rulesurl, $this->navigation_urls());

        $this->disable_plugin();
        $this->assertSame([], $this->navigation_urls());
    }

    /**
     * Grade a user in an assignment, which fires user_graded and runs the observer.
     *
     * @return void
     */
    private function grade_a_student(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // The observers queue nothing for a course that holds no rule.
        $generator->get_plugin_generator('local_coursedynamicrules')->create_rule($course->id, []);
        $student = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $course->id,
        ]);
        $gradeitem->update_final_grade($student->id, 50, 'test');
    }

    /**
     * Enabled: grading a student queues the rule execution task.
     *
     * @return void
     */
    public function test_observer_queues_rule_task_when_enabled(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->grade_a_student();

        $this->assertNotEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));
    }

    /**
     * Disabled: grading a student queues nothing.
     *
     * @return void
     */
    public function test_observer_queues_nothing_when_disabled(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->disable_plugin();

        $this->grade_a_student();

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));
    }

    /**
     * Disabled: the completion observer queues nothing.
     *
     * @return void
     */
    public function test_completion_observer_queues_nothing_when_disabled(): void {
        global $CFG;
        $this->require_ai_provider();
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        // The observers queue nothing for a course that holds no rule.
        $generator->get_plugin_generator('local_coursedynamicrules')->create_rule($course->id, []);
        $student = $generator->create_and_enrol($course, 'student');
        $page = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $completion = new \completion_info($course);

        $this->disable_plugin();
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));

        set_config('plugin_enabled_local_coursedynamicrules', '1', 'aiprovider_datacurso');
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $student->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertNotEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));
    }

    /**
     * Scheduled and adhoc tasks of the plugin.
     *
     * @return array<string, array{0: string}>
     */
    public static function task_provider(): array {
        return [
            'rule_task' => [rule_task::class],
            'no_complete_activity_task' => [task\no_complete_activity_task::class],
            'no_course_access_task' => [task\no_course_access_task::class],
            'course_inactivity_task' => [task\course_inactivity_task::class],
        ];
    }

    /**
     * Run a task and return what it printed.
     *
     * @param string $classname Task class.
     * @return string
     */
    private function run_task(string $classname): string {
        $course = $this->getDataGenerator()->create_course();
        $task = new $classname();
        if ($task instanceof rule_task) {
            $task->set_custom_data((object) [
                'courseid' => $course->id,
                'userid' => get_admin()->id,
                'conditiontypes' => ['grade_in_activity'],
            ]);
        }
        ob_start();
        $task->execute();
        return (string) ob_get_clean();
    }

    /**
     * Disabled: every task reports the plugin is disabled and stops.
     *
     * @dataProvider task_provider
     * @param string $classname Task class.
     * @return void
     */
    public function test_task_skips_when_disabled(string $classname): void {
        $this->require_ai_provider();
        $this->resetAfterTest();
        $this->disable_plugin();

        $this->assertStringContainsString(
            get_string('task_skipped_plugindisabled', 'local_coursedynamicrules'),
            $this->run_task($classname)
        );
    }

    /**
     * Enabled: every task runs as before, without the disabled notice.
     *
     * @dataProvider task_provider
     * @param string $classname Task class.
     * @return void
     */
    public function test_task_runs_when_enabled(string $classname): void {
        $this->require_ai_provider();
        $this->resetAfterTest();

        $this->assertStringNotContainsString(
            get_string('task_skipped_plugindisabled', 'local_coursedynamicrules'),
            $this->run_task($classname)
        );
    }

    /**
     * Submit the dynamic grade form through the core dynamic form web service as admin.
     *
     * @return string|null The moodle_exception error code, or null when the submission was accepted.
     */
    private function submit_dynamic_form(): ?string {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        // The form lists the activities completed by grade, so the course needs one.
        $generator->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]);
        $this->setAdminUser();
        try {
            \core_form\external\dynamic_form::execute(
                dynamic_grade_in_activity_form::class,
                http_build_query(['courseid' => $course->id])
            );
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        return null;
    }

    /**
     * The dynamic grade form refuses only while disabled, with the provider's message for an admin.
     *
     * @return void
     */
    public function test_dynamic_form_follows_the_state(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();

        $this->assertNotContains($this->submit_dynamic_form(), ['plugindisabled', 'plugindisabled_admin']);

        $this->disable_plugin();
        $this->assertSame('plugindisabled_admin', $this->submit_dynamic_form());
    }

    /**
     * Page scripts of the plugin.
     *
     * @return array<string, array{0: string}>
     */
    public static function page_provider(): array {
        $pages = ['actions.php', 'conditions.php', 'deleteaction.php', 'deletecondition.php', 'deleterule.php',
            'duplicaterule.php', 'editrule.php', 'rules.php'];
        return array_combine($pages, array_map(static fn(string $page): array => [$page], $pages));
    }

    /**
     * Every page refuses through the provider right after the course login check.
     *
     * Page scripts cannot run inside PHPUnit, so the contract is checked on the script itself;
     * the refusal itself is the provider's require_enabled(), exercised by the dynamic form test above.
     *
     * @dataProvider page_provider
     * @param string $page Page script name.
     * @return void
     */
    public function test_page_requires_the_plugin_enabled(string $page): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/coursedynamicrules/' . $page);

        $login = strpos($source, 'require_login($course);');
        $gate = strpos($source, "plugin_settings::require_enabled('local_coursedynamicrules');");
        $this->assertNotFalse($login);
        $this->assertNotFalse($gate);
        $this->assertGreaterThan($login, $gate);
    }

    /**
     * Skip when the Datacurso AI provider is absent: the code under test asks it whether this plugin is enabled.
     *
     * @return void
     */
    private function require_ai_provider(): void {
        // The CI pipeline does not install declared dependencies; without the Datacurso AI provider this test cannot run.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }
}
