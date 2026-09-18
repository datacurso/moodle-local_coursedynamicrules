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

/**
 * Administration settings for the plugin.
 *
 * One setting, and it is the operational one: the number of enrolled users a scheduled task holds
 * in memory at a time while it walks a course. It existed before this page did, readable only
 * through set_config() or $CFG->forced_plugin_settings, which meant the threshold that decides how
 * much work a run costs could not be seen, let alone tuned, by the administrator responsible for
 * the site's cron.
 *
 * @package     local_coursedynamicrules
 * @copyright   2026 Industria Elearning <info@industriaelearning.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_coursedynamicrules',
        new lang_string('pluginname', 'local_coursedynamicrules')
    );

    $settings->add(new admin_setting_configtext(
        'local_coursedynamicrules/taskbatchsize',
        new lang_string('taskbatchsize', 'local_coursedynamicrules'),
        new lang_string('taskbatchsize_desc', 'local_coursedynamicrules'),
        \local_coursedynamicrules\helper\task_batch::DEFAULT_SIZE,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
