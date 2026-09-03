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
 * Restore plugin for local_coursedynamicrules.
 *
 * @package    local_coursedynamicrules
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Defines course-level restore structure and processing for coursedynamicrules local plugin.
 *
 * @package   local_coursedynamicrules
 */
class restore_local_coursedynamicrules_plugin extends restore_local_plugin {
    /** @var array<int, string> Source role id => source role shortname, from the backup file. */
    protected $notificationroleshortnames = [];

    /**
     * Define plugin structure
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element(
                'local_coursedynamicrules_rule',
                $this->get_pathfor('/rules/rule')
            ),
            new restore_path_element(
                'local_coursedynamicrules_condition',
                $this->get_pathfor('/rules/rule/conditions/condition')
            ),
            new restore_path_element(
                'local_coursedynamicrules_action',
                $this->get_pathfor('/rules/rule/actions/action')
            ),
            new restore_path_element(
                'local_coursedynamicrules_aigrade',
                $this->get_pathfor('/aigrades/aigrade')
            ),
            new restore_path_element(
                'local_coursedynamicrules_notificationrole',
                $this->get_pathfor('/notificationroles/notificationrole')
            ),
        ];
    }

    /**
     * Record a notification role reference exported by the backup.
     *
     * Only remembers what the source site called the role; resolving it against this site happens
     * later, in the after-restore pass, once core's own role mapping is available to be preferred.
     *
     * @param array $data
     *
     * @return void
     */
    public function process_local_coursedynamicrules_notificationrole($data) {
        $data = (object) $data;

        $roleid = (int) ($data->roleid ?? 0);
        $shortname = (string) ($data->shortname ?? '');

        if ($roleid > 0 && $shortname !== '') {
            $this->notificationroleshortnames[$roleid] = $shortname;
        }
    }

    /**
     * Process rule element
     *
     * @param array $data
     *
     * @return void
     */
    public function process_local_coursedynamicrules_rule($data) {
        global $DB;

        $data = (object)$data;

        $record = new \stdClass();
        $record->courseid = $this->task->get_courseid();
        $record->name = $data->name ?? null;
        $record->description = $data->description ?? null;
        $record->active = $data->active ?? 0;
        $record->lastexecutiontime = $data->lastexecutiontime ?? null;
        $record->timecreated = $data->timecreated ?? time();
        $record->timemodified = $data->timemodified ?? time();

        // The activation lock travels with the rule, or restore becomes its back door: without
        // this, "edit a locked rule" is just "import the course and edit the copy". An archive
        // with no stamp (made before the lock existed) but active gets one by the same axiom the
        // 2026083002 upgrade applies to the installed base - active means it WAS activated.
        // Inactive unstamped rules restore unlocked, same grandfathering as the upgrade.
        $record->timeactivated = (int) ($data->timeactivated ?? 0) ?: null;
        if (!empty($record->active) && $record->timeactivated === null) {
            // Truthiness on purpose: zeros in the archive's time columns are skipped, never
            // copied - a stamp of literally 0 would fork the sealed predicate.
            $record->timeactivated = $record->timemodified ?: ($record->timecreated ?: time());
        }

        $newruleid = $DB->insert_record('local_coursedynamicrules_rule', $record);
        $this->set_mapping('local_coursedynamicrules_rule', $data->id, $newruleid, false);
    }

    /**
     * Process condition element
     *
     * @param array $data
     *
     * @return void
     */
    public function process_local_coursedynamicrules_condition($data) {
        global $DB;

        $data = (object)$data;

        $record = new \stdClass();
        $record->ruleid = $this->get_mappingid('local_coursedynamicrules_rule', $data->ruleid);
        $record->name = $data->name ?? null;
        $record->conditiontype = $data->conditiontype ?? null;
        $record->eventname = $data->eventname ?? null;
        $record->params = $this->remap_condition_params($data->params ?? '');
        $record->lastexecutiontime = $data->lastexecutiontime ?? null;

        $newconditionid = $DB->insert_record('local_coursedynamicrules_condition', $record);
        $this->set_mapping('local_coursedynamicrules_condition', $data->id, $newconditionid, false);
    }

    /**
     * Process action element
     *
     * @param array $data
     *
     * @return void
     */
    public function process_local_coursedynamicrules_action($data) {
        global $DB;

        $data = (object)$data;

        $record = new \stdClass();
        $record->ruleid = $this->get_mappingid('local_coursedynamicrules_rule', $data->ruleid);
        $record->name = $data->name ?? null;
        $record->actiontype = $data->actiontype ?? null;
        $record->params = $this->remap_action_params($data->params ?? '');
        $record->lastexecutiontime = $data->lastexecutiontime ?? null;

        $newactionid = $DB->insert_record('local_coursedynamicrules_action', $record);
        $this->set_mapping('local_coursedynamicrules_action', $data->id, $newactionid, false);
    }

    /**
     * Restore one row of the generated-reinforcement register.
     *
     * The register exists to stop a student being given the same reinforcement twice. Without this,
     * a restored course loses every marker and generates - and pays for - a second reinforcement for
     * every student who already had one.
     *
     * Module ids are deliberately stored unmapped here: activities are restored after this step, so
     * course_module mappings do not exist yet. They are resolved in after_restore_course().
     *
     * @param array $data
     *
     * @return void
     */
    public function process_local_coursedynamicrules_aigrade($data) {
        global $DB;

        $data = (object)$data;

        // Every id is mapped where a mapping exists and stored as 0 where it does not, and the row
        // is kept either way. That is deliberate: the row's job is to record that a generated grade
        // column exists in this course, and that is true whether or not the student, the rule or
        // the action came across with it.
        //
        // A restore without user data has no user mapping at all, so `userid` becomes 0 - the same
        // severed state a privacy erasure produces. Dropping the row instead, which is what this
        // used to do, left every user-free copy of a course with a live grade column and nobody
        // excluded from it: measured at 0% under Lowest grade for every student who ever enrolled
        // in the copy, permanently, because the enrolment sweep's course gate then answers no.
        //
        // A row with userid 0 spares nobody, which is correct: without a recipient the plugin
        // cannot know who the column was meant to count for.
        $record = new \stdClass();
        $record->courseid = $this->task->get_courseid();
        $record->ruleid = (int)($this->get_mappingid(
            'local_coursedynamicrules_rule', $data->ruleid ?? 0) ?: 0);
        $record->actionid = (int)($this->get_mappingid(
            'local_coursedynamicrules_action', $data->actionid ?? 0) ?: 0);
        $record->userid = (int)($this->get_mappingid('user', $data->userid ?? 0) ?: 0);
        $record->cmid = (int)($data->cmid ?? 0);
        // Through clean_mode() like every other write path: a hand-edited or truncated backup can
        // otherwise seed an arbitrary value into the column the sweep reads.
        $record->grademode = \local_coursedynamicrules\local\service\grade_isolation_service::clean_mode(
            $data->grademode ?? null);
        $record->timecreated = (int)($data->timecreated ?? time());

        $newid = $DB->insert_record(
            \local_coursedynamicrules\local\service\grade_register_service::TABLE,
            $record
        );
        $this->set_mapping('local_coursedynamicrules_aigrade', $data->id, $newid, false);
    }

    /**
     * Remap params for conditions.
     *
     * @param string $paramsjson
     * @return string
     */
    protected function remap_condition_params(string $paramsjson): string {
        $params = json_decode($paramsjson);
        if (!$params) {
            return $paramsjson;
        }

        if (isset($params->cmid)) {
            $mappedcmid = $this->get_mapped_cmid($params->cmid);
            // Keep original id if mapping is not available to avoid losing the condition definition.
            $params->cmid = $mappedcmid ?? $params->cmid;
        }

        if (!empty($params->gradeitemsconditions)) {
            $params->gradeitemsconditions = $this->remap_gradeitemsconditions($params->gradeitemsconditions);
        }

        return json_encode($params);
    }

    /**
     * Remap params for actions.
     *
     * @param string $paramsjson
     * @return string
     */
    protected function remap_action_params(string $paramsjson): string {
        $params = json_decode($paramsjson);
        if (!$params) {
            return $paramsjson;
        }

        if (!empty($params->coursemodules)) {
            $params->coursemodules = $this->remap_coursemodules($params->coursemodules);
        }

        if (!empty($params->beforemod)) {
            $mappedbeforemod = $this->get_mapped_cmid($params->beforemod);
            $params->beforemod = $mappedbeforemod ?? $params->beforemod;
        }

        if (isset($params->cmid)) {
            $mappedcmid = $this->get_mapped_cmid($params->cmid);
            $params->cmid = $mappedcmid ?? $params->cmid;
        }

        return json_encode($params);
    }

    /**
     * Remap grade item conditions to the new course ids.
     *
     * @param array|object $gradeitemsconditions
     * @return array
     */
    protected function remap_gradeitemsconditions($gradeitemsconditions): array {
        $remapped = [];
        foreach ((array)$gradeitemsconditions as $key => $gradeitemcondition) {
            $condition = (object)$gradeitemcondition;
            $oldgradeitemid = $condition->gradeitem ?? null;
            $newgradeitemid = $oldgradeitemid ? $this->get_mappingid('grade_item', $oldgradeitemid) : null;

            if ($newgradeitemid) {
                $condition->gradeitem = $newgradeitemid;
                $key = preg_replace('/_(\d+)$/', '_' . $newgradeitemid, (string)$key);
            }

            $remapped[$key] = $condition;
        }

        return $remapped;
    }

    /**
     * Remap course module references stored in params.
     *
     * @param array|object $coursemodules
     * @return array
     */
    protected function remap_coursemodules($coursemodules): array {
        $remapped = [];
        foreach ((array)$coursemodules as $coursemodule) {
            $coursemodule = (object)$coursemodule;
            $oldcmid = $coursemodule->id ?? null;
            $newcmid = $this->get_mapped_cmid($oldcmid);

            // If there is no mapping, keep the old id so the record is not lost.
            $coursemodule->id = $newcmid ?? $oldcmid;
            $remapped[] = $coursemodule;
        }

        return $remapped;
    }

    /**
     * Returns the new course module mapping.
     *
     * @param int|null $cmid
     * @return int|null
     */
    protected function get_mapped_cmid($cmid): ?int {
        if (empty($cmid)) {
            return null;
        }

        $newcmid = $this->get_mappingid('course_module', $cmid);

        return $newcmid ? (int)$newcmid : null;
    }

    /**
     * Final pass to remap any lingering ids once all mappings are available.
     *
     * @return void
     */
    public function after_restore_course() {
        $this->remap_generated_reinforcements();
        $this->remap_persisted_params();
        $this->remap_notification_roles();
        $this->remap_ownership_markers();
        $this->readopt_stripped_markers();
    }

    /**
     * Re-adopt ownership markers that core's own restore stripped from mixed availability trees.
     *
     * remap_ownership_markers() renames markers that survived the restore - but core's
     * update_after_restore re-encodes any tree in which a sibling condition changed (a
     * completion/grade/date restriction always does, by id remap), and availability_user's save()
     * drops the marker property in that re-encode. This pass runs after core's re-encode (this
     * hook is a later step of restore_final_task) and re-derives ownership from the restored
     * action's OWN params - the snapshot that names the modules it manages - adopting the single
     * unmarked user node exactly the way execute() adopts pre-marker legacy trees in production.
     *
     * @return void
     */
    protected function readopt_stripped_markers() {
        global $DB;

        $courseid = $this->task->get_courseid();
        $rewritten = 0;

        foreach ($this->get_restored_action_id_map() as $newactionid) {
            $action = $DB->get_record(
                'local_coursedynamicrules_action',
                ['id' => $newactionid],
                'id, actiontype, params'
            );
            if (!$action || $action->actiontype !== 'enableactivity') {
                continue;
            }

            $params = json_decode((string) $action->params);
            foreach ((array) ($params->coursemodules ?? []) as $coursemodule) {
                $cmid = (int) (is_object($coursemodule) ? ($coursemodule->id ?? 0) : $coursemodule);
                if ($cmid <= 0) {
                    continue;
                }
                // Params were remapped first (after_restore_course order), so this cmid is a module
                // THIS restore created - never a live module of a pre-existing target course.
                $availability = $DB->get_field(
                    'course_modules',
                    'availability',
                    ['id' => $cmid, 'course' => $courseid]
                );
                if (empty($availability)) {
                    continue;
                }
                $adopted = \local_coursedynamicrules\action\enableactivity\enableactivity_action::adopt_stripped_marker(
                    (string) $availability,
                    (int) $action->id
                );
                if ($adopted !== null && $adopted !== $availability) {
                    $DB->set_field('course_modules', 'availability', $adopted, ['id' => $cmid]);
                    $rewritten++;
                }
            }
        }

        if ($rewritten > 0) {
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * Resolve every notification role reference of the restored rules against THIS site.
     *
     * Runs in the after-restore pass on purpose: core's role mapping only exists once the restore's
     * own role decisions are settled, so resolving earlier would drop references that were about to
     * become resolvable. Resolution order is deliberate:
     *   1. core's role mapping - it reflects the restore's (possibly operator-chosen) decisions;
     *   2. the source shortname, which is stable across sites, matched against a local role;
     *   3. drop it. A role id that resolves to nothing on this site is worse than one recipient
     *      fewer: kept as-is it either addresses nobody or, on another site, addresses whoever
     *      happens to hold that number now.
     *
     * @return void
     */
    protected function remap_notification_roles() {
        global $DB;

        $records = $DB->get_records(
            'backup_ids_temp',
            [
                'backupid' => $this->get_restoreid(),
                'itemname' => 'local_coursedynamicrules_action',
            ],
            '',
            'id, newitemid'
        );

        foreach ($records as $record) {
            if (empty($record->newitemid)) {
                continue;
            }

            $action = $DB->get_record(
                'local_coursedynamicrules_action',
                ['id' => $record->newitemid],
                'id, params'
            );
            if (!$action) {
                continue;
            }

            $remapped = $this->remap_action_roles((string) $action->params);
            if ($remapped !== (string) $action->params) {
                $DB->set_field('local_coursedynamicrules_action', 'params', $remapped, ['id' => $action->id]);
            }
        }
    }

    /**
     * Rewrite the role id lists stored in one action's params.
     *
     * @param string $paramsjson
     * @return string
     */
    protected function remap_action_roles(string $paramsjson): string {
        $params = json_decode($paramsjson);
        if (!is_object($params)) {
            return $paramsjson;
        }

        $touched = false;
        foreach (\local_coursedynamicrules\action\sendnotification\sendnotification_action::ROLE_PARAM_KEYS as $key) {
            if (!isset($params->{$key})) {
                continue;
            }

            $resolved = [];
            $dropped = [];
            foreach ((array) $params->{$key} as $roleid) {
                $newroleid = $this->resolve_restored_roleid((int) $roleid);
                if ($newroleid !== null) {
                    $resolved[] = $newroleid;
                } else {
                    $dropped[] = (int) $roleid;
                }
            }

            $resolved = array_values(array_unique($resolved));
            if ($resolved !== array_map('intval', (array) $params->{$key})) {
                $params->{$key} = $resolved;
                $touched = true;
            }

            if (!empty($dropped)) {
                // Never silent: an operator has to be able to find out why a restored rule notifies
                // fewer people than the original did. This belongs in the restore's own log, not in
                // debugging() - the restore log is what an operator actually reads afterwards, and a
                // debugging() call here would also surface as unexpected output under PHPUnit.
                $this->task->log(
                    'local_coursedynamicrules: dropped notification role ids with no equivalent in '
                    . 'this site (' . $key . '): ' . implode(', ', $dropped),
                    backup::LOG_WARNING
                );
            }
        }

        return $touched ? json_encode($params) : $paramsjson;
    }

    /**
     * The id this site uses for a role the source site called $oldroleid, or null when there is none.
     *
     * @param int $oldroleid
     * @return int|null
     */
    protected function resolve_restored_roleid(int $oldroleid): ?int {
        global $DB;

        if ($oldroleid <= 0) {
            return null;
        }

        $mapped = $this->get_mappingid('role', $oldroleid);
        if (!empty($mapped) && $DB->record_exists('role', ['id' => $mapped])) {
            return (int) $mapped;
        }

        $shortname = $this->notificationroleshortnames[$oldroleid] ?? null;
        if ($shortname !== null) {
            $localroleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!empty($localroleid)) {
                return (int) $localroleid;
            }
        }

        // Last resort, and only reachable for a backup produced BEFORE this plugin exported role
        // shortnames: with neither a mapping nor a shortname there is nothing to resolve against, so
        // a numerically valid id is kept. That is right for a same-site restore and is the best
        // available guess elsewhere - a newer backup file always takes the shortname path above.
        if ($DB->record_exists('role', ['id' => $oldroleid])) {
            return $oldroleid;
        }

        return null;
    }

    /**
     * Reconcile the "enable activity" ownership markers of the restored course modules.
     *
     * The availability JSON of a course module is restored verbatim by core, so a restored
     * restriction still names the SOURCE action id. The restored action holds a new id, recognises
     * no node as its own, and can therefore neither grant nor revoke access: the activity stays
     * hidden from every student with no way back. Rewrite each marker onto the id the action was
     * actually restored under.
     *
     * @return void
     */
    protected function remap_ownership_markers() {
        global $DB;

        $actionidmap = $this->get_restored_action_id_map();
        if (empty($actionidmap)) {
            return;
        }

        // ONLY the course modules created by THIS restore may be rewritten. When restoring into an
        // existing course the backup's old action ids share a number space with the live ones, so
        // sweeping every module of the course would rewrite a marker that belongs to an action the
        // target course already had - silently stealing its node and breaking a rule that worked.
        $restoredcmids = $this->get_restored_course_module_ids();
        if (empty($restoredcmids)) {
            return;
        }

        $courseid = $this->task->get_courseid();
        [$insql, $inparams] = $DB->get_in_or_equal($restoredcmids, SQL_PARAMS_NAMED, 'cm');
        $modules = $DB->get_records_select(
            'course_modules',
            "id $insql AND course = :courseid AND availability IS NOT NULL",
            $inparams + ['courseid' => $courseid],
            '',
            'id, availability'
        );

        $rewritten = 0;
        foreach ($modules as $module) {
            $remapped = \local_coursedynamicrules\action\enableactivity\enableactivity_action::remap_ownership_markers(
                $module->availability,
                $actionidmap
            );
            if ($remapped !== null && $remapped !== $module->availability) {
                $DB->set_field('course_modules', 'availability', $remapped, ['id' => $module->id]);
                $rewritten++;
            }
        }

        if ($rewritten > 0) {
            // A course module's availability is part of the course cache: without this the restored
            // course keeps serving the stale tree until something else rebuilds it.
            rebuild_course_cache($courseid, true);
        }
    }

    /**
     * The ids of the course modules this restore created.
     *
     * @return int[]
     */
    protected function get_restored_course_module_ids(): array {
        global $DB;

        $records = $DB->get_records(
            'backup_ids_temp',
            [
                'backupid' => $this->get_restoreid(),
                'itemname' => 'course_module',
            ],
            '',
            'id, newitemid'
        );

        $cmids = [];
        foreach ($records as $record) {
            if (!empty($record->newitemid)) {
                $cmids[] = (int) $record->newitemid;
            }
        }

        return array_values(array_unique($cmids));
    }

    /**
     * Map of source action id => restored action id, as recorded while the actions were processed.
     *
     * @return array
     */
    protected function get_restored_action_id_map(): array {
        global $DB;

        $records = $DB->get_records(
            'backup_ids_temp',
            [
                'backupid' => $this->get_restoreid(),
                'itemname' => 'local_coursedynamicrules_action',
            ],
            '',
            'id, itemid, newitemid'
        );

        $map = [];
        foreach ($records as $record) {
            if (!empty($record->newitemid)) {
                $map[(int) $record->itemid] = (int) $record->newitemid;
            }
        }

        return $map;
    }

    /**
     * Re-run param remapping for restored rules so cmids/grade items update after activities are created.
     *
     * @return void
     */
    protected function remap_persisted_params() {
        global $DB;

        $records = $DB->get_records(
            'backup_ids_temp',
            [
                'backupid' => $this->get_restoreid(),
                'itemname' => 'local_coursedynamicrules_rule',
            ],
            '',
            'itemid, newitemid'
        );

        if (empty($records)) {
            return;
        }

        foreach ($records as $record) {
            $newruleid = $record->newitemid;

            $this->remap_condition_records($newruleid);
            $this->remap_action_records($newruleid);
        }
    }

    /**
     * Remap and persist params for conditions of a rule.
     *
     * @param int $ruleid
     * @return void
     */
    protected function remap_condition_records(int $ruleid) {
        global $DB;

        $conditions = $DB->get_records('local_coursedynamicrules_condition', ['ruleid' => $ruleid]);
        foreach ($conditions as $condition) {
            $paramsjson = $condition->params ?? '';
            $remappedjson = $this->remap_condition_params($paramsjson);

            if ($remappedjson !== $paramsjson) {
                $DB->set_field('local_coursedynamicrules_condition', 'params', $remappedjson, ['id' => $condition->id]);
            }
        }
    }

    /**
     * Remap and persist params for actions of a rule.
     *
     * @param int $ruleid
     * @return void
     */
    protected function remap_action_records(int $ruleid) {
        global $DB;

        $actions = $DB->get_records('local_coursedynamicrules_action', ['ruleid' => $ruleid]);
        foreach ($actions as $action) {
            $paramsjson = $action->params ?? '';
            $remappedjson = $this->remap_action_params($paramsjson);

            if ($remappedjson !== $paramsjson) {
                $DB->set_field('local_coursedynamicrules_action', 'params', $remappedjson, ['id' => $action->id]);
            }
        }
    }

    /**
     * Point the restored reinforcement register at this site's modules.
     *
     * Runs after activities exist, which is the earliest moment course_module mappings are
     * available. Two outcomes are deliberate:
     *
     * - The generated activity did not come across: the marker points at nothing, and keeping it
     *   would deny the student a reinforcement they no longer have. The row is deleted.
     * - Only the watched activity is missing: the marker still holds, so it is kept with the link
     *   cleared - exactly how the non-carrying grade modes already store it.
     *
     * Scoped strictly to the rows THIS restore created. A restore into an existing course finds
     * that course's own rows here too, and they already carry live module ids that would not
     * resolve through a mapping table - remapping them would delete perfectly good markers.
     *
     * @return void
     */
    protected function remap_generated_reinforcements() {
        global $DB;

        $table = \local_coursedynamicrules\local\service\grade_register_service::TABLE;
        $ids = $this->get_restored_aigrade_ids();
        if (!$ids) {
            return;
        }

        foreach ($DB->get_records_list($table, 'id', $ids) as $row) {
            $cmid = $this->get_mapped_cmid($row->cmid);
            if (empty($cmid)) {
                $DB->delete_records($table, ['id' => $row->id]);
                continue;
            }

            if ((int)$row->cmid !== $cmid) {
                $DB->update_record($table, (object)['id' => $row->id, 'cmid' => $cmid]);
            }

            // Re-exclude, from scratch, for the students who are in THIS course. The exclusions
            // themselves live in grade_grades, which core only carries inside user data, so a copy
            // made without it arrives with a live grade column and not one student excluded from
            // it - measured at 0% under Lowest grade for everybody in the copy. Even a restore WITH
            // user data only brings the exclusions of the users who were in the backup, so a
            // restore into an existing course leaves that course's own students uncovered.
            //
            // Cheap and idempotent: exclude_all_but() only writes a row that is missing or a flag
            // that is not set, and never touches a grade that already exists.
            $cm = get_coursemodule_from_id(null, $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            \local_coursedynamicrules\local\service\grade_isolation_service::apply(
                (int)$row->courseid,
                $cm->modname,
                (int)$cm->instance,
                $row->grademode,
                (int)$row->userid
            );
        }
    }

    /**
     * Ids of the register rows this restore created, from core's own mapping table.
     *
     * @return int[]
     */
    protected function get_restored_aigrade_ids(): array {
        global $DB;

        $records = $DB->get_records(
            'backup_ids_temp',
            [
                'backupid' => $this->get_restoreid(),
                'itemname' => 'local_coursedynamicrules_aigrade',
            ],
            '',
            'id, newitemid'
        );

        $ids = [];
        foreach ($records as $record) {
            if (!empty($record->newitemid)) {
                $ids[] = (int)$record->newitemid;
            }
        }

        return $ids;
    }
}
