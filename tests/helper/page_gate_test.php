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
 * The one door every listing page's capability decision goes through.
 *
 * A page script cannot be loaded from a unit test, and the acceptance runner fails any scenario
 * that lands on an exception page - so require_capability() calls written inline in rules.php,
 * conditions.php and actions.php had NO effect-level coverage at all: deleting all of them left
 * the whole suite green. That was found by a blind judge, and this file is the answer: the
 * decision lives in one helper, tested here with real roles holding exactly one capability at a
 * time, and a companion wiring test pins that every page actually calls it.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\page_gate
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_gate_test extends \advanced_testcase {
    /** @var \context_course Context of the probe course. */
    private \context_course $context;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->context = \context_course::instance($course->id);
    }

    /**
     * Put the current user in a role holding exactly these plugin capabilities.
     *
     * @param string[] $capabilities Capability shortnames.
     * @return void
     */
    private function acting_with(array $capabilities): void {
        $roleid = create_role('Gate probe', 'gateprobe', '');
        foreach ($capabilities as $capability) {
            assign_capability(
                'local/coursedynamicrules:' . $capability,
                CAP_ALLOW,
                $roleid,
                $this->context->id,
                true
            );
        }
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->context->instanceid, $roleid);
        $this->setUser($user);
    }

    /**
     * MDL-E2E-004: view without manage is refused, so the listing rejects the half-pair role.
     *
     * Every listing needs BOTH halves of its pair: view without manage is refused.
     *
     * @dataProvider components_provider
     * @covers ::require_listing
     * @param string $component
     */
    public function test_view_alone_does_not_open_the_listing(string $component): void {
        $this->acting_with(['view' . $component]);

        $this->expectException(\required_capability_exception::class);
        page_gate::require_listing($component, $this->context);
    }

    /**
     * MDL-E2E-004: manage without view is refused too, so the listing rejects the other half-pair.
     *
     * And manage without view is refused too - the pair is the gate, not either half.
     *
     * This is the half that used to be the WHOLE gate: before 1.8.3 only manage* was checked, and
     * the changelog warns custom-role administrators that view* is now required alongside it. This
     * test is that warning, executable.
     *
     * @dataProvider components_provider
     * @covers ::require_listing
     * @param string $component
     */
    public function test_manage_alone_does_not_open_the_listing(string $component): void {
        $this->acting_with(['manage' . $component]);

        $this->expectException(\required_capability_exception::class);
        page_gate::require_listing($component, $this->context);
    }

    /**
     * MDL-E2E-004: the exact view+manage pair opens the listing.
     *
     * The exact pair opens the listing.
     *
     * @dataProvider components_provider
     * @covers ::require_listing
     * @param string $component
     */
    public function test_the_pair_opens_the_listing(string $component): void {
        $this->acting_with(['view' . $component, 'manage' . $component]);

        page_gate::require_listing($component, $this->context);
        $this->assertTrue(true, 'No exception: the pair the pages advertise is the pair that opens them.');
    }

    /**
     * MDL-E2E-004: the listing pair alone cannot create; the create URL rejects without create*.
     *
     * Creating needs create*, whatever else the role holds.
     *
     * The add menu is only rendered for a role that holds it, but the component type arrives as a
     * URL parameter, and a URL is not a menu.
     *
     * @dataProvider creatable_components_provider
     * @covers ::require_creation
     * @param string $component
     */
    public function test_the_listing_pair_alone_cannot_create(string $component): void {
        $this->acting_with(['view' . $component, 'manage' . $component]);

        $this->expectException(\required_capability_exception::class);
        page_gate::require_creation($component, $this->context);
    }

    /**
     * MDL-E2E-004: with create* the creation branch opens.
     *
     * With create* the creation branch opens.
     *
     * @dataProvider creatable_components_provider
     * @covers ::require_creation
     * @param string $component
     */
    public function test_create_opens_the_creation_branch(string $component): void {
        $this->acting_with(['create' . $component]);

        page_gate::require_creation($component, $this->context);
        $this->assertTrue(true, 'No exception: create* is what the type branch demands.');
    }

    /**
     * The three listing components.
     *
     * @return array[]
     */
    public static function components_provider(): array {
        return [['rule'], ['condition'], ['action']];
    }

    /**
     * The components a ?type= URL can create.
     *
     * @return array[]
     */
    public static function creatable_components_provider(): array {
        return [['condition'], ['action']];
    }

    /**
     * The component destination: its own listing for a role holding that component's pair, and
     * otherwise whatever the rules listing allows - never a link into a refusal.
     *
     * @covers ::component_listing_url
     */
    public function test_the_component_listing_is_the_destination_for_its_own_pair(): void {
        $this->acting_with(['viewaction', 'manageaction']);

        $url = page_gate::component_listing_url('action', (int) $this->context->instanceid, 42, $this->context);

        $this->assertStringContainsString('/local/coursedynamicrules/actions.php', $url->out(false));
        $this->assertSame('42', (string) $url->param('ruleid'));
    }

    /**
     * A role that may delete a component but not enter its listing - the seam that deleted the
     * component and then showed a permission error - lands on the course page instead.
     *
     * @covers ::component_listing_url
     */
    public function test_deleting_without_the_component_pair_lands_on_the_course_page(): void {
        $this->acting_with(['deleteaction']);

        $url = page_gate::component_listing_url('action', (int) $this->context->instanceid, 42, $this->context);

        $this->assertStringContainsString('/course/view.php', $url->out(false));
        $this->assertStringNotContainsString('coursedynamicrules', $url->out(false));
    }

    /**
     * And it falls back to the RULES listing when the operator may enter that one, which is closer
     * to where they were than the course page.
     *
     * @covers ::component_listing_url
     */
    public function test_the_component_fallback_prefers_the_rules_listing_when_allowed(): void {
        $this->acting_with(['deleteaction', 'viewrule', 'managerule']);

        $url = page_gate::component_listing_url('action', (int) $this->context->instanceid, 42, $this->context);

        $this->assertStringContainsString('/local/coursedynamicrules/rules.php', $url->out(false));
    }

    /**
     * Where a page sends the operator when it is done: the listing for a role that may enter it.
     *
     * @covers ::listing_url
     */
    public function test_the_listing_is_the_destination_for_a_role_that_may_enter_it(): void {
        $this->acting_with(['viewrule', 'managerule']);

        $url = page_gate::listing_url((int) $this->context->instanceid, $this->context);

        $this->assertStringContainsString('/local/coursedynamicrules/rules.php', $url->out(false));
        $this->assertSame((string) $this->context->instanceid, (string) $url->param('courseid'));
    }

    /**
     * And the course page for a role that may not - the seam that had a page do the work and then
     * show a permission error, or offer a "back" link into a guaranteed refusal. A role that may
     * delete a rule, or manage its components, need not hold the rule listing's own pair.
     *
     * @dataProvider destinations_without_the_rule_pair
     * @covers ::listing_url
     * @param string[] $capabilities What the role holds instead of the rule pair.
     */
    public function test_the_course_page_is_the_destination_without_the_rule_pair(array $capabilities): void {
        $this->acting_with($capabilities);

        $url = page_gate::listing_url((int) $this->context->instanceid, $this->context);

        $this->assertStringContainsString('/course/view.php', $url->out(false));
        $this->assertStringNotContainsString('coursedynamicrules', $url->out(false));
        $this->assertSame((string) $this->context->instanceid, (string) $url->param('id'));
    }

    /**
     * Roles that reach one of these pages without holding the rules listing's pair.
     *
     * @return array<string, array{string[]}>
     */
    public static function destinations_without_the_rule_pair(): array {
        return [
            'may delete a rule only' => [['deleterule']],
            'holds the condition pair, not the rule pair' => [['viewcondition', 'managecondition']],
            'may create rules but not read the listing' => [['createrule']],
            'half the rule pair' => [['viewrule']],
        ];
    }

    /**
     * MDL-UNIT-023: each listing page is wired to consult the gate that requires its capabilities.
     *
     * The pages actually consult the gate - the wiring half of the coverage.
     *
     * An occurrence scan, deliberately: the effect tests above prove what the gate DOES, but they
     * cannot see whether any page calls it. This can. Delete the page_gate call from any of the
     * three listings and this names the file - which is precisely what happened to the inline
     * require_capability() lines this helper replaced: all of them could be deleted with the suite
     * staying green.
     *
     * @coversNothing
     */
    public function test_every_listing_page_consults_the_gate(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/coursedynamicrules/';
        $expected = [
            'rules.php' => ["page_gate::require_listing('rule'"],
            'conditions.php' => [
                "page_gate::require_listing('condition'",
                "page_gate::require_creation('condition'",
                'page_gate::listing_url(',
            ],
            'actions.php' => [
                "page_gate::require_listing('action'",
                "page_gate::require_creation('action'",
                'page_gate::listing_url(',
            ],
            // The pages that send the operator somewhere after doing the work. Each one used to
            // build the listing URL by hand, which is how three of them came to send a role that
            // cannot enter the listing straight into a refusal.
            'editrule.php' => ['page_gate::listing_url('],
            'deleterule.php' => ['page_gate::listing_url('],
            'duplicaterule.php' => ['page_gate::listing_url('],
            // The component delete pages: their destination is the COMPONENT listing, which has its
            // own pair, and they demand only their own delete capability.
            'deletecondition.php' => ["page_gate::component_listing_url(\n    'condition'"],
            'deleteaction.php' => ["page_gate::component_listing_url(\n    'action'"],
        ];

        $missing = [];
        foreach ($expected as $file => $calls) {
            $content = file_get_contents($root . $file);
            foreach ($calls as $call) {
                if (strpos($content, $call) === false) {
                    $missing[] = "$file no longer calls $call)";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'A page stopped consulting the gate: its capability enforcement is gone and no effect '
            . 'test can see that from outside the page.'
        );
    }
}
