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

use local_coursedynamicrules\helper\availability_user_status;

/**
 * Behavioural cover for the capability enforcement and the availability warning.
 *
 * The cheap way to check an enforcement batch is to read the page sources as text and confirm the
 * require_capability() calls are written down. That is a structural claim: it cannot tell whether
 * an ordinary editing teacher still gets through, nor whether a denial is actually obeyed. This
 * class asserts the effect instead of the wording - real roles, real contexts, has_capability()
 * answering for a user. The one deliberate exception is the dormancy tripwire at the bottom, which
 * IS an occurrence scan, because there the occurrence is itself the claim under test: that nothing
 * consults the dormant capabilities.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversNothing
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capability_enforcement_test extends \advanced_testcase {
    /** @var string[] Capabilities that were declared but unchecked, and this batch now enforces. */
    private const PREVIOUSLY_INERT = [
        'local/coursedynamicrules:createrule',
        'local/coursedynamicrules:createaction',
        'local/coursedynamicrules:createcondition',
        'local/coursedynamicrules:viewrule',
        'local/coursedynamicrules:viewaction',
        'local/coursedynamicrules:viewcondition',
    ];

    /**
     * @var string[] Capabilities that are declared and STILL checked nowhere, on purpose.
     *
     * They belong to in-place component editing, which this release withholds entirely - the ?edit
     * endpoints bounce every request, refusing more than a capability check would. They stay
     * declared because removing a shipped capability erases any override an administrator has made
     * to it, and the editing flow that consumes them already exists on a future branch. What is NOT
     * acceptable is announcing them as enforced: an admin who prohibits one expecting the denial to
     * bind is being lied to. The dormancy test below keeps this list honest in both directions.
     */
    private const DECLARED_BUT_DORMANT = [
        // Empty since the bounded component editor woke updateaction/updatecondition
        // (2026-08-31): every declared capability is now consulted somewhere. The scan keeps
        // this honest in both directions - a capability added here must truly do nothing.
    ];

    /**
     * Enforcing a capability is only safe if the role that used to do the work still holds it.
     *
     * The capabilities were declared with the archetypes of the `manage*` capabilities that
     * stood in for them, so an ordinary editing teacher must pass every one of them. If this fails,
     * enforcing them locked out the very people the plugin is for.
     */
    public function test_an_editing_teacher_still_holds_every_newly_enforced_capability(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $missing = [];
        foreach (self::PREVIOUSLY_INERT as $capability) {
            if (!has_capability($capability, $context, $teacher)) {
                $missing[] = $capability;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'An editing teacher must keep every capability that is now enforced, or enforcing them '
            . 'locked out the plugin\'s own audience. Missing: ' . implode(', ', $missing)
        );
    }

    /**
     * A student must hold none of them, so enforcement is not vacuous.
     *
     * The control case of the pair above: if everybody passed, the checks would let anyone through
     * and the previous test would pass for the wrong reason.
     */
    public function test_a_student_holds_none_of_the_newly_enforced_capabilities(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $granted = [];
        foreach (self::PREVIOUSLY_INERT as $capability) {
            if (has_capability($capability, $context, $student)) {
                $granted[] = $capability;
            }
        }

        $this->assertSame([], $granted, 'A student must hold none of these. Granted: ' . implode(', ', $granted));
    }

    /**
     * A denial of one of the eight is now obeyed instead of being overridden by its manage* sibling.
     *
     * This is the whole point of the fix: before it, a role granted `managerule` could create rules
     * even with `createrule` explicitly prohibited, because nothing ever asked.
     */
    public function test_denying_createrule_is_obeyed_even_when_managerule_is_granted(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'rulesoperator']);
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        assign_capability('local/coursedynamicrules:managerule', CAP_ALLOW, $roleid, $context->id, true);
        assign_capability('local/coursedynamicrules:createrule', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(
            has_capability('local/coursedynamicrules:managerule', $context, $user),
            'Precondition: the operator must still be allowed to manage rules.'
        );
        $this->assertFalse(
            has_capability('local/coursedynamicrules:createrule', $context, $user),
            'A prohibited createrule must be refused; before this batch nothing asked, so the '
            . 'prohibition had no effect whatsoever.'
        );
    }

    /**
     * The warning's own condition, exercised rather than read.
     *
     * Grepping the listings for the notification call would only prove the warning is wired, not
     * that it fires at the right moment - this does: it drives availability_user through enabled
     * and disabled and asserts the helper's answer tracks the state.
     */
    public function test_availability_status_follows_the_plugin_being_enabled_or_disabled(): void {
        $this->resetAfterTest(true);

        \core\plugininfo\availability::enable_plugin('user', 1);
        $this->assertTrue(
            availability_user_status::is_enabled(),
            'With the per-user availability restriction enabled the rules can gate activities, so no '
            . 'warning is due.'
        );

        \core\plugininfo\availability::enable_plugin('user', 0);
        $this->assertFalse(
            availability_user_status::is_enabled(),
            'With the restriction disabled every gated activity is exposed, so the warning IS due.'
        );
    }

    /**
     * An editing teacher can DELETE what they can create.
     *
     * The three delete capabilities were manager-only, so the everyday flow was: a teacher creates
     * a component by mistake, cannot remove it, and escalates - multiplied by every teacher on the
     * site, a queue of requests for a two-click operation. Product decision 2026-08-31: whoever may
     * build rules may also unbuild them; RISK_DATALOSS stays declared so admins reviewing the role
     * still see the risk.
     *
     * Archetype defaults only reach NEW capabilities (accesslib's update_capabilities iterates
     * $newcaps), so this grant needs BOTH halves: db/access.php for fresh installs - which is what
     * this test's install-time role sees - and an upgrade step for every existing site, which the
     * companion upgrade test covers.
     */
    public function test_an_editing_teacher_can_delete_what_they_can_create(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            $this->assertTrue(
                has_capability('local/coursedynamicrules:' . $capability, $context, $teacher),
                "An editing teacher must hold {$capability}: they can create the thing, so making "
                . 'them escalate to remove it turns every mistake into a support request.'
            );
        }
    }

    /**
     * The dormant capabilities are checked NOWHERE in the plugin - and the moment that changes,
     * this test demands the announcement change with it.
     *
     * An occurrence scan is normally the wrong kind of assertion, but here occurrence IS the claim
     * under test: the changelog must not say "enforced" about a capability no code consults. If a
     * future change starts consulting updateaction or updatecondition (in-place editing returning,
     * say), this goes red - not because anything broke, but because PREVIOUSLY_INERT, the changelog
     * and the role documentation all need to move together, and this is the tripwire.
     */
    public function test_the_dormant_capabilities_are_consulted_nowhere(): void {
        global $CFG;

        $offenders = [];
        $root = $CFG->dirroot . '/local/coursedynamicrules';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS
        ));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root . '/', '', $file->getPathname());
            // Where a capability may legitimately be NAMED without being consulted: its declaration,
            // its language strings, and the two files that document its dormancy.
            if (preg_match('#^(db/access\.php|lang/|tests/capability_enforcement_test\.php|CHANGES\.md)#', $relative)) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            foreach (self::DECLARED_BUT_DORMANT as $capability) {
                if (strpos($content, 'coursedynamicrules:' . $capability) !== false) {
                    $offenders[] = "$relative consults $capability";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A dormant capability is now consulted somewhere: promote it out of DECLARED_BUT_DORMANT '
            . 'and update the changelog that currently tells administrators it does nothing.'
        );
    }
}
