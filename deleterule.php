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

/**
 * TODO describe file deleterule
 *
 * @package    local_coursedynamicrules
 * @copyright  2024 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursedynamicrules\core\rule;
use local_coursedynamicrules\helper\component_renderer;

require('../../config.php');

require_login();


$id = required_param('id', PARAM_INT); // Rule ID.
$delete = optional_param('delete', '', PARAM_ALPHANUM); // Confirmation hash.
$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/coursedynamicrules:deleterule', $context);

$url = new moodle_url('/local/coursedynamicrules/deleterule.php', ['delete' => $delete, 'courseid' => $courseid]);
$rulesurl = new moodle_url('/local/coursedynamicrules/rules.php', ['courseid' => $courseid]);

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

// Ensure the rule belongs to this course before loading it (prevents cross-course deletion).
$rule = \local_coursedynamicrules\helper\ownership::get_rule($id, $courseid);

// Escaped once, here, because both outputs below put this name into HTML and neither escapes it
// for us: core_renderer::confirm() emits its message through html_writer::tag('p', ...) untouched
// (lib/classes/output/core_renderer.php:1769). The name is user text and the form is not its only
// writer - course restore inserts it with no cleaning at all
// (restore_local_coursedynamicrules_plugin.class.php:95) - so a rule restored from a crafted
// backup carries whatever its author put in the name. This page was the one member of the delete
// family left unescaped: deletecondition.php and deleteaction.php go through
// component_renderer::escaped_description(), the listing through descriptions_html(), and the
// component pages through Mustache's {{description}}.
$rulename = component_renderer::escaped_name($rule->name, $context);

$config = get_config('local_coursedynamicrules');

if ($delete === md5($config->confirmdeleterule ?? '')) {
    require_sesskey();

    // Delete rule.
    $ruleinstance = new rule($rule, $courseid);
    $ruleinstance->delete();

    echo $OUTPUT->notification(
        get_string("deletedrule", "local_coursedynamicrules", $rulename),
        'notifysuccess',
        false
    );
    echo $OUTPUT->continue_button($rulesurl);
    echo $OUTPUT->footer();

    exit; // We must exit here!!!
}

$strdeleterulecheck = get_string("deleterulecheck", "local_coursedynamicrules");
$message = "{$strdeleterulecheck}<br /><br />{$rulename}";

// Generate ramdom token for validation delete action.
$confirmdeleterule = time() . md5(mt_rand(100000000, mt_getrandmax()));
set_config('confirmdeleterule', $confirmdeleterule, 'local_coursedynamicrules');


$continueurl = new moodle_url(
    '/local/coursedynamicrules/deleterule.php',
    [
        'id' => $rule->id,
        'delete' => md5($confirmdeleterule),
        'courseid' => $courseid,
    ]
);

$continuebutton = new single_button(
    $continueurl,
    get_string('delete'),
    'post',
    single_button::BUTTON_SECONDARY,
    ['data-action' => 'delete']
);
echo $OUTPUT->confirm($message, $continuebutton, $rulesurl);

// In the following script, we need to use setTimeout as disabling the
// button in the event listener script prevent the click to be taken into account.
$jsscript = <<<EOF
const button = document.querySelector('button[data-action="delete"]');
if (button) {
button.addEventListener('click', () => {
    setTimeout(() => {
        button.disabled = true;
    }, 0);
});
}
EOF;
$PAGE->requires->js_amd_inline($jsscript);

echo $OUTPUT->footer();
