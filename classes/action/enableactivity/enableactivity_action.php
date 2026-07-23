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

namespace local_coursedynamicrules\action\enableactivity;

use core_availability\tree;
use local_coursedynamicrules\core\action;
use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\form\actions\enableactivity_form;
use stdClass;

/**
 * Class enableactivity_action
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enableactivity_action extends action {
    /** @var string type of the action */
    protected $type = 'enableactivity';

    /**
     * Execute the action
     * @param object $context Context of the rule
     */
    public function execute($context) {
        global $DB;
        $userid = $context->userid;
        $coursemodules = $this->params->coursemodules;

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;
            $cmrecord = $DB->get_record('course_modules', ['id' => $cmid]);
            if (!$cmrecord) {
                debugging('enableactivity: course module ' . $cmid . ' no longer exists; skipped', DEBUG_DEVELOPER);
                continue;
            }

            $availability = $cmrecord->availability ? json_decode($cmrecord->availability) : null;

            // The action only manages the user restriction it created at save time. Locate it by type
            // rather than by position: a teacher may add another restriction that shifts it off index 0.
            // If it is no longer present (restrictions cleared or replaced) skip this module instead of
            // raising a fatal error or corrupting a foreign availability condition.
            $usercondition = $this->find_user_condition($availability);
            if ($usercondition === null) {
                debugging('enableactivity: expected user restriction not found on cm ' . $cmid . '; skipped', DEBUG_DEVELOPER);
                continue;
            }

            $userids = $usercondition->userids ?? [];

            if (!in_array($userid, $userids)) {
                $userids[] = $userid;
                $usercondition->userids = $userids;

                $DB->set_field(
                    'course_modules',
                    'availability',
                    json_encode($availability),
                    ['id' => $cmid]
                );
            }
        }

        rebuild_course_cache($this->courseid, true);
    }

    /**
     * Find the top-level user restriction node in a decoded availability tree.
     *
     * The returned node is the live object inside the tree, so mutating its properties updates the
     * tree in place. Only the first level is searched, matching the flat tree the action creates.
     *
     * @param object|null $availability Decoded availability tree, or null.
     * @return object|null The user condition node, or null if none is present.
     */
    private function find_user_condition($availability) {
        if (!isset($availability->c) || !is_array($availability->c)) {
            return null;
        }
        foreach ($availability->c as $condition) {
            if (isset($condition->type) && $condition->type === 'user') {
                return $condition;
            }
        }
        return null;
    }

    /**
     * Creates and returns an instance of the form for editing the action
     *
     * @param mixed $action the action attribute for the form. If empty defaults to auto detect the
     *              current url. If a moodle_url object then outputs params as hidden variables.
     * @param mixed $customdata if your form defintion method needs access to data such as $course
     *              $cm, etc. to construct the form definition then pass it in this array. You can
     *              use globals for somethings.
     * @param string $method if you set this to anything other than 'post' then _GET and _POST will
     *               be merged and used as incoming data to the form.
     * @param string $target target frame for form submission. You will rarely use this. Don't use
     *               it if you don't need to as the target attribute is deprecated in xhtml strict.
     * @param mixed $attributes you can pass a string of html attributes here or an array.
     *               Special attribute 'data-random-ids' will randomise generated elements ids. This
     *               is necessary when there are several forms on the same page.
     *               Special attribute 'data-double-submit-protection' set to 'off' will turn off
     *               double-submit protection JavaScript - this may be necessary if your form sends
     *               downloadable files in response to a submit button, and can't call
     *               \core_form\util::form_download_complete();
     * @param bool $editable
     * @param array $ajaxformdata Forms submitted via ajax, must pass their data here, instead of relying on _GET and _POST.
     */
    public function build_editform(
        $action = null,
        $customdata = null,
        $method = 'post',
        $target = '',
        $attributes = null,
        $editable = true,
        $ajaxformdata = null
    ) {
        $this->actionform = new enableactivity_form(
            $action,
            $customdata,
            $method,
            $target,
            $attributes,
            $editable,
            $ajaxformdata
        );
    }

    /**
     * Saves the action after it has been edited (or created)
     * @param object $formdata
     */
    public function save_action($formdata) {
        global $DB;

        $coursemodules = [];

        foreach ($formdata->coursemodules as $cmid) {
            $cminfo = get_coursemodule_from_id(null, $cmid, $formdata->courseid);

            // Store visible and visibleoncoursepage status to restore it later when the rule is deleted.
            $coursemodules[] = (object) [
                'id' => $cmid,
                'visible' => $cminfo->visible,
                'visibleoncoursepage' => $cminfo->visibleoncoursepage,
            ];
        }

        $params = [
            'coursemodules' => $coursemodules,
        ];

        $action = new stdClass();
        $action->ruleid = $formdata->ruleid;
        $action->actiontype = $this->type;
        $action->params = json_encode($params);

        $this->set_data($action);

        $DB->insert_record('local_coursedynamicrules_action', $action);

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;
            $availabilityoptions = (object)[
                'type' => 'user',
                'userids' => [],
            ];
            $availability = tree::get_root_json(
                [$availabilityoptions],
                tree::OP_AND,
                false
            );

            // Set availability to the course module.
            $availability = json_encode($availability);
            $DB->set_field(
                'course_modules',
                'availability',
                $availability,
                ['id' => $cmid]
            );

            // Set module to visible.
            set_coursemodule_visible($cmid, 1);
        }
        rebuild_course_cache($formdata->courseid, true);
    }

    /**
     * Returns the description of the action to visualization
     *
     * @return string
     */
    public function get_description() {
        $coursemodules = $this->params->coursemodules;
        $descriptionarray = [];

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;
            $cminfo = get_coursemodule_from_id(null, $cmid, $this->courseid);
            if (!$cminfo) {
                continue;
            }
            $descriptionarray[] = ucfirst($cminfo->modname) . " - " . $cminfo->name;
        }
        return get_string(
            'enableactivity_description',
            'local_coursedynamicrules',
            implode(', ', $descriptionarray)
        );
    }

    /**
     * Deletes a action record from the 'local_coursedynamicrules_action' table. and related information with it.
     *
     * @return bool True on success, false on failure.
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public function delete() {
        global $DB;
        $coursemodules = $this->params->coursemodules;

        foreach ($coursemodules as $cm) {
            $cmid = $cm->id;

            // If the module no longer exists there is nothing to restore; keep going so the rule
            // stays deletable (set_coursemodule_visible() would otherwise fatal on a missing context).
            if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
                continue;
            }

            $initialvisible = $cm->visible;
            $initialvisibleoncoursepage = $cm->visibleoncoursepage;

            // Remove availability condition from the course module.
            $DB->set_field(
                'course_modules',
                'availability',
                null,
                ['id' => $cmid]
            );

            // Restore coursemodule visibility to initial status.
            set_coursemodule_visible($cmid, $initialvisible, $initialvisibleoncoursepage);
        }

        return parent::delete();
    }
}
