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

namespace local_coursedynamicrules\action\createaiactivity;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests that the AI activity action tells the course service which plugin is calling.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursedynamicrules\action\createaiactivity\createaiactivity_action
 */
final class createaiactivity_plugin_identity_test extends \advanced_testcase {
    /**
     * The client the action builds identifies as this plugin and reports its release.
     */
    public function test_the_client_identifies_as_this_plugin_with_its_release(): void {
        $this->resetAfterTest();
        // The real client reads the license and asks the shop for its region: give it both, offline.
        set_config('licensekey', 'test-license-key', 'aiprovider_datacurso');
        \curl::mock_response(json_encode(['is_for_eu' => false]));
        $release = \core_plugin_manager::instance()->get_plugin_info('local_coursedynamicrules')->release;
        $this->assertNotEmpty($release);

        $method = new \ReflectionMethod(createaiactivity_action::class, 'get_api_client');
        $action = (new \ReflectionClass(createaiactivity_action::class))->newInstanceWithoutConstructor();
        $headers = $method->invoke($action, null, null)->get_plugin_headers();

        $this->assertContains('X-Plugin-Name: local_coursedynamicrules', $headers);
        $this->assertContains('X-Plugin-Version: ' . $release, $headers);
    }
}
