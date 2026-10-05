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

namespace local_coursedynamicrules\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * The declaration is only worth what the privacy registry actually shows of it.
 *
 * external_transfer_declaration_test.php proves the declaration matches the request. This file
 * proves the declaration is REACHABLE: core renders a component's declared fields in the plugin
 * privacy registry only when it counts the component as compliant, and it counts a component
 * compliant only when a data provider accompanies the metadata one
 * (privacy/classes/manager.php:143-160, admin/tool/dataprivacy/classes/metadata_registry.php:58-73).
 *
 * A component with a metadata provider alone therefore declares into a void: every field is
 * correct, the registry lists the component as non-compliant, and shows none of them. That is the
 * screen a privacy audit reads, so a correct declaration nobody can see closes no finding.
 *
 * These two assertions are what SATG-PRIV-001 asks for in effect, stated as behaviour of core
 * rather than as a list of interfaces to implement: whichever way the provider is completed, the
 * registry has to end up showing the transfer.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @covers     \local_coursedynamicrules\privacy\provider
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registry_compliance_test extends \advanced_testcase {
    /** @var string The component under test. */
    private const COMPONENT = 'local_coursedynamicrules';

    /**
     * Core must count this component as compliant, or nothing it declares is rendered anywhere.
     *
     * @return void
     */
    public function test_core_counts_the_component_as_compliant(): void {
        $this->resetAfterTest(true);

        $this->assertTrue(
            (new \core_privacy\manager())->component_is_compliant(self::COMPONENT),
            'Core does not count this component as compliant, so the plugin privacy registry renders '
                . 'none of its declared fields. A metadata provider alone is not enough: '
                . 'privacy/classes/manager.php:143-160 also requires a data provider.'
        );
    }

    /**
     * The registry entry must carry the external transfer, not just a "non-compliant" mark.
     *
     * Asserted against the registry rather than against get_metadata() on purpose: the declaration
     * being right is already covered elsewhere, and what this finding is about is whether anyone
     * can see it.
     *
     * @return void
     */
    public function test_the_privacy_registry_shows_the_external_ai_transfer(): void {
        $this->resetAfterTest(true);

        $entry = $this->registry_entry();

        $this->assertTrue(
            $entry['compliant'] ?? false,
            'The registry lists the component as non-compliant, and so renders no metadata for it.'
        );

        $names = array_column($entry['metadata'] ?? [], 'name');
        $this->assertContains(
            'datacurso_ai',
            $names,
            'The registry entry does not carry the external AI transfer. Declared fields are only '
                . 'formatted into the entry for a compliant component.'
        );
    }

    /**
     * This component's entry in the rendered privacy registry.
     *
     * @return array The registry entry, or an empty array when the component is absent.
     */
    private function registry_entry(): array {
        foreach ((new \tool_dataprivacy\metadata_registry())->get_registry_metadata() as $branch) {
            foreach ($branch['plugins'] ?? [] as $plugin) {
                if (($plugin['raw_component'] ?? '') === self::COMPONENT) {
                    return $plugin;
                }
            }
        }

        $this->fail('The component does not appear in the privacy registry at all.');
    }
}
