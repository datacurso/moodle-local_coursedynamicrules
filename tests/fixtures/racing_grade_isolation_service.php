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

/**
 * The isolation service with somebody else winning the race, on purpose.
 *
 * exclude_all_but() reads which grade_grades rows already exist, then writes the ones that do not.
 * In production the hazard is another process inserting one of those rows inside that window -
 * which is not exotic: core creates a row for every enrolled user during any regrade
 * (lib/grade/grade_category.php:655-668), and creating the module leaves a regrade pending, so a
 * teacher opening the grader report is enough.
 *
 * That window cannot be opened through the public API. Feeding the same user id twice does NOT
 * produce the collision - array_unique() in exclude_all_but() removes the duplicate before
 * anything is queued - so a test written that way measures nothing and the fallback in
 * insert_shields() would ship with no coverage at all. This subclass opens the window at the one
 * point where it exists, by inserting the colliding row as the read returns.
 *
 * @package    local_coursedynamicrules
 * @category   test
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class racing_grade_isolation_service extends grade_isolation_service {

    /** @var int The user whose row somebody else inserts mid-flight. */
    public static $loser = 0;

    /** @var int How many rows this actually slipped in, so a test can prove the race happened. */
    public static $injected = 0;

    /**
     * Read as production does, then let somebody else write before we do.
     *
     * The returned array is deliberately the one from BEFORE the injection: that staleness is the
     * whole defect being tested. exclude_all_but() will queue an insert for a row that now exists.
     *
     * @param int $itemid
     * @param int[] $userids
     * @return \stdClass[]
     */
    protected static function read_existing_shields(int $itemid, array $userids): array {
        global $DB;

        $existing = parent::read_existing_shields($itemid, $userids);

        if (self::$loser && !isset($existing[self::$loser])) {
            // excluded = 0, because the interesting case is a row that exists but is NOT yet
            // shielded: the fallback has to flag it rather than skip it or overwrite the grade.
            $DB->insert_record('grade_grades', (object) [
                'itemid' => $itemid,
                'userid' => self::$loser,
                'excluded' => 0,
                'overridden' => 0,
                'locked' => 0,
                'locktime' => 0,
                'hidden' => 0,
                'aggregationstatus' => 'unknown',
                'aggregationweight' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            self::$injected++;
        }

        return $existing;
    }
}
