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
        if (!class_exists(\aiprovider_datacurso\httpclient\plugin_identity::class)) {
            $this->markTestSkipped('The installed aiprovider_datacurso does not send plugin headers.');
        }
        // The real client reads the license and asks the shop for its region: give it both, offline.
        set_config('licensekey', 'test-license-key', 'aiprovider_datacurso');
        \curl::mock_response('{"is_for_eu": false}');
        $manager = \core_plugin_manager::instance();
        $plugin = $manager->get_plugin_info('local_coursedynamicrules');
        $release = $plugin->release;
        $this->assertNotEmpty($release);

        $client = $this->build_the_client_of_the_action();
        $headers = $client->get_plugin_headers();

        $this->assertContains('X-Plugin-Name: local_coursedynamicrules', $headers);
        $this->assertContains('X-Plugin-Version: ' . $release, $headers);
    }

    /**
     * Build the client with the method the action uses, without running the action.
     *
     * @return \aiprovider_datacurso\httpclient\ai_course_api
     */
    private function build_the_client_of_the_action() {
        $method = new \ReflectionMethod(createaiactivity_action::class, 'get_api_client');
        $class = new \ReflectionClass(createaiactivity_action::class);
        $action = $class->newInstanceWithoutConstructor();

        return $method->invoke($action, null, null);
    }
}
