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
 * Class form_plugin_validator
 *
 * Utility class for validating the installation and enablement status of Moodle plugins in forms.
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_plugin_validator {
    /**
     * Adds notifications to the form for any missing or disabled plugins.
     *
     * This method iterates through a given list of required plugins and verifies
     * each one using {@see self::add_notification_to_form()}.
     * If a plugin is missing or disabled, a Moodle notification is added to the form,
     * and the plugin name is recorded in the return array.
     *
     * @param \MoodleQuickForm $mform The Moodle form object to which notifications will be added.
     * @param array $requiredplugins List of plugin definitions, each containing:
     *                               - 'pluginname' (string): The plugin component name.
     *                               - 'langprefix' (string): The language string prefix used for messages.
     *                               - 'enableurl' (optional, moodle_url): The management page URL for the plugin.
     * @return array List of plugins that are missing or disabled (empty array if all are OK).
     */
    public static function add_notifications_to_form(\MoodleQuickForm $mform, array $requiredplugins): array {
        $missingplugins = [];

        foreach ($requiredplugins as $plugin) {
            if (self::add_notification_to_form($plugin, $mform)) {
                $missingplugins[] = $plugin['pluginname'];
            }
        }

        return $missingplugins;
    }

    /**
     * Checks whether a plugin is missing or disabled and adds a notification if necessary.
     *
     * This method retrieves the plugin information using Moodle's plugin manager.
     * If the plugin is not installed or is currently disabled, it displays a Moodle
     * notification message on the form and returns true.
     *
     * @param array $plugin The plugin definition array, containing:
     *                      - 'pluginname' (string): The plugin’s component name.
     *                      - 'langprefix' (string): The prefix used for language strings.
     *                      - 'enableurl' (optional, moodle_url): The management or enablement page URL.
     * @param \MoodleQuickForm $mform The form object where the notification will be displayed.
     * @return bool True if the plugin is missing or disabled, false if it is installed and enabled.
     */
    public static function add_notification_to_form(array $plugin, \MoodleQuickForm $mform): bool {
        global $OUTPUT;
        $pluginname = $plugin['pluginname'];
        $enableurl = $plugin['enableurl'] ?? null;
        // Get plugin info object from the plugin manager.
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info($pluginname);

        $langdata = [
            'downloadurl' => $plugin['downloadurl'],
            'pluginname' => $pluginname,
        ];

        // Plugin is not installed.
        if (empty($plugininfo)) {
            $message = get_string("plugin_missing", 'local_coursedynamicrules', $langdata);
            $notification = $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
            $mform->addElement('html', $notification);
            return true;
        }

        // If the plugin type supports enabling/disabling, check if it is enabled.
        if ($plugininfo->is_enabled() == false && $enableurl) {
            $langdata['enableurl'] = $enableurl->out();
            $langdata['visiblename'] = $plugininfo->displayname;
            $message = get_string("plugin_disabled", 'local_coursedynamicrules', $langdata);
            $notification = $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
            $mform->addElement('html', $notification);
            return true;
        }
        return false;
    }
}
