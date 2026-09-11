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
 * Backup plugin for local_coursedynamicrules.
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Defines course-level backup structure for the coursedynamicrules local plugin.
 *
 * @package   local_coursedynamicrules
 */
class backup_local_coursedynamicrules_plugin extends backup_local_plugin {
    /**
     * Define plugin structure
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element(null);

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Named with the plugin prefix, not just 'rules': Moodle's backup optigroup requires
        // nested element names to be unique across ALL local plugins' course structures, and
        // local_notificationsagent (third-party) also registers a bare 'rules' element.
        $rules = new backup_nested_element('coursedynamicrules_rules');
        $plugin->add_child($pluginwrapper);
        $pluginwrapper->add_child($rules);

        // Every nested element name below is also plugin-prefixed: Moodle's backup optigroup
        // checks name uniqueness across the WHOLE shared tree of all local plugins, at any depth,
        // not just among direct siblings - so generic names like 'rule' or 'action' collide with
        // another plugin's equally generic names even when nested under a uniquely-named parent.
        $rule = new backup_nested_element('coursedynamicrules_rule', ['id'], [
            'courseid',
            'name',
            'description',
            'active',
            'lastexecutiontime',
            'timeactivated',
            'timecreated',
            'timemodified',
        ]);
        $rules->add_child($rule);

        $conditions = new backup_nested_element('coursedynamicrules_conditions');
        $rule->add_child($conditions);
        $condition = new backup_nested_element('coursedynamicrules_condition', ['id'], [
            'ruleid',
            'name',
            'conditiontype',
            'eventname',
            'params',
            'lastexecutiontime',
        ]);
        $conditions->add_child($condition);

        $actions = new backup_nested_element('coursedynamicrules_actions');
        $rule->add_child($actions);
        $action = new backup_nested_element('coursedynamicrules_action', ['id'], [
            'ruleid',
            'name',
            'actiontype',
            'params',
            'lastexecutiontime',
        ]);
        $actions->add_child($action);

        // Notification recipients are role ids buried inside the action's `params` JSON, where
        // annotate_ids() cannot reach them. Emitting them as their own element does two things a
        // verbatim copy of the JSON cannot: it annotates the roles so core builds a role mapping for
        // the restore, and it records each role's shortname, which IS stable across sites. Without
        // this a restore elsewhere keeps a raw id that either no longer exists or - worse - now
        // belongs to a different role, silently addressing notifications carrying learner data to
        // the wrong people.
        $notificationroles = new backup_nested_element('coursedynamicrules_notificationroles');
        $pluginwrapper->add_child($notificationroles);
        $notificationrole = new backup_nested_element('coursedynamicrules_notificationrole', ['id'], [
            'roleid',
            'shortname',
        ]);
        $notificationroles->add_child($notificationrole);

        // Sources.
        $rule->set_source_table('local_coursedynamicrules_rule', ['courseid' => backup::VAR_COURSEID]);
        $condition->set_source_table('local_coursedynamicrules_condition', ['ruleid' => backup::VAR_PARENTID]);
        $action->set_source_table('local_coursedynamicrules_action', ['ruleid' => backup::VAR_PARENTID]);
        $notificationrole->set_source_array($this->collect_notification_roles());

        // Annotations.
        $notificationrole->annotate_ids('role', 'roleid');

        return $plugin;
    }

    /**
     * Every role referenced by a notification action of the course being backed up.
     *
     * Read straight from the actions' `params` JSON, because that is the only place these role
     * references exist. Roles missing from the site are skipped rather than exported with an empty
     * shortname: an id nobody can resolve is exactly what this element exists to avoid.
     *
     * @return array List of ['id' => int, 'roleid' => int, 'shortname' => string].
     */
    protected function collect_notification_roles(): array {
        global $DB;

        $sql = 'SELECT a.id, a.params
                  FROM {local_coursedynamicrules_action} a
                  JOIN {local_coursedynamicrules_rule} r ON r.id = a.ruleid
                 WHERE r.courseid = :courseid';
        $actions = $DB->get_records_sql($sql, ['courseid' => $this->task->get_courseid()]);

        $roleids = [];
        foreach ($actions as $action) {
            $params = json_decode((string) $action->params);
            if (!is_object($params)) {
                continue;
            }
            foreach (\local_coursedynamicrules\action\sendnotification\sendnotification_action::ROLE_PARAM_KEYS as $key) {
                foreach ((array) ($params->{$key} ?? []) as $roleid) {
                    $roleid = (int) $roleid;
                    if ($roleid > 0) {
                        $roleids[$roleid] = $roleid;
                    }
                }
            }
        }

        if (empty($roleids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $roles = $DB->get_records_select('role', "id $insql", $inparams, 'id', 'id, shortname');

        $rows = [];
        $sequence = 0;
        foreach ($roles as $role) {
            $rows[] = [
                'id' => ++$sequence,
                'roleid' => (int) $role->id,
                'shortname' => (string) $role->shortname,
            ];
        }

        return $rows;
    }
}
