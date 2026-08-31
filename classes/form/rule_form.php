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

namespace local_coursedynamicrules\form;

/**
 * Class rule_form
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    /**
     * Defines the form.
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $courseid = $customdata['courseid'];

        // A rule being CREATED has no record yet: editrule.php hands this an empty stdClass, so every
        // read below has to tolerate a property that is not there. Reading them unguarded emitted
        // three PHP warnings on the most-used screen in the plugin - invisible in production, fatal
        // under Behat, and the reason 18 acceptance scenarios could not run at all.
        $rule = $customdata['rule'];

        $mform->addElement('text', 'name', get_string('name', 'local_coursedynamicrules'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setDefault('name', $rule->name ?? '');

        $mform->addElement('textarea', 'description', get_string('description', 'local_coursedynamicrules'));
        $mform->setType('description', PARAM_RAW);
        $mform->setDefault('description', $rule->description ?? '');

        $mform->addElement('checkbox', 'active', get_string('ruleactive', 'local_coursedynamicrules'));
        $mform->setDefault('active', $rule->active ?? 0);
        $mform->addHelpButton('active', 'ruleactive', 'local_coursedynamicrules');

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'id', $rule->id ?? 0);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
