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

namespace local_coursedynamicrules\local\service;

use grade_category;
use grade_item;

/**
 * Decides what an AI-generated activity is allowed to do to the course gradebook.
 *
 * A generated activity is visible to one student, but that restriction lives in
 * `course_modules.availability` and the gradebook never reads it: the new grade item is a column
 * for the WHOLE course, empty for everybody else. Whether that empty column costs those students
 * points depends on the "Exclude empty grades" box of the grade category, which any teacher can
 * untick - and under "Lowest grade" aggregation it takes their course total to zero.
 *
 * Four modes. Every figure below was measured on Moodle 4.5.8 with a bystander who scored 80/100
 * on a baseline activity and never saw the generated one, under the dangerous setting (empty
 * grades counting as zero, where doing nothing drops them to 40%):
 *
 * - MODE_NOGRADE  (default) the activity is not graded at all. No grade item takes part, so no
 *                 column exists, so there is nothing to go empty. The bystander stays at 80%
 *                 under EVERY aggregation method - the only mode with no caveat.
 * - MODE_OWN      the activity keeps its grade in a dedicated extra-credit category. Bystander
 *                 80%, recipient rises to 100%: the grade joins the numerator without enlarging
 *                 the denominator.
 * - MODE_COMBINE  the activity is graded but placed in a zero-weight category so it counts for
 *                 nobody; {@see grade_combination_service} then writes the combined result onto
 *                 the source activity's grade for that student.
 * - MODE_REPLACE  as above, but the source grade is replaced rather than combined.
 *
 * MODE_OWN, MODE_COMBINE and MODE_REPLACE lean on `aggregationcoef`/`aggregationcoef2`, which only
 * Natural and Simple weighted mean consult (lib/grade/grade_category.php:1344-1366). Under Mean,
 * Median, Mode or Lowest grade the category counts as one more value and the protection is gone -
 * see {@see self::protects_under()}. MODE_NOGRADE is immune to that because it removes the column
 * instead of re-weighting it.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_isolation_service {
    /** @var string The generated activity carries no grade at all. Default. */
    public const MODE_NOGRADE = 'nograde';

    /** @var string It keeps its own grade, counting only for the student who received it. */
    public const MODE_OWN = 'own';

    /** @var string Its grade is combined with the source activity's grade. */
    public const MODE_COMBINE = 'combine';

    /** @var string Its grade replaces the source activity's grade. */
    public const MODE_REPLACE = 'replace';

    /** @var string Combine rule: keep whichever of the two grades is higher. */
    public const RULE_BEST = 'best';

    /** @var string Combine rule: average both grades. */
    public const RULE_MEAN = 'mean';

    /** @var string Replace rule: only when the reinforcement is better. */
    public const RULE_IMPROVE = 'improve';

    /** @var string Replace rule: always, better or worse. */
    public const RULE_ALWAYS = 'always';

    /** @var string Replace rule: capped at the source activity's pass grade. */
    public const RULE_CAP = 'cap';

    /**
     * @var string Stable key for the plugin's category, stored on the category's own grade item.
     * Matching on the name would break the moment a teacher renames it.
     */
    private const CATEGORY_IDNUMBER = 'local_coursedynamicrules_reinforcement';

    /**
     * Every mode a rule may store, in the order they are offered.
     *
     * @return string[]
     */
    public static function modes(): array {
        return [self::MODE_NOGRADE, self::MODE_OWN, self::MODE_COMBINE, self::MODE_REPLACE];
    }

    /**
     * The modes available once the activity does carry a grade.
     *
     * @return string[]
     */
    public static function graded_modes(): array {
        return [self::MODE_OWN, self::MODE_COMBINE, self::MODE_REPLACE];
    }

    /**
     * Turn the form's two-level choice into a single stored mode.
     *
     * The form asks two questions - does the activity carry a grade, and where does that grade
     * land - because they are two decisions, not four siblings. Only one function may join them,
     * or saving and validating could disagree about what the teacher chose.
     *
     * @param mixed $hasgrade Whether the activity is graded at all.
     * @param mixed $grademode Where the grade lands, when there is one.
     * @return string
     */
    public static function mode_from_choice($hasgrade, $grademode): string {
        if (empty($hasgrade)) {
            return self::MODE_NOGRADE;
        }
        $grademode = is_string($grademode) ? $grademode : '';

        return in_array($grademode, self::graded_modes(), true) ? $grademode : self::MODE_OWN;
    }

    /**
     * Modes that need a source activity to act upon.
     *
     * @return string[]
     */
    public static function modes_needing_source(): array {
        return [self::MODE_COMBINE, self::MODE_REPLACE];
    }

    /**
     * The rules valid for a mode; an empty list means the mode takes no rule.
     *
     * @param string $mode
     * @return string[]
     */
    public static function rules_for(string $mode): array {
        if ($mode === self::MODE_COMBINE) {
            return [self::RULE_BEST, self::RULE_MEAN];
        }
        if ($mode === self::MODE_REPLACE) {
            return [self::RULE_IMPROVE, self::RULE_ALWAYS, self::RULE_CAP];
        }

        return [];
    }

    /**
     * Normalise a stored or submitted mode, falling back to the safe default.
     *
     * Rules saved before this option existed carry no value at all, and the fallback has to be the
     * mode that cannot hurt anybody rather than the one that reproduces the old behaviour.
     *
     * @param mixed $value
     * @return string
     */
    public static function clean_mode($value): string {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::modes(), true) ? $value : self::MODE_NOGRADE;
    }

    /**
     * Normalise a stored or submitted rule for a given mode.
     *
     * @param string $mode
     * @param mixed $value
     * @return string Empty string when the mode takes no rule.
     */
    public static function clean_rule(string $mode, $value): string {
        $valid = self::rules_for($mode);
        if (empty($valid)) {
            return '';
        }
        $value = is_string($value) ? $value : '';

        return in_array($value, $valid, true) ? $value : reset($valid);
    }

    /**
     * Whether the category-based modes actually protect anyone under a given aggregation.
     *
     * Measured, not assumed: under Mean a bystander still drops to 40% and under Lowest grade to
     * 0%, because those methods never read the weight coefficients. MODE_NOGRADE is absent from
     * this question because it does not depend on the aggregation at all.
     *
     * @param int $aggregation One of the GRADE_AGGREGATE_* constants.
     * @return bool
     */
    public static function protects_under(int $aggregation): bool {
        return in_array($aggregation, [GRADE_AGGREGATE_SUM, GRADE_AGGREGATE_WEIGHTED_MEAN2], true);
    }

    /**
     * Apply a mode to a freshly generated activity.
     *
     * Safe to call repeatedly: the category is looked up before being created, and moving an item
     * that already sits in it is a no-op.
     *
     * @param int $courseid Course the activity belongs to.
     * @param string $modname Module name, e.g. 'quiz'.
     * @param int $instance Module instance id.
     * @param string $mode One of the MODE_* constants.
     * @return int Number of grade items acted upon.
     */
    public static function apply(int $courseid, string $modname, int $instance, string $mode): int {
        $mode = self::clean_mode($mode);
        $items = self::gradable_items($courseid, $modname, $instance);

        if (empty($items)) {
            // Resources such as page, folder or url produce no grade item at all: there is no
            // column, so there is nothing for any mode to shield anyone from.
            return 0;
        }

        if ($mode === self::MODE_NOGRADE) {
            return self::ungrade($courseid, $modname, $instance, $items);
        }

        $category = self::ensure_category($courseid);
        $moved = 0;
        foreach ($items as $item) {
            if ($item->set_parent($category->id)) {
                $moved++;
            }
        }

        self::configure_category($category, $mode);
        $category->force_regrading();

        return $moved;
    }

    /**
     * Strip the grade from every item of the activity.
     *
     * Uses grade_update(), the same module-facing API a module calls when its own grade setting is
     * turned off, rather than deleting rows: measured to remove the column from the gradebook tree
     * and close the hole under every aggregation, while leaving the item in place so a teacher can
     * turn grading back on if they decide to.
     *
     * @param int $courseid
     * @param string $modname
     * @param int $instance
     * @param grade_item[] $items
     * @return int
     */
    private static function ungrade(int $courseid, string $modname, int $instance, array $items): int {
        $done = 0;
        foreach ($items as $item) {
            $result = grade_update(
                'local_coursedynamicrules',
                $courseid,
                'mod',
                $modname,
                $instance,
                (int) $item->itemnumber,
                null,
                ['gradetype' => GRADE_TYPE_NONE]
            );
            if ($result === GRADE_UPDATE_OK) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * The grade items an activity contributes to aggregation.
     *
     * A module may create several: a rated forum creates two ('rating' and 'whole forum'), a
     * workshop two, and a page none. Items with no grade type never take part in aggregation
     * (lib/grade/grade_item.php:1720-1735 filters on GRADE_TYPE_VALUE), so they are skipped.
     *
     * @param int $courseid
     * @param string $modname
     * @param int $instance
     * @return grade_item[]
     */
    public static function gradable_items(int $courseid, string $modname, int $instance): array {
        $items = grade_item::fetch_all([
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instance,
        ]) ?: [];

        return array_filter($items, function (grade_item $item): bool {
            return in_array((int) $item->gradetype, [GRADE_TYPE_VALUE, GRADE_TYPE_SCALE], true);
        });
    }

    /**
     * Find the plugin's grade category for a course, creating it the first time.
     *
     * @param int $courseid
     * @return grade_category
     */
    private static function ensure_category(int $courseid): grade_category {
        global $DB;

        $existing = $DB->get_field('grade_items', 'iteminstance', [
            'courseid' => $courseid,
            'itemtype' => 'category',
            'idnumber' => self::CATEGORY_IDNUMBER,
        ]);
        if ($existing) {
            $category = grade_category::fetch(['id' => $existing, 'courseid' => $courseid]);
            if ($category) {
                return $category;
            }
        }

        $category = new grade_category([
            'courseid' => $courseid,
            'fullname' => get_string('createaiactivity_gradecategory_name', 'local_coursedynamicrules'),
        ], false);
        $category->insert();

        $category = grade_category::fetch(['id' => $category->id, 'courseid' => $courseid]);
        $category->load_grade_item()->add_idnumber(self::CATEGORY_IDNUMBER);

        return $category;
    }

    /**
     * Apply the chosen mode to the category's own grade item.
     *
     * @param grade_category $category
     * @param string $mode
     */
    private static function configure_category(grade_category $category, string $mode): void {
        $item = $category->load_grade_item();

        if ($mode === self::MODE_OWN) {
            // Extra credit adds to the numerator without adding its maximum to the denominator
            // (grade_category.php:1344-1350), so a student with no grade here loses nothing.
            $item->aggregationcoef = 1;
            $item->weightoverride = 0;
        } else {
            // Combine and replace land their effect on the SOURCE activity, so the reinforcement
            // itself must count for nobody - including the student who did it, or the same work
            // would be rewarded twice. A pinned weight of zero keeps it out of both sides of the
            // fraction (grade_category.php:1357-1366).
            $item->aggregationcoef = 0;
            $item->weightoverride = 1;
            $item->aggregationcoef2 = 0;
        }

        $item->update();
    }
}
