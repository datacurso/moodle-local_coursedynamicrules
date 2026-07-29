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
use local_coursedynamicrules\helper\rule_component_loader;

require('../../config.php');

$id = required_param('id', PARAM_INT); // Action ID.
$delete = optional_param('delete', '', PARAM_ALPHANUM); // Confirmation hash.
$courseid = required_param('courseid', PARAM_INT);
$ruleid = required_param('ruleid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/coursedynamicrules:deleteaction', $context);

$url = new moodle_url(
    '/local/coursedynamicrules/deleteaction.php',
    ['id' => $id, 'delete' => $delete, 'courseid' => $courseid, 'ruleid' => $ruleid]
);
$actionsurl = new moodle_url('/local/coursedynamicrules/actions.php', ['courseid' => $courseid, 'ruleid' => $ruleid]);

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_course($course);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

// Ensure the action's rule belongs to this course before loading it.
$action = \local_coursedynamicrules\helper\ownership::get_action($id, $courseid);

$config = get_config('local_coursedynamicrules');

$actioninstance = rule_component_loader::create_action_instance($action, $courseid);
$description = component_renderer::escaped_description($actioninstance);

if ($delete === md5($config->confirmdeleteaction ?? '')) {
    require_sesskey();

    // Delete action.
    $actioninstance->delete();

    echo $OUTPUT->notification(
        get_string("deletedaction", "local_coursedynamicrules", $description),
        'notifysuccess',
        false
    );
    echo $OUTPUT->continue_button($actionsurl);
    echo $OUTPUT->footer();
    exit; // We must exit here!!!
}

$strdeleteactioncheck = get_string("deleteactioncheck", "local_coursedynamicrules");
$message = "{$strdeleteactioncheck}<br /><br />{$description}";

// Generate ramdom token for validation delete action.
$confirmdeleteaction = time() . md5(mt_rand(100000000, mt_getrandmax()));
set_config('confirmdeleteaction', $confirmdeleteaction, 'local_coursedynamicrules');
$hashdelete = md5($confirmdeleteaction);

$continueurl = new moodle_url(
    '/local/coursedynamicrules/deleteaction.php',
    [
        'id' => $id,
        'delete' => $hashdelete,
        'courseid' => $courseid,
        'ruleid' => $ruleid,
    ]
);

$continuebutton = new single_button(
    $continueurl,
    get_string('delete'),
    'post',
    false,
    ['data-action' => 'delete']
);
echo $OUTPUT->confirm($message, $continuebutton, $actionsurl);

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
