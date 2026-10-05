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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

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
     * MDL-UNIT-020: an installed action-dependency plugin without an enableurl validates cleanly.
     *
     * An installed plugin declared without the optional enableurl key must validate cleanly,
     * without raising an undefined array key warning.
     *
     * @covers ::add_notifications_to_form
     */
    public function test_installed_plugin_without_enableurl_adds_no_notification(): void {
        $mform = new \MoodleQuickForm('testform', 'post', '');

        // The plugin under test is always installed, so this fixture needs no companion plugin.
        $missing = form_plugin_validator::add_notifications_to_form($mform, [
            [
                'pluginname' => 'local_coursedynamicrules',
                'downloadurl' => 'https://moodle.org/plugins/local_coursedynamicrules',
            ],
        ]);

        $this->assertSame([], $missing);
    }

    /**
     * MDL-UNIT-020: a missing action-dependency plugin adds a notification and is reported.
     *
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

    /**
     * The same verdict must be available without a form, so a listing can ask it before offering a
     * control that leads to a form the plugin's absence has already emptied.
     *
     * @covers ::missing_plugins
     */
    public function test_missing_plugins_reports_without_needing_a_form(): void {
        $this->assertSame(
            ['local_definitelynotinstalled'],
            form_plugin_validator::missing_plugins([
                [
                    'pluginname' => 'local_coursedynamicrules',
                    'downloadurl' => 'https://moodle.org/plugins/local_coursedynamicrules',
                ],
                [
                    'pluginname' => 'local_definitelynotinstalled',
                    'downloadurl' => 'https://moodle.org/plugins/local_definitelynotinstalled',
                ],
            ])
        );
    }

    /**
     * And it agrees with the form-facing method, which is the whole point: a control hidden because a
     * plugin is missing and a form that explains why must not disagree about whether it is missing.
     *
     * @covers ::missing_plugins
     */
    public function test_the_query_and_the_notifier_agree(): void {
        $plugins = [
            [
                'pluginname' => 'local_definitelynotinstalled',
                'downloadurl' => 'https://moodle.org/plugins/local_definitelynotinstalled',
            ],
        ];

        $mform = new \MoodleQuickForm('testform', 'post', '');

        $this->assertSame(
            form_plugin_validator::add_notifications_to_form($mform, $plugins),
            form_plugin_validator::missing_plugins($plugins)
        );
    }
}
