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

use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\condition;

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
 * Every component's params go through its own params_for_duplicate() (condition::/action:: base
 * contract) rather than travelling as a raw column: verbatim by default, so most components copy
 * exactly, and overridden by the few whose stored params are not configuration alone - see
 * no_course_access_condition and enableactivity_action for the two that override it today, and why.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_duplicator {
    /** @var int Length of the rule name column (db/install.xml, local_coursedynamicrules_rule.name). */
    private const NAME_LENGTH = 255;

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

        // One transaction for the rule and every component: instantiating a component can throw
        // (rule_component_loader refuses a type whose class is missing), and a copy that exists with
        // half its components is worse than no copy - it looks like a deliberate draft.
        $transaction = $DB->start_delegated_transaction();

        // The source's row is the starting point, and what must NOT travel is named here: every
        // other column - including any added later - copies by default, which is what "the same rule
        // with new ids" means. Enumerating what must travel instead would silently drop the next
        // column somebody adds, and the copy would differ from its source in a way nothing reveals.
        $copy = clone $source;
        unset($copy->id);
        $copy->name = self::unique_copy_name((string) $source->name, $courseid);
        // Runtime and lifecycle state stays with the original: a copy was never activated (so it is
        // unsealed and fully editable), never stopped by the engine, and never run.
        $copy->active = 0;
        $copy->timeactivated = null;
        $copy->timeautodeactivated = null;
        $copy->lastexecutiontime = null;
        $copy->timecreated = time();
        $copy->timemodified = time();
        $newid = (int) $DB->insert_record('local_coursedynamicrules_rule', $copy);

        $componentevents = [];
        foreach (self::component_tables() as $table => [$loader, $eventclass]) {
            foreach ($DB->get_records($table, ['ruleid' => $ruleid]) as $component) {
                $instance = $loader($component, $courseid);

                unset($component->id);
                $component->ruleid = $newid;
                $component->lastexecutiontime = null;
                // Cast to object before encoding: a component whose stored params did not decode to
                // an object copies as an empty params SET, and an empty PHP array would be written as
                // "[]" - a JSON array, the very shape every consumer of params cannot read.
                $component->params = json_encode((object) $instance->params_for_duplicate());
                $componentevents[] = [$eventclass, (int) $DB->insert_record($table, $component)];
            }
        }

        $transaction->allow_commit();

        // Logged after the commit, and the rule before its components: the order a hand-built rule
        // is logged in, where the rule exists before anything can be added to it (editrule.php,
        // then conditions/actions.php).
        \local_coursedynamicrules\event\rule_created::create([
            'context' => $context,
            'objectid' => $newid,
        ])->trigger();
        foreach ($componentevents as [$eventclass, $componentid]) {
            $eventclass::create([
                'context' => $context,
                'objectid' => $componentid,
            ])->trigger();
        }

        return $newid;
    }

    /**
     * Component table => [the loader that turns one of its rows into a component instance, the event
     * class fired when a copy of that row is created]. The event classes are the ones
     * conditions.php/actions.php fire for a hand-built component, so a duplicated rule's components
     * are as visible in the site logs as its own. Keyed off the classes' own TABLE constants: a
     * mismatch between the key and the loader would send every row to the wrong one.
     *
     * @return array<string, array{callable, string}>
     */
    private static function component_tables(): array {
        return [
            condition::TABLE => [
                [rule_component_loader::class, 'create_condition_instance'],
                \local_coursedynamicrules\event\condition_created::class,
            ],
            action::TABLE => [
                [rule_component_loader::class, 'create_action_instance'],
                \local_coursedynamicrules\event\action_created::class,
            ],
        ];
    }

    /**
     * "{name} (copy)", numbered upward until it is unique within the course, and never longer than
     * the name column.
     *
     * @param string $name The source rule's name.
     * @param int $courseid
     * @return string
     */
    private static function unique_copy_name(string $name, int $courseid): string {
        global $DB;

        $n = 1;
        $candidate = self::copy_name($name, $n);
        while ($DB->record_exists('local_coursedynamicrules_rule', ['courseid' => $courseid, 'name' => $candidate])) {
            $n++;
            $candidate = self::copy_name($name, $n);
        }

        return $candidate;
    }

    /**
     * The copy's name for attempt $n, shortened at the END of the source name when name and suffix
     * together would overflow the column. The suffix is what tells the copy apart from its source, so
     * it takes its room first and the name gets what is left; a long source name loses its last
     * characters instead of making the database refuse the copy.
     *
     * Measured in characters, as the column is: the name may well be multibyte. The suffix's room is
     * measured on the string built around an empty name, not guessed from a full build: get_string()
     * substitutes placeholders sequentially, so a "{$a->n}" sitting inside the name itself would be
     * replaced too and throw a length guess off. The final clamp is for a customised language string
     * that repeats the placeholder: the column has the last word, never the string.
     *
     * @param string $name The source rule's name.
     * @param int $n 1 for "(copy)", higher for "(copy N)".
     * @return string
     */
    private static function copy_name(string $name, int $n): string {
        $build = static function (string $base) use ($n): string {
            if ($n === 1) {
                return get_string('rulecopyname', 'local_coursedynamicrules', $base);
            }
            return get_string('rulecopynamenumbered', 'local_coursedynamicrules', (object) ['name' => $base, 'n' => $n]);
        };

        $room = self::NAME_LENGTH - \core_text::strlen($build(''));
        $candidate = $build(\core_text::substr($name, 0, max(0, $room)));

        return \core_text::substr($candidate, 0, self::NAME_LENGTH);
    }
}
