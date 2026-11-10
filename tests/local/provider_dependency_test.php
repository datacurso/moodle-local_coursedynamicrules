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

namespace local_coursedynamicrules\local;

use local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form;
use local_coursedynamicrules\task\rule_task;

/**
 * The plugin keeps the site working when the Datacurso AI provider is older than 1.7.0.
 *
 * Provider 1.7.0 introduced `\aiprovider_datacurso\local\plugin_settings`. A site that updated this
 * plugin while keeping an older provider fell over on every page ("Class not found"). Every use of
 * that class now goes through provider_dependency: with an older provider the navigation entry,
 * observers and tasks skip quietly and the pages and the dynamic form refuse with a notice that
 * tells an administrator to update the provider.
 *
 * The older provider is simulated through the guard's test seam, so these tests run with or
 * without the provider installed; the source scan makes sure no code reaches the provider class
 * around the guard.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursedynamicrules\local\provider_dependency
 * @covers     \local_coursedynamicrules\observer\user_graded
 * @covers     \local_coursedynamicrules\task\rule_task
 * @covers     \local_coursedynamicrules\task\no_complete_activity_task
 * @covers     \local_coursedynamicrules\task\no_course_access_task
 * @covers     \local_coursedynamicrules\task\course_inactivity_task
 * @covers     \local_coursedynamicrules\form\conditions\dynamic_grade_in_activity_form
 * @covers     ::local_coursedynamicrules_extend_navigation_course
 */
final class provider_dependency_test extends \advanced_testcase {
    /**
     * Restore the real provider detection after every test.
     *
     * @return void
     */
    protected function tearDown(): void {
        provider_dependency::set_available_for_testing(null);
        parent::tearDown();
    }

    /**
     * Simulate a provider older than 1.7.0, which has no plugin_settings class.
     *
     * @return void
     */
    private function simulate_old_provider(): void {
        provider_dependency::set_available_for_testing(false);
    }

    /**
     * Skip when the Datacurso AI provider 1.7.0 or later is not installed.
     *
     * @return void
     */
    private function require_ai_provider(): void {
        // The CI pipeline does not install declared dependencies; these checks need the real provider.
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
    }

    /**
     * With an older provider the guard reports it unavailable and the plugin as not enabled.
     *
     * @return void
     */
    public function test_old_provider_is_not_available_nor_enabled(): void {
        $this->simulate_old_provider();

        $this->assertFalse(provider_dependency::is_available());
        $this->assertFalse(provider_dependency::is_enabled());
    }

    /**
     * An administrator is told to update the provider; the link points to the plugins overview.
     *
     * @return void
     */
    public function test_require_enabled_tells_an_admin_to_update_the_provider(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->simulate_old_provider();

        try {
            provider_dependency::require_enabled();
            $this->fail('require_enabled() must refuse with an older provider');
        } catch (\moodle_exception $e) {
            $this->assertSame('providerupdaterequired_admin', $e->errorcode);
            $this->assertSame('local_coursedynamicrules', $e->module);
            $this->assertStringContainsString('1.7.0', $e->getMessage());
        }
    }

    /**
     * Anyone else gets a generic notice, without the provider details.
     *
     * @return void
     */
    public function test_require_enabled_gives_others_a_generic_notice(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->simulate_old_provider();

        try {
            provider_dependency::require_enabled();
            $this->fail('require_enabled() must refuse with an older provider');
        } catch (\moodle_exception $e) {
            $this->assertSame('providerupdaterequired', $e->errorcode);
            $this->assertStringNotContainsString('1.7.0', $e->getMessage());
        }
    }

    /**
     * Build the course navigation as admin and return how many nodes the plugin added.
     *
     * @return int
     */
    private function navigation_children(): int {
        global $CFG;
        require_once($CFG->dirroot . '/local/coursedynamicrules/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $navigation = new \navigation_node(['text' => 'Course', 'type' => \navigation_node::TYPE_COURSE]);
        local_coursedynamicrules_extend_navigation_course($navigation, $course, \context_course::instance($course->id));
        return $navigation->children->count();
    }

    /**
     * The navigation callback adds nothing and does not fail with an older provider.
     *
     * @return void
     */
    public function test_navigation_callback_adds_nothing_with_an_old_provider(): void {
        $this->resetAfterTest();
        $this->simulate_old_provider();

        $this->assertSame(0, $this->navigation_children());
    }

    /**
     * The grade observer queues nothing and does not fail with an older provider.
     *
     * @return void
     */
    public function test_observer_queues_nothing_with_an_old_provider(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->simulate_old_provider();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
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

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));
    }

    /**
     * Scheduled and adhoc tasks of the plugin.
     *
     * @return array<string, array{0: string}>
     */
    public static function task_provider(): array {
        return [
            'rule_task' => [rule_task::class],
            'no_complete_activity_task' => [\local_coursedynamicrules\task\no_complete_activity_task::class],
            'no_course_access_task' => [\local_coursedynamicrules\task\no_course_access_task::class],
            'course_inactivity_task' => [\local_coursedynamicrules\task\course_inactivity_task::class],
        ];
    }

    /**
     * Every task skips with a line that names the provider update, and does not fail.
     *
     * @dataProvider task_provider
     * @param string $classname Task class.
     * @return void
     */
    public function test_task_skips_with_an_old_provider(string $classname): void {
        $this->resetAfterTest();
        $this->simulate_old_provider();

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
        $output = (string) ob_get_clean();

        $this->assertStringContainsString(
            get_string('task_skipped_providerupdaterequired', 'local_coursedynamicrules'),
            $output
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
     * The dynamic grade form refuses with the controlled notice, never with a fatal error.
     *
     * @return void
     */
    public function test_dynamic_form_refuses_with_the_notice_with_an_old_provider(): void {
        $this->resetAfterTest();
        $this->simulate_old_provider();

        $this->assertSame('providerupdaterequired_admin', $this->submit_dynamic_form());
    }

    /**
     * With the provider installed nothing changes: available, enabled, and the form is not refused by the guard.
     *
     * @return void
     */
    public function test_with_the_provider_installed_nothing_changes(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();

        $this->assertTrue(provider_dependency::is_available());
        $this->assertTrue(provider_dependency::is_enabled());
        provider_dependency::require_enabled();
        $this->assertSame(1, $this->navigation_children());
        $this->assertNotContains(
            $this->submit_dynamic_form(),
            ['providerupdaterequired', 'providerupdaterequired_admin', 'plugindisabled', 'plugindisabled_admin']
        );
    }

    /**
     * With the provider installed, switching the plugin off in the provider still disables it through the guard.
     *
     * @return void
     */
    public function test_with_the_provider_installed_its_disabled_state_is_honoured(): void {
        $this->require_ai_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('plugin_enabled_local_coursedynamicrules', '0', 'aiprovider_datacurso');

        $this->assertTrue(provider_dependency::is_available());
        $this->assertFalse(provider_dependency::is_enabled());
        try {
            provider_dependency::require_enabled();
            $this->fail('require_enabled() must refuse while the plugin is disabled in the provider');
        } catch (\moodle_exception $e) {
            $this->assertSame('plugindisabled_admin', $e->errorcode);
            $this->assertSame('aiprovider_datacurso', $e->module);
        }
    }

    /**
     * No plugin code outside the guard names the provider class introduced in 1.7.0.
     *
     * One reference left outside the guard is enough to bring every page down again on a site
     * with an older provider, which the seam above cannot see.
     *
     * @return void
     */
    public function test_only_the_guard_uses_the_provider_settings_class(): void {
        global $CFG;
        $root = $CFG->dirroot . '/local/coursedynamicrules';
        $guard = realpath($root . '/classes/local/provider_dependency.php');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $offenders = [];
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (substr($path, -4) !== '.php' || preg_match('~^(tests|private|vendor|node_modules)/~', $relative)) {
                continue;
            }
            if (realpath($path) === $guard) {
                continue;
            }
            $source = file_get_contents($path);
            if (preg_match('~aiprovider_datacurso\\\\local\\\\|\bplugin_settings::~', $source)) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders, 'Use \local_coursedynamicrules\local\provider_dependency instead');
    }
}
