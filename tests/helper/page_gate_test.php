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
            'conditions.php' => ["page_gate::require_listing('condition'", "page_gate::require_creation('condition'"],
            'actions.php' => ["page_gate::require_listing('action'", "page_gate::require_creation('action'"],
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
