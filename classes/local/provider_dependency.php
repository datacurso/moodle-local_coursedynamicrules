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

namespace local_coursedynamicrules\local;

/**
 * The one place that talks to the Datacurso AI provider's plugin state.
 *
 * The provider class `\aiprovider_datacurso\local\plugin_settings` exists on the provider's 5.x
 * line only from 2.2.0 (version 2026110900). A site that updated this plugin while keeping an older
 * provider must keep working: without the class the plugin counts as not enabled, so the
 * navigation entry, the observers and the tasks skip, and the pages refuse with a notice telling
 * an administrator to update the provider. No other plugin code names the provider class.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_dependency {
    /** @var string This plugin's component, the key of its state in the provider. */
    private const COMPONENT = 'local_coursedynamicrules';

    /** @var string Provider class introduced in aiprovider_datacurso 2.2.0. */
    private const SETTINGS_CLASS = '\\aiprovider_datacurso\\local\\plugin_settings';

    /** @var bool|null Forced availability for unit tests; null detects the real provider. */
    private static ?bool $availableoverride = null;

    /**
     * Whether the installed provider has the plugin state API (provider 2.2.0 or later).
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (self::$availableoverride !== null) {
            return self::$availableoverride;
        }
        return class_exists(self::SETTINGS_CLASS)
            && method_exists(self::SETTINGS_CLASS, 'is_enabled')
            && method_exists(self::SETTINGS_CLASS, 'require_enabled');
    }

    /**
     * Whether the plugin is enabled in the provider; false when the provider is too old to say.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!self::is_available()) {
            return false;
        }
        $settings = self::SETTINGS_CLASS;
        return $settings::is_enabled(self::COMPONENT);
    }

    /**
     * Why a task must not run, as the line it prints, or null when it may run.
     *
     * @return string|null The skip line for an older provider or a disabled plugin; null when enabled.
     */
    public static function task_skip_reason(): ?string {
        if (!self::is_available()) {
            return get_string('task_skipped_providerupdaterequired', self::COMPONENT);
        }
        if (!self::is_enabled()) {
            return get_string('task_skipped_plugindisabled', self::COMPONENT);
        }
        return null;
    }

    /**
     * Stop the request unless the plugin is enabled in an up-to-date provider.
     *
     * With an older provider a site administrator is told to update it, with a link to the plugins
     * overview; anyone else gets a generic notice. Otherwise the provider decides, as before.
     *
     * @return void
     * @throws \moodle_exception providerupdaterequired_admin or providerupdaterequired with an older
     *     provider; plugindisabled_admin or plugindisabled (aiprovider_datacurso) when disabled.
     */
    public static function require_enabled(): void {
        if (self::is_available()) {
            $settings = self::SETTINGS_CLASS;
            $settings::require_enabled(self::COMPONENT);
            return;
        }
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $url = new \moodle_url('/admin/plugins.php');
            throw new \moodle_exception('providerupdaterequired_admin', 'local_coursedynamicrules', $url);
        }
        throw new \moodle_exception('providerupdaterequired', 'local_coursedynamicrules');
    }

    /**
     * Force the provider availability in unit tests, to simulate a provider older than 2.2.0.
     *
     * @param bool|null $available Forced value, or null to detect the real provider again.
     * @return void
     * @throws \coding_exception Outside unit tests.
     */
    public static function set_available_for_testing(?bool $available): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('provider_dependency::set_available_for_testing() is only for unit tests');
        }
        self::$availableoverride = $available;
    }
}
