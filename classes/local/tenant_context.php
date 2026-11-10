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

use aiprovider_datacurso\local\plugin_settings;

/**
 * Asks the Datacurso AI provider whether this plugin is enabled for the Moodle Workplace tenant of a user.
 *
 * The provider keeps the state of this plugin per tenant, and the tenant that decides is always the
 * tenant of the USER - never the tenant that owns the course. A tenant that disabled the plugin means
 * that no user of that tenant uses it, in any course: their own tenant's courses, another tenant's
 * courses and shared courses alike, and nothing is done for them.
 *
 * - Interactive requests (pages, navigation, dynamic forms, AJAX): the tenant of the current user,
 *   see is_enabled_for_current_user() and require_enabled_for_current_user().
 * - Background work (tasks, observers): the tenant of the user the rule acts for - the enrolled
 *   user, the event's related user, the user a queued task was created for - never the cron user.
 *   See is_enabled_for_user() for a single user, and an instance of this class to filter a stream
 *   of users with one provider lookup per tenant.
 *
 * On a site without tool_tenant every user belongs to the implicit tenant 0, so the provider's site
 * default applies. The tool_tenant classes are only referenced after is_tenancy_available().
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_context {
    /** @var string Component whose state the provider keeps. */
    public const COMPONENT = 'local_coursedynamicrules';

    /** @var int Tenant id used when there is no tenancy, or no user to resolve: the site default. */
    public const NO_TENANT = 0;

    /** @var array<int, bool> State of the plugin per tenant id, looked up once per instance. */
    private array $enabledbytenant = [];

    /** @var int Users filter_users() held back since the last take_skipped_count(). */
    private int $skipped = 0;

    /**
     * Whether this site provides tenancy through tool_tenant.
     *
     * @return bool
     */
    public static function is_tenancy_available(): bool {
        return class_exists('\tool_tenant\tenancy');
    }

    /**
     * Tenant of a user.
     *
     * @param int $userid User id.
     * @return int Tenant id, or 0 when tenancy is not available or there is no user.
     */
    public static function get_tenant_id_for_user(int $userid): int {
        // Guarded here: tool_tenant reads a user id of 0 as "the current user".
        if (!self::is_tenancy_available() || $userid <= 0) {
            return self::NO_TENANT;
        }

        return (int) \tool_tenant\tenancy::get_tenant_id($userid);
    }

    /**
     * Whether the plugin is enabled in the provider for the tenant of a user.
     *
     * For background work: pass the user the rule acts for, never the cron user.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function is_enabled_for_user(int $userid): bool {
        return plugin_settings::is_enabled(self::COMPONENT, self::get_tenant_id_for_user($userid));
    }

    /**
     * Whether the plugin is enabled in the provider for the tenant of the current user.
     *
     * @return bool
     */
    public static function is_enabled_for_current_user(): bool {
        return plugin_settings::is_enabled(self::COMPONENT);
    }

    /**
     * Stop an interactive request when the plugin is disabled for the tenant of the current user.
     *
     * @return void
     * @throws \moodle_exception plugindisabled or plugindisabled_admin when the plugin is disabled.
     */
    public static function require_enabled_for_current_user(): void {
        plugin_settings::require_enabled(self::COMPONENT);
    }

    /**
     * Whether the plugin is enabled for the tenant of a user, asking the provider once per tenant.
     *
     * Meant for one background run (a scheduled task walking many users): the state is cached on this
     * instance, so a change saved in the provider during the run applies from the next run.
     *
     * @param int $userid User id.
     * @return bool
     */
    public function is_enabled_for_user_cached(int $userid): bool {
        $tenantid = self::get_tenant_id_for_user($userid);
        $this->enabledbytenant[$tenantid] ??= plugin_settings::is_enabled(self::COMPONENT, $tenantid);
        return $this->enabledbytenant[$tenantid];
    }

    /**
     * Pass through the users whose tenant has the plugin enabled, without collecting them.
     *
     * Lazy, like the stream it wraps: a user is looked at only when the consumer asks for the next one,
     * so a paged walk keeps its memory bound. The users held back are counted, see take_skipped_count().
     *
     * @param iterable $users stdClass objects carrying at least "id".
     * @return \Generator<int, \stdClass>
     */
    public function filter_users(iterable $users): \Generator {
        foreach ($users as $user) {
            if ($this->is_enabled_for_user_cached((int) $user->id)) {
                yield $user;
            } else {
                $this->skipped++;
            }
        }
    }

    /**
     * How many users filter_users() held back since the last call, and reset the count.
     *
     * @return int
     */
    public function take_skipped_count(): int {
        $skipped = $this->skipped;
        $this->skipped = 0;
        return $skipped;
    }

    /**
     * Write to the task log how many users of a rule filter_users() held back, and reset the count.
     *
     * Silent when nobody was held back.
     *
     * @param int $courseid Course of the rule.
     * @param int $ruleid Rule id.
     * @return void
     */
    public function trace_skipped_users(int $courseid, int $ruleid): void {
        $skipped = $this->take_skipped_count();
        if ($skipped > 0) {
            mtrace("local_coursedynamicrules: course {$courseid}, rule {$ruleid}, {$skipped} users: "
                . get_string('task_skipped_plugindisabled', 'local_coursedynamicrules'));
        }
    }
}
