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

namespace local_coursedynamicrules\tests;

use core_availability\tree;
use local_coursedynamicrules\action\enableactivity\enableactivity_action;
use local_coursedynamicrules\local\aiactivity_key;

/**
 * Builds the activity createaiactivity_action leaves behind, without calling the AI service.
 *
 * The action keys the module's ID number with aiactivity_key and restricts it to the one student
 * through a single user node stamped with the action's marker (createaiactivity_action::execute()).
 * This reproduces that end state, so the privacy tests do not need the AI companion plugins.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generated_ai_activity {
    /**
     * Insert a rule holding one createaiactivity action.
     *
     * @param int $courseid The course.
     * @param string $name The rule name.
     * @return int The action id.
     */
    public static function create_action(int $courseid, string $name = 'AI rule'): int {
        global $DB;
        $ruleid = (int) $DB->insert_record('local_coursedynamicrules_rule', (object) [
            'courseid' => $courseid,
            'name' => $name,
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return (int) $DB->insert_record('local_coursedynamicrules_action', (object) [
            'ruleid' => $ruleid,
            'name' => 'createaiactivity',
            'actiontype' => 'createaiactivity',
            'params' => json_encode(['message' => 'Create a page', 'generateimages' => false, 'sectionnum' => 0]),
        ]);
    }

    /**
     * Create the activity an action generated for a student: keyed, and gated by a marked user node.
     *
     * @param \testing_data_generator $generator The data generator.
     * @param int $courseid The course.
     * @param int $actionid The createaiactivity action.
     * @param int $userid The student.
     * @param string $modname The module type ('assign' when a grade item is needed).
     * @return int The course module id.
     */
    public static function create(
        \testing_data_generator $generator,
        int $courseid,
        int $actionid,
        int $userid,
        string $modname = 'page'
    ): int {
        global $DB;
        $key = aiactivity_key::for_action_user($actionid, $userid);
        $module = $generator->create_module($modname, ['course' => $courseid, 'idnumber' => $key]);
        $node = enableactivity_action::mark_node((object) ['type' => 'user', 'userids' => [$userid]], $actionid);
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(tree::get_root_json([$node], tree::OP_AND, false)),
            ['id' => $module->cmid]
        );
        rebuild_course_cache($courseid, true);

        return (int) $module->cmid;
    }

    /**
     * Add an unmarked user node to a module's tree: a teacher's own restriction.
     *
     * @param int $cmid The module.
     * @param int[] $userids The users it lists.
     * @return void
     */
    public static function add_teacher_restriction(int $cmid, array $userids): void {
        global $DB;
        $tree = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $tree->c[] = (object) ['type' => 'user', 'userids' => array_values($userids)];
        $tree->showc = array_fill(0, count($tree->c), false);
        $DB->set_field('course_modules', 'availability', json_encode($tree), ['id' => $cmid]);
    }

    /**
     * Strip every marker from a module's tree, as saving the module settings form does.
     *
     * @param int $cmid The module.
     * @return void
     */
    public static function strip_markers(int $cmid): void {
        global $DB;
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $walk = function ($node) use (&$walk) {
            foreach ((array) ($node->c ?? []) as $child) {
                $walk($child);
            }
            if (($node->type ?? null) === 'user') {
                unset($node->source, $node->sourceadopted);
            }
        };
        $walk($root);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cmid]);
    }

    /**
     * The root-level user nodes of a module's tree, in order.
     *
     * @param int $cmid The module.
     * @return \stdClass[]
     */
    public static function user_nodes(int $cmid): array {
        global $DB;
        $root = json_decode((string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]));
        $nodes = [];
        foreach ((array) ($root->c ?? []) as $node) {
            if (($node->type ?? null) === 'user') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The ids a node lists, as integers, in stored order.
     *
     * @param \stdClass $node A user node.
     * @return int[]
     */
    public static function ids_of(\stdClass $node): array {
        $ids = array_map('intval', array_values((array) ($node->userids ?? [])));
        if (isset($node->userid)) {
            $ids[] = (int) $node->userid;
        }

        return $ids;
    }
}
