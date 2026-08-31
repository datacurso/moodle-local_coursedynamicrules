<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin upgrade steps are defined here.
 *
 * @package     local_coursedynamicrules
 * @category    upgrade
 * @copyright   2024 Industria Elearning <info@industriaelearning.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_coursedynamicrules upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_coursedynamicrules_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // For further information please read {@link https://docs.moodle.org/dev/Upgrade_API}.
    //
    // You will also have to create the db/install.xml file by using the XMLDB Editor.
    // Documentation for the XMLDB Editor can be found at {@link https://docs.moodle.org/dev/XMLDB_editor}.
    if ($oldversion < 2024102000) {
        // Define table cdr_rule to be created.
        $table = new xmldb_table('cdr_rule');

        // Adding fields to table cdr_rule.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '16', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '16', null, null, null, null);

        // Adding keys to table cdr_rule.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        // Conditionally launch create table for cdr_rule.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table cdr_condition to be created.
        $table = new xmldb_table('cdr_condition');

        // Adding fields to table cdr_condition.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('conditiontype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('eventname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('params', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table cdr_condition.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'cdr_rule', ['id']);

        // Conditionally launch create table for cdr_condition.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table cdr_action to be created.
        $table = new xmldb_table('cdr_action');

        // Adding fields to table cdr_action.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('actiontype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('params', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table cdr_action.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'cdr_rule', ['id']);

        // Conditionally launch create table for cdr_action.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Coursedynamicrules savepoint reached.
        upgrade_plugin_savepoint(true, 2024102000, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2025010600) {
        // Define field lastexecutiontime to be added to cdr_rule.
        $table = new xmldb_table('cdr_rule');
        $field = new xmldb_field('lastexecutiontime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'active');

        // Conditionally launch add field lastexecutiontime.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Changing precision of field timecreated on table cdr_rule to (10).
        $table = new xmldb_table('cdr_rule');
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'lastexecutiontime');

        // Launch change of precision for field timecreated.
        $dbman->change_field_precision($table, $field);

        // Changing precision of field timemodified on table cdr_rule to (10).
        $table = new xmldb_table('cdr_rule');
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');

        // Launch change of precision for field timemodified.
        $dbman->change_field_precision($table, $field);

        // Coursedynamicrules savepoint reached.
        upgrade_plugin_savepoint(true, 2025010600, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2025022601) {
        // Define field lastexecutiontime to be added to cdr_condition.
        $table = new xmldb_table('cdr_condition');
        $field = new xmldb_field('lastexecutiontime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'params');

        // Conditionally launch add field lastexecutiontime.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field lastexecutiontime to be added to cdr_action.
        $table = new xmldb_table('cdr_action');
        $field = new xmldb_field('lastexecutiontime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'params');

        // Conditionally launch add field lastexecutiontime.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Coursedynamicrules savepoint reached.
        upgrade_plugin_savepoint(true, 2025022601, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2025111802) {
        // Define table cdr_rule to be renamed to local_coursedynamicrules_rule.
        $table = new xmldb_table('cdr_rule');

        // Launch rename table for cdr_rule.
        $dbman->rename_table($table, 'local_coursedynamicrules_rule');

        // Define table cdr_condition to be renamed to local_coursedynamicrules_condition.
        $table = new xmldb_table('cdr_condition');

        // Launch rename table for cdr_condition.
        $dbman->rename_table($table, 'local_coursedynamicrules_condition');

        // Define table cdr_action to be renamed to local_coursedynamicrules_action.
        $table = new xmldb_table('cdr_action');

        // Launch rename table for cdr_action.
        $dbman->rename_table($table, 'local_coursedynamicrules_action');

        // Coursedynamicrules savepoint reached.
        upgrade_plugin_savepoint(true, 2025111802, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2026042300) {
        local_coursedynamicrules_upgrade_migrate_sendnotification_roles();
        upgrade_plugin_savepoint(true, 2026042300, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2026083001) {
        // The editing teacher can delete what they can create - see the function's docblock for
        // why db/access.php alone cannot deliver this to an existing site.
        local_coursedynamicrules_upgrade_grant_component_deletion();

        upgrade_plugin_savepoint(true, 2026083001, 'local', 'coursedynamicrules');
    }

    if ($oldversion < 2026083002) {
        // A rule may be edited only until its FIRST activation, never again. The column records
        // that moment: stamped once, never cleared - pausing and reactivating leave it alone.
        $table = new xmldb_table('local_coursedynamicrules_rule');
        $field = new xmldb_field(
            'timeactivated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'lastexecutiontime'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Product decision 2026-08-31: rules that are ACTIVE at upgrade time were activated at some
        // point, so they are stamped - locked from day one, still pausable forever - or the
        // requirement would be void for the whole installed base. Inactive rules are grandfathered
        // unlocked: their activation history is unknowable, and stamping them would lock rules
        // that may never have run. The stamp borrows the best timestamp the row can offer.
        $DB->execute(
            "
            UPDATE {local_coursedynamicrules_rule}
               SET timeactivated = COALESCE(timemodified, timecreated, :now)
             WHERE active = 1 AND timeactivated IS NULL",
            ['now' => time()]
        );

        upgrade_plugin_savepoint(true, 2026083002, 'local', 'coursedynamicrules');
    }

    return true;
}

/**
 * Migrate sendnotification roles params to primary/copy keys.
 *
 * @return void
 */
function local_coursedynamicrules_upgrade_migrate_sendnotification_roles(): void {
    global $DB;

    $studentroles = $DB->get_records('role', ['shortname' => 'student'], 'id ASC', 'id');
    $studentroleids = array_map('intval', array_keys($studentroles));

    $actions = $DB->get_records('local_coursedynamicrules_action', ['actiontype' => 'sendnotification']);
    foreach ($actions as $action) {
        $params = json_decode($action->params, true);
        if (!is_array($params)) {
            continue;
        }

        if (isset($params['primaryroleids']) || isset($params['copyroleids'])) {
            continue;
        }

        $primaryroleids = [];
        $copyroleids = [];

        if (isset($params['observedroleids']) || isset($params['observerroleids'])) {
            $primaryroleids = array_values(array_map('intval', $params['observedroleids'] ?? []));
            $copyroleids = array_values(array_map('intval', $params['observerroleids'] ?? []));
        } else {
            $legacyroleids = array_values(array_map('intval', $params['roleids'] ?? []));
            $legacyroleids = array_values(array_unique(array_filter($legacyroleids)));

            if (count($legacyroleids) === 1) {
                $primaryroleids = $legacyroleids;
                $copyroleids = [];
            } else if (count($legacyroleids) > 1) {
                $studentmatches = array_values(array_intersect($legacyroleids, $studentroleids));
                if (!empty($studentmatches)) {
                    $primaryroleids = [$studentmatches[0]];
                } else {
                    $primaryroleids = [$legacyroleids[0]];
                }
                $copyroleids = array_values(array_diff($legacyroleids, $primaryroleids));
            }
        }

        if (empty($primaryroleids) && empty($copyroleids)) {
            continue;
        }

        $params['primaryroleids'] = $primaryroleids;
        $params['copyroleids'] = $copyroleids;
        unset($params['observedroleids']);
        unset($params['observerroleids']);
        unset($params['roleids']);

        $DB->set_field('local_coursedynamicrules_action', 'params', json_encode($params), ['id' => $action->id]);
    }
}

/**
 * Grant the three component-deletion capabilities to every editing-teacher-archetype role.
 *
 * Extracted from the savepoint so the upgrade path itself is testable: core's
 * update_capabilities() applies archetype defaults ONLY to capabilities it is seeing for the
 * first time (it iterates $newcaps - lib/accesslib.php), and these three shipped releases ago as
 * manager-only. Editing db/access.php therefore changes nothing on any site that already has the
 * plugin: this function is what carries the product decision - whoever may build rules may also
 * unbuild them - to every existing site.
 *
 * assign_capability() is called WITHOUT overwrite: a site that explicitly prohibited or allowed
 * any of these on some role made a decision, and an upgrade must not undo it. Only roles with no
 * explicit entry receive the new default.
 *
 * @return void
 */
function local_coursedynamicrules_upgrade_grant_component_deletion(): void {
    $systemcontext = context_system::instance();

    foreach (get_archetype_roles('editingteacher') as $role) {
        foreach (['deleterule', 'deletecondition', 'deleteaction'] as $capability) {
            assign_capability(
                'local/coursedynamicrules:' . $capability,
                CAP_ALLOW,
                $role->id,
                $systemcontext->id
            );
        }
    }

    $systemcontext->mark_dirty();
}
