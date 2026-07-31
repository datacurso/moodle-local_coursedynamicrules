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

namespace local_coursedynamicrules\form\conditions;

use cm_info;
use context;
use context_course;
use core_form\dynamic_form;
use core_grades\component_gradeitems;
use grade_item;
use moodle_url;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/gradelib.php');

/**
 * Class dynamic_grade_in_activity_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamic_grade_in_activity_form extends dynamic_form {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $cmid = $this->optional_param('coursemodule', 0, PARAM_INT);
        $courseid = $this->optional_param('courseid', 0, PARAM_INT);

        $attr = $mform->getAttributes();
        $attr['id'] = 'dynamic_grade_in_activity_form';
        $attr['novalidate'] = true;
        $attr['class'] .= ' needs-validation';

        $mform->setAttributes($attr);

        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();
        $filteredcms = [];
        $options = [];
        foreach ($cms as $cm) {
            // Indicate when require grade is enable.
            // See get_moduleinfo_data funtion.
            $completionusegrade = !is_null($cm->completiongradeitemnumber);

            if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC && $completionusegrade && !$cm->deletioninprogress) {
                $options[$cm->id] = ucfirst($cm->modname) . " - " . $cm->name;
                $filteredcms[$cm->id] = $cm;
            }
        }

        // A stored cmid may no longer be eligible (activity deleted, or completion/grade settings
        // changed since the condition was saved): fall back to the first eligible activity instead
        // of indexing a missing key and dereferencing a null $cm.
        $cm = $filteredcms[$cmid] ?? (reset($filteredcms) ?: null);

        $attributes = [
            'multiple' => false,
            'noselectionstring' => get_string('allcourseactivitymodules', 'local_coursedynamicrules'),
        ];
        $mform->addElement(
            'autocomplete',
            'coursemodule',
            get_string('searchcourseactivitymodules', 'local_coursedynamicrules'),
            $options,
            $attributes
        );

        if ($cm) {
            $mform->setDefault('coursemodule', $cm->id);

            $component = 'mod_' . $cm->modname;

            // Get the itemnames mapping for the component. This is used to display the grade item names in the form.
            $itemnames = component_gradeitems::get_itemname_mapping_for_component($component);

            if (count($itemnames) === 1) {
                $options = [
                    'groupstring' => get_string('gradenoun'),
                ];
                $this->add_grade_elements($mform, $cm, 0, $options);
            } else if (count($itemnames) > 1) {
                foreach ($itemnames as $itemnumber => $itemname) {
                    $optiongroup = [
                        'groupstring' => get_string("grade_{$itemname}_name", $component),
                    ];
                    $this->add_grade_elements($mform, $cm, $itemnumber, $optiongroup);
                }
            }
        }
    }


    /**
     * Adds grade elements to the form.
     *
     * This function adds two groups of elements to the form: one for grades greater than or equal to a specified value,
     * and another for grades less than a specified value. Each group consists of a checkbox to enable the condition and
     * a text input to specify the grade value.
     *
     * @param MoodleQuickForm $mform The form to which the elements will be added.
     * @param cm_info $cm The course module object
     * @param int $itemnumber The item number for the grade item.
     * @param array $optiongroup An array containing the group string for the option group.
     */
    private function add_grade_elements($mform, $cm, $itemnumber, $optiongroup) {
        // Fetch the grade item based on the course module and item number.
        $gradeitem = grade_item::fetch(
            [
                'iteminstance' => $cm->instance,
                'itemmodule' => $cm->modname,
                'itemtype' => 'mod',
                'itemnumber' => $itemnumber,
            ]
        );

        $decimals = $gradeitem->get_decimals();
        $grademin = format_float($gradeitem->grademin, $decimals);
        $grademax = format_float($gradeitem->grademax, $decimals);

        // Load the scale for the grade item.
        $scale = $gradeitem->load_scale();

        $attributes = [
            'data-cmid' => $cm->id,
            'data-gradeitem' => $gradeitem->id,
            'data-grademin' => $grademin,
            'data-grademax' => $grademax,
        ];

        if ($scale) {
            $options = [];
            foreach ($scale->scale_items as $i => $name) {
                $options[$i + 1] = $name;
            }

            $identifier = 'gradegte_' . $gradeitem->id;
            $attributes['data-condition'] = 'gradegte';
            $attributes['id'] = $identifier;
            $elementgradegte = $mform->createElement(
                'select',
                $identifier,
                '',
                $options,
                $attributes
            );

            $identifier = 'gradelt_' . $gradeitem->id;
            $attributes['id'] = $identifier;
            $attributes['data-condition'] = 'gradelt';
            $elementgradelt = $mform->createElement(
                'select',
                $identifier,
                '',
                $options,
                $attributes
            );
        } else {
            $identifier = 'gradegte_' . $gradeitem->id;
            $attributes['id'] = $identifier;
            $attributes['data-condition'] = 'gradegte';
            $elementgradegte = $mform->createElement(
                'text',
                $identifier,
                '',
                $attributes
            );

            $identifier = 'gradelt_' . $gradeitem->id;
            $attributes['id'] = $identifier;
            $attributes['data-condition'] = 'gradelt';
            $elementgradelt = $mform->createElement(
                'text',
                $identifier,
                '',
                $attributes
            );
        }

        // Create elements for "grade greater than or equal" condition.
        $gradegreatergroup = [];
        $gradegreatergroup[] = $mform->createElement(
            'advcheckbox',
            'enablegradegte_' . $gradeitem->id,
            '',
            get_string('gradegreaterthanorequal', 'local_coursedynamicrules'),
        );
        $gradegreatergroup[] = $elementgradegte;
        $groupstring = $optiongroup['groupstring'];
        $groupstring .= ' (' . $grademin . ' - ' . $grademax . ')';
        $mform->addGroup(
            $gradegreatergroup,
            'gradegtegroup_' . $gradeitem->id,
            $groupstring,
            ' ',
            false
        );
        $mform->addHelpButton('gradegtegroup_' . $gradeitem->id, 'gradegreaterthanorequal', 'local_coursedynamicrules');
        $mform->disabledIf('gradegte_' . $gradeitem->id, 'enablegradegte_' . $gradeitem->id, 'notchecked');
        $mform->setType('gradegte_' . $gradeitem->id, PARAM_FLOAT);

        // Create elements for "grade less than" condition.
        $gradelessgroup = [];
        $gradelessgroup[] = $mform->createElement(
            'advcheckbox',
            'enablegradelt_' . $gradeitem->id,
            '',
            get_string('gradelessthan', 'local_coursedynamicrules'),
        );
        $gradelessgroup[] = $elementgradelt;
        $mform->addGroup($gradelessgroup, 'gradeltgroup_' . $gradeitem->id, '', ' ', false);
        $mform->addHelpButton('gradeltgroup_' . $gradeitem->id, 'gradelessthan', 'local_coursedynamicrules');
        $mform->disabledIf('gradelt_' . $gradeitem->id, 'enablegradelt_' . $gradeitem->id, 'notchecked');
        $mform->setType('gradelt_' . $gradeitem->id, PARAM_FLOAT);
    }

    /**
     * Returns context where this form is used
     *
     * This context is validated in {@see \external_api::validate_context()}
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * Example:
     *     $cmid = $this->optional_param('cmid', 0, PARAM_INT);
     *     return context_module::instance($cmid);
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $courseid = $this->optional_param('courseid', 0, PARAM_INT);
        return context_course::instance($courseid);
    }

    /**
     * Checks if current user has access to this form, otherwise throws exception
     *
     * Sometimes permission check may depend on the action and/or id of the entity.
     * If necessary, form data is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * Example:
     *     require_capability('dosomething', $this->get_context_for_dynamic_submission());
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/coursedynamicrules:managecondition', $this->get_context_for_dynamic_submission());
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * Example:
     *     $data = $this->get_data();
     *     file_postupdate_standard_filemanager($data, ....);
     *     api::save_entity($data); // Save into the DB, trigger event, etc.
     *
     * @return mixed
     */
    public function process_dynamic_submission() {
        // No need to process the form submission.
        return [];
    }

    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $id = $this->optional_param('id', 0, PARAM_INT);
     *     $data = api::get_entity($id); // For example, retrieve a row from the DB.
     *     file_prepare_standard_filemanager($data, ...);
     *     $this->set_data($data);
     *
     * On edit, the outer grade_in_activity_form preloads the hidden 'cmid'/'gradeitems' fields from
     * the stored condition (D5); the AMD module passes their values here as the initial
     * dynamicForm.load() args, so this decodes 'gradeitems' and maps each stored key straight onto
     * its matching enable{cond}_{gid} checkbox and {cond}_{gid} value element name (the stored key
     * IS the value element's name).
     */
    public function set_data_for_dynamic_submission(): void {
        $gradeitems = json_decode($this->optional_param('gradeitems', '{}', PARAM_RAW), true);
        if (!is_array($gradeitems)) {
            return;
        }

        $defaults = [];
        foreach ($gradeitems as $key => $item) {
            if (!is_array($item) || !isset($item['value'])) {
                continue;
            }
            if (!empty($item['disabled'])) {
                // A deliberately disabled threshold (the AMD rebuild serialises disabled:true
                // entries) must stay unchecked on a failed-validation redisplay, not be
                // force-re-enabled just because it still carries a stored value (FIX2-7).
                continue;
            }
            $defaults['enable' . $key] = 1;
            $defaults[$key] = $item['value'];
        }

        if (!empty($defaults)) {
            $this->set_data((object) $defaults);
        }
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * Example:
     *     $id = $this->optional_param('id', 0, PARAM_INT);
     *     return new moodle_url('/my/page/where/form/is/used.php', ['id' => $id]);
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/test/exampledynamicform.php', []);
    }
}
