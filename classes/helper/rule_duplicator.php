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
 * Duplicate a rule into an inactive, unsealed draft - the lock's official escape hatch.
 *
 * Traced from Moodle Workplace's tool_dynamicrule (api.php, duplicate_rule): the copy is born
 * DISABLED under a distinct name, configuration copied, runtime state left behind. Workplace's
 * own edit dialog suggests duplication as the way to change a rule whose conditions locked; this
 * plugin's lock is stricter (everything seals at first activation), which makes the hatch more
 * valuable, not less: duplicate the sealed rule, edit the draft, activate it deliberately.
 *
 * Duplication is deliberately NOT gated by the lock - copying a sealed rule is the point - and
 * deliberately IS gated by ownership: the source must belong to the course the copy lands in.
 * Params travel verbatim: runtime throttles stored inside params (a deliberate storage choice of
 * their component classes) mirror the original's window, which for an inactive draft only decides
 * how soon it may first fire after a deliberate activation.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_duplicator {
    /**
     * Copy a rule and its components into a new inactive, unsealed rule of the same course.
     *
     * @param int $ruleid The source rule - sealed or not.
     * @param int $courseid The course both the source and the copy belong to.
     * @param \context $context Course context, for the audit event. Required on purpose: deciding
     *                          it here silently is the seam the capability fixes buried.
     * @return int The new rule id.
     * @throws \moodle_exception When the source rule does not belong to the course.
     */
    public static function duplicate(int $ruleid, int $courseid, \context $context): int {
        global $DB;

        $source = ownership::get_rule($ruleid, $courseid);

        $copy = new \stdClass();
        $copy->courseid = $courseid;
        $copy->name = self::unique_copy_name((string) $source->name, $courseid);
        $copy->description = $source->description;
        $copy->active = 0;
        $copy->timeactivated = null;
        $copy->lastexecutiontime = null;
        $copy->timecreated = time();
        $copy->timemodified = time();
        $newid = (int) $DB->insert_record('local_coursedynamicrules_rule', $copy);

        foreach (['local_coursedynamicrules_condition', 'local_coursedynamicrules_action'] as $table) {
            foreach ($DB->get_records($table, ['ruleid' => $ruleid]) as $component) {
                unset($component->id);
                $component->ruleid = $newid;
                $component->lastexecutiontime = null;
                $DB->insert_record($table, $component);
            }
        }

        \local_coursedynamicrules\event\rule_created::create([
            'context' => $context,
            'objectid' => $newid,
        ])->trigger();

        return $newid;
    }

    /**
     * "{name} (copy)", numbered upward until it is unique within the course.
     *
     * @param string $name The source rule's name.
     * @param int $courseid
     * @return string
     */
    protected static function unique_copy_name(string $name, int $courseid): string {
        global $DB;

        $candidate = get_string('rulecopyname', 'local_coursedynamicrules', $name);
        $n = 1;
        while ($DB->record_exists('local_coursedynamicrules_rule', ['courseid' => $courseid, 'name' => $candidate])) {
            $n++;
            $candidate = get_string('rulecopynamenumbered', 'local_coursedynamicrules', (object) [
                'name' => $name,
                'n' => $n,
            ]);
        }

        return $candidate;
    }
}
