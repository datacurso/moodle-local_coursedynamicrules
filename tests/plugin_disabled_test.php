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
 * On Workplace the state is kept per tenant and the tenant that decides is always the tenant of the
 * user, never the tenant of the course: a user of a tenant that disabled the plugin is refused and
 * skipped in every course, including another tenant's course and a shared one, while the users of the
 * other tenants in the same course carry on.
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
 * @covers     \local_coursedynamicrules\local\tenant_context
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
     * A scheduled task asks the provider per enrolled user, and only for a rule it really walks, so for
     * $due the course gets a started course, an enrolled student and a rule that is due; otherwise the
     * rule is not due yet and an enabled task loads it and leaves it alone.
     *
     * @param string $classname Task class.
     * @param \stdClass|null $course Course of the rule, a new one when null.
     * @param int $userid User a queued rule_task acts for, the admin when 0.
     * @param bool $due Whether a scheduled task's rule is due, with a student to walk.
     * @return string
     */
    private function run_task(string $classname, ?\stdClass $course = null, int $userid = 0, bool $due = false): string {
        // A course that has not started yet: an enabled inactivity task loads its rule and leaves it alone.
        $startdate = $due ? time() - WEEKSECS : time() + YEARSECS;
        $course = $course ?? $this->getDataGenerator()->create_course(['startdate' => $startdate]);
        $task = new $classname();
        if ($task instanceof rule_task) {
            $task->set_custom_data((object) [
                'courseid' => $course->id,
                'userid' => $userid ?: get_admin()->id,
                'conditiontypes' => ['grade_in_activity'],
            ]);
        } else {
            if ($due) {
                $this->getDataGenerator()->create_and_enrol($course, 'student');
            }
            $this->create_scheduled_rule((int) $course->id, $task, $due);
        }
        ob_start();
        $task->execute();
        return (string) ob_get_clean();
    }

    /**
     * Create an active rule holding the condition a scheduled task evaluates, and a notification to students.
     *
     * @param int $courseid Course id.
     * @param \core\task\scheduled_task $task Scheduled task of the plugin.
     * @param bool $due Whether the condition is due now; when false an enabled task leaves the rule alone.
     * @return int Rule id.
     */
    private function create_scheduled_rule(int $courseid, \core\task\scheduled_task $task, bool $due = false): int {
        global $DB;

        $types = [
            task\no_complete_activity_task::class => 'no_complete_activity',
            task\no_course_access_task::class => 'no_course_access',
            task\course_inactivity_task::class => 'course_inactivity',
        ];
        $rule = $this->getDataGenerator()->get_plugin_generator('local_coursedynamicrules')->create_rule($courseid, []);
        $when = $due ? time() - 100 : time() + YEARSECS;
        $DB->insert_record('local_coursedynamicrules_condition', (object) [
            'ruleid' => $rule->get_id(),
            'conditiontype' => $types[get_class($task)],
            'params' => json_encode([
                'expectedcompletiondate' => $when,
                'nexttimeperiod' => $when,
                'periodvalue' => 1,
                'periodunit' => 'days',
            ]),
        ]);
        // A rule without an action never walks its users.
        $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $rule->get_id(),
            'actiontype' => 'sendnotification',
            'params' => json_encode([
                'messagesubject' => 'Rule alert',
                'messagebody' => 'Rule alert body.',
                'primaryroleids' => [(int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST)],
                'copyroleids' => [],
            ]),
        ]);
        return (int) $rule->get_id();
    }

    /**
     * Create a Workplace tenant that owns a new course category, and a course inside it.
     *
     * @param string $name Tenant name.
     * @return array{0: \stdClass, 1: \stdClass} The tenant and its course.
     */
    private function create_tenant_course(string $name): array {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => $name . ' category']);
        $tenant = $generator->get_plugin_generator('tool_tenant')->create_tenant([
            'name' => $name,
            'categoryid' => $category->id,
        ]);
        return [$tenant, $generator->create_course(['category' => $category->id])];
    }

    /**
     * Create a user that belongs to a tenant.
     *
     * @param \stdClass $tenant Tenant.
     * @return \stdClass
     */
    private function create_tenant_user(\stdClass $tenant): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('tool_tenant')->create_user(['tenantid' => $tenant->id]);
    }

    /**
     * Switch the plugin off in the provider for one tenant only.
     *
     * @param int $tenantid Tenant id.
     * @return void
     */
    private function disable_plugin_for_tenant(int $tenantid): void {
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_coursedynamicrules', false, $tenantid);
    }

    /**
     * Skip unless Workplace tenancy is available.
     *
     * @return void
     */
    private function require_tenancy(): void {
        if (!class_exists(\tool_tenant\tenancy::class)) {
            $this->markTestSkipped('tool_tenant is not installed.');
        }
    }

    /**
     * Disabled (site default, no tenancy needed): every task skips the users it would act for and says so.
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
            $this->run_task($classname, null, 0, true)
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
     * The tenant comes from the user, whoever the current user is, and a missing user means the site default.
     *
     * @return void
     */
    public function test_tenant_comes_from_the_user(): void {
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenant] = $this->create_tenant_course('Tenant A');
        $user = $this->create_tenant_user($tenant);

        $this->assertSame((int) $tenant->id, local\tenant_context::get_tenant_id_for_user((int) $user->id));
        $this->assertSame(local\tenant_context::NO_TENANT, local\tenant_context::get_tenant_id_for_user(0));
    }

    /**
     * Disabled for tenant A: the filter drops the users of A and keeps the users of B, and counts the dropped ones.
     *
     * @return void
     */
    public function test_filter_users_keeps_only_enabled_tenants(): void {
        $this->require_ai_provider();
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta] = $this->create_tenant_course('Tenant A');
        [$tenantb] = $this->create_tenant_course('Tenant B');
        $usera = $this->create_tenant_user($tenanta);
        $userb = $this->create_tenant_user($tenantb);
        $this->disable_plugin_for_tenant((int) $tenanta->id);

        $tenants = new local\tenant_context();
        $users = [(object) ['id' => (int) $usera->id], (object) ['id' => (int) $userb->id]];
        $kept = array_map(static fn(\stdClass $user): int => $user->id, iterator_to_array($tenants->filter_users($users), false));

        $this->assertSame([(int) $userb->id], $kept);
        $this->assertSame(1, $tenants->take_skipped_count());
        $this->assertSame(0, $tenants->take_skipped_count());
    }

    /**
     * Disabled for tenant A: in a shared course a scheduled task acts for the user of B only.
     *
     * The course belongs to no tenant and cron runs as the admin, so only the users' tenants can decide.
     *
     * @return void
     */
    public function test_scheduled_task_skips_only_the_users_of_the_disabled_tenant(): void {
        global $DB;
        $this->require_ai_provider();
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta] = $this->create_tenant_course('Tenant A');
        [$tenantb] = $this->create_tenant_course('Tenant B');
        $usera = $this->create_tenant_user($tenanta);
        $userb = $this->create_tenant_user($tenantb);
        $shared = $this->getDataGenerator()->create_course();
        foreach ([$usera, $userb] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $shared->id, 'student');
            // Last access long ago, so the no-access condition holds for both.
            $DB->insert_record('user_lastaccess', (object) [
                'userid' => $user->id,
                'courseid' => $shared->id,
                'timeaccess' => time() - (40 * DAYSECS),
            ]);
        }
        $task = new task\no_course_access_task();
        $ruleid = $this->create_scheduled_rule((int) $shared->id, $task, true);
        $this->disable_plugin_for_tenant((int) $tenanta->id);

        $sink = $this->redirectMessages();
        ob_start();
        $task->execute();
        $output = (string) ob_get_clean();
        $recipients = array_map(
            static fn(\stdClass $message): int => (int) $message->useridto,
            $sink->get_messages_by_component('local_coursedynamicrules')
        );
        $sink->close();

        $this->assertNotContains((int) $usera->id, $recipients);
        $this->assertContains((int) $userb->id, $recipients);
        $notice = get_string('task_skipped_plugindisabled', 'local_coursedynamicrules');
        $this->assertStringContainsString("course {$shared->id}, rule {$ruleid}, 1 users: {$notice}", $output);
    }

    /**
     * Disabled for tenant A: a queued evaluation follows the tenant of the user it was queued for.
     *
     * The user of A is skipped in a course of the enabled tenant B, and the user of B runs in a course of
     * the disabled tenant A: the tenant of the course plays no part.
     *
     * @return void
     */
    public function test_rule_task_follows_the_tenant_of_the_user(): void {
        $this->require_ai_provider();
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta, $coursea] = $this->create_tenant_course('Tenant A');
        [$tenantb, $courseb] = $this->create_tenant_course('Tenant B');
        $usera = $this->create_tenant_user($tenanta);
        $userb = $this->create_tenant_user($tenantb);
        $this->disable_plugin_for_tenant((int) $tenanta->id);

        $notice = get_string('task_skipped_plugindisabled', 'local_coursedynamicrules');
        $this->assertStringContainsString($notice, $this->run_task(rule_task::class, $courseb, (int) $usera->id));
        $this->assertStringNotContainsString($notice, $this->run_task(rule_task::class, $coursea, (int) $userb->id));
    }

    /**
     * Disabled for tenant A: grading a user of A queues nothing, even in a course of the enabled tenant B.
     *
     * @return void
     */
    public function test_observer_follows_the_tenant_of_the_graded_user(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->require_ai_provider();
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta] = $this->create_tenant_course('Tenant A');
        [$tenantb, $courseb] = $this->create_tenant_course('Tenant B');
        $usera = $this->create_tenant_user($tenanta);
        $userb = $this->create_tenant_user($tenantb);
        $this->disable_plugin_for_tenant((int) $tenanta->id);

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('local_coursedynamicrules')->create_rule($courseb->id, []);
        $generator->enrol_user($usera->id, $courseb->id, 'student');
        $generator->enrol_user($userb->id, $courseb->id, 'student');
        $assign = $generator->create_module('assign', ['course' => $courseb->id]);
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $courseb->id,
        ]);

        $gradeitem->update_final_grade($usera->id, 50, 'test');
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));

        $gradeitem->update_final_grade($userb->id, 50, 'test');
        $this->assertNotEmpty(\core\task\manager::get_adhoc_tasks(rule_task::class));
    }

    /**
     * Disabled for tenant A: a teacher of A is refused and sees no menu entry in a course of the enabled tenant B,
     * while a teacher of B keeps both in a course of the disabled tenant A.
     *
     * @return void
     */
    public function test_interactive_gate_follows_the_tenant_of_the_current_user(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/coursedynamicrules/lib.php');
        $this->require_ai_provider();
        $this->require_tenancy();
        $this->resetAfterTest();
        $this->setAdminUser();

        [$tenanta, $coursea] = $this->create_tenant_course('Tenant A');
        [$tenantb, $courseb] = $this->create_tenant_course('Tenant B');
        $usera = $this->create_tenant_user($tenanta);
        $userb = $this->create_tenant_user($tenantb);
        // Both hold the pair of capabilities the menu entry asks for, so only the tenant can hide it.
        $this->getDataGenerator()->enrol_user($usera->id, $courseb->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($userb->id, $coursea->id, 'editingteacher');
        $this->disable_plugin_for_tenant((int) $tenanta->id);

        foreach ([[$usera, $courseb, false], [$userb, $coursea, true]] as [$user, $course, $enabled]) {
            $this->setUser($user);
            $this->assertSame($enabled, local\tenant_context::is_enabled_for_current_user());

            $navigation = new \navigation_node(['text' => 'Course', 'type' => \navigation_node::TYPE_COURSE]);
            local_coursedynamicrules_extend_navigation_course($navigation, $course, \context_course::instance($course->id));
            $this->assertCount($enabled ? 1 : 0, $navigation->children);

            $errorcode = null;
            try {
                local\tenant_context::require_enabled_for_current_user();
            } catch (\moodle_exception $e) {
                $errorcode = $e->errorcode;
            }
            $this->assertSame($enabled ? null : 'plugindisabled', $errorcode);
        }
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
     * Every page refuses through the provider, for the tenant of the current user, right after the course login check.
     *
     * Page scripts cannot run inside PHPUnit, so the contract is checked on the script itself;
     * the refusal itself is provider_dependency::require_enabled(), reached through tenant_context and
     * exercised by the dynamic form test above and by tests/local/provider_dependency_test.php.
     *
     * @dataProvider page_provider
     * @param string $page Page script name.
     * @return void
     */
    public function test_page_requires_the_plugin_enabled(string $page): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/coursedynamicrules/' . $page);

        $login = strpos($source, 'require_login($course);');
        $gate = strpos($source, 'tenant_context::require_enabled_for_current_user();');
        $this->assertStringNotContainsString('for_course', $source);
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
