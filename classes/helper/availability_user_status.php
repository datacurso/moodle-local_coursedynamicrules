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
 * Reports whether the per-user availability restriction this plugin depends on is usable.
 *
 * The "enable activity" action hides an activity from everybody and then grants it student by
 * student through an `availability_user` restriction. Disabling that availability plugin site-wide
 * does not merely stop new grants: Moodle ignores restrictions whose plugin is gone, so every
 * activity the rules were gating becomes visible to EVERY student at once, silently. Management
 * pages therefore have to say so.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability_user_status {
    /** @var string The availability subplugin the "enable activity" action writes its restrictions with. */
    private const PLUGIN = 'user';

    /**
     * Whether the per-user availability restriction is enabled site-wide.
     *
     * @return bool True when the restriction is available and the rules can gate activities.
     */
    public static function is_enabled(): bool {
        if (!\core_component::get_plugin_directory('availability', self::PLUGIN)) {
            return false;
        }

        return array_key_exists(self::PLUGIN, \core\plugininfo\availability::get_enabled_plugins());
    }
}
