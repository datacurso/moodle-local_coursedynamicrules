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
 * Tests for the form plugin validator helper.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @coversDefaultClass \local_coursedynamicrules\helper\form_plugin_validator
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class form_plugin_validator_test extends \advanced_testcase {
    /**
     * An installed plugin declared without the optional enableurl key must validate cleanly,
     * without raising an undefined array key warning.
     *
     * @covers ::add_notifications_to_form
     */
    public function test_installed_plugin_without_enableurl_adds_no_notification(): void {
        $mform = new \MoodleQuickForm('testform', 'post', '');

        $missing = form_plugin_validator::add_notifications_to_form($mform, [
            [
                'pluginname' => 'local_coursegen',
                'downloadurl' => 'https://moodle.org/plugins/local_coursegen',
            ],
        ]);

        $this->assertSame([], $missing);
    }

    /**
     * A plugin that is not installed must add a notification and be reported as missing.
     *
     * @covers ::add_notifications_to_form
     */
    public function test_missing_plugin_is_reported(): void {
        $mform = new \MoodleQuickForm('testform', 'post', '');

        $missing = form_plugin_validator::add_notifications_to_form($mform, [
            [
                'pluginname' => 'local_definitelynotinstalled',
                'downloadurl' => 'https://moodle.org/plugins/local_definitelynotinstalled',
            ],
        ]);

        $this->assertSame(['local_definitelynotinstalled'], $missing);
    }
}
