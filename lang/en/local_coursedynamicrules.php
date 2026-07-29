<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_coursedynamicrules
 * @category    string
 * @copyright   2024 Industria Elearning <info@industriaelearning.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['actions_help'] = 'Actions are used to define the actions that will be executed when the rule conditions are met';
$string['addactions'] = 'Add actions';
$string['addconditions'] = 'Add conditions';
$string['after'] = 'After';
$string['allcourseactivitymodules'] = 'All course activity modules';
$string['availableplaceholders'] = 'Available placeholders';
$string['backtolistrules'] = 'Back to list rules';
$string['basedate'] = 'Base date';
$string['basedate_help'] = 'Choose the reference date for evaluating inactivity:

* **From enrolment date**: Calculates from when the user enrolled.
* **From course start date**: Calculates from the course start date.
* **From now**: Calculates from the current date.';
$string['before'] = 'Before';
$string['checklicensekey'] = 'Check licence key';
$string['complete_activity'] = 'Activity completed';
$string['complete_activity_condition_info'] = 'This condition will check which user has completed the selected activity module.';
$string['complete_activity_description'] = 'Users who have completed the course activity module \'{$a->moddescription}\'';
$string['completiondate'] = 'Completion date';
$string['conditions'] = 'Conditions';
$string['conditions_help'] = 'Conditions define the criteria that must be met to run the actions of the rule. All conditions in a rule must be met (AND). To run the actions when at least one of several criteria is met (OR), create a separate rule for each criterion with the same actions.';
$string['copiedtoclipboard'] = 'Copied to clipboard';
$string['copyrecipients'] = 'Copy recipients';
$string['copyrecipients_help'] = 'Select which roles receive an informational copy when the condition is met for a primary recipient.';
$string['copytoclipboard'] = 'Copy to clipboard';
$string['course_inactivity'] = 'Course inactivity in time intervals';
$string['course_inactivity_custom_description'] = 'Users without activity in the course for intervals of {$a->intervals} {$a->unit} from {$a->basedate}';
$string['course_inactivity_info'] = 'This condition will check which users have had no activity in the course within the specified time intervals.';
$string['course_inactivity_recurring_description'] = 'Users without activity in the course at recurring intervals of {$a->intervals} {$a->unit} from {$a->basedate}';
$string['course_inactivity_task'] = 'Course inactivity task';
$string['coursedynamicrules:createaction'] = 'Create actions';
$string['coursedynamicrules:createcondition'] = 'Create conditions';
$string['coursedynamicrules:createrule'] = 'Create rules';
$string['coursedynamicrules:deleteaction'] = 'Delete actions';
$string['coursedynamicrules:deletecondition'] = 'Delete conditions';
$string['coursedynamicrules:deleterule'] = 'Delete rules';
$string['coursedynamicrules:manageaction'] = 'Manage actions';
$string['coursedynamicrules:managecondition'] = 'Manage conditions';
$string['coursedynamicrules:managerule'] = 'Manage rules';
$string['coursedynamicrules:notification'] = 'Send notification';
$string['coursedynamicrules:updateaction'] = 'Update actions';
$string['coursedynamicrules:updatecondition'] = 'Update conditions';
$string['coursedynamicrules:updaterule'] = 'Update rules';
$string['coursedynamicrules:viewaction'] = 'View actions';
$string['coursedynamicrules:viewcondition'] = 'View conditions';
$string['coursedynamicrules:viewrule'] = 'View rules';
$string['courselink'] = 'Course link';
$string['coursemoduleelementnotfound'] = 'Course module select element not found.';
$string['coursename'] = 'Course name';
$string['coursestartdate'] = 'Course start date';
$string['createaiactivity'] = 'Create AI reinforcement activity';
$string['createaiactivity_action_info'] = 'This action will request the Datacurso AI service to generate a personalised reinforcement activity for users who meet the rule conditions.';
$string['createaiactivity_beforemod'] = 'Place before activity';
$string['createaiactivity_beforemod_help'] = 'Select the activity that the new resource should precede, or keep the default option to add it at the end of the section.';
$string['createaiactivity_beforemod_none'] = 'Do not position before another activity';
$string['createaiactivity_description'] = 'Generate an AI reinforcement activity in section "{$a->section}" using prompt "{$a->prompt}"';
$string['createaiactivity_description_beforemod'] = 'It will be inserted before "{$a}".';
$string['createaiactivity_description_generateimages'] = 'Image generation: {$a}.';
$string['createaiactivity_generateimages'] = 'Generate images with AI';
$string['createaiactivity_generateimages_label'] = 'Allow the AI to include generated images when supported.';
$string['createaiactivity_placeholders_info'] = 'Available placeholders: <code>{$a->coursename}</code>, <code>{$a->courseurl}</code>, <code>{$a->fullname}</code>, <code>{$a->firstname}</code>, <code>{$a->lastname}</code>.';
$string['createaiactivity_prompt'] = 'AI prompt';
$string['createaiactivity_prompt_help'] = 'Write the instruction that will be sent to the AI service. You can include placeholders that will be replaced before sending the prompt.';
$string['createaiactivity_section'] = 'Course section';
$string['createrule'] = 'Create Rule';
$string['customintervals'] = 'Custom intervals';
$string['customintervals_help'] = 'Enter whole numbers greater than 0 in ascending order, separated by commas (e.g., "7,14,30"). Each number is an inactivity checkpoint measured from the base date.';
$string['datacurso'] = 'Datacurso';
$string['datacurso_brand_alt'] = 'Datacurso logo';
$string['date_from_course_start'] = 'From course start date';
$string['date_from_enrollment'] = 'From enrolment date';
$string['date_from_now'] = 'From now';
$string['days'] = 'Days';
$string['deleteactioncheck'] = 'Are you absolutely sure you want to completely delete this action?';
$string['deletecondition'] = 'Delete condition';
$string['deleteconditioncheck'] = 'Are you absolutely sure you want to completely delete this condition?';
$string['deletedaction'] = 'Deleted action <b>{$a}</b>';
$string['deletedcondition'] = 'Deleted condition <b>{$a}</b>';
$string['deletedrule'] = 'Deleted rule <b>{$a}</b>';
$string['deleterule'] = 'Delete rule';
$string['deleterulecheck'] = 'Are you absolutely sure you want to completely delete this rule?';
$string['deletingcondition'] = 'Deleting condition \'{$a}\'';
$string['deletingrule'] = 'Deleting rule \'{$a}\'';
$string['description'] = 'Description';
$string['editactions'] = 'Edit actions';
$string['editconditions'] = 'Edit conditions';
$string['editrule'] = 'Edit rule';
$string['enableactivity'] = 'Enable activity';
$string['enableactivity_action_info'] = 'This action will enable selected activities modules for users who meet the rule conditions criteria.';
$string['enableactivity_description'] = 'Enable activities \'{$a}\'';
$string['enablegradegreaterthanorequal_help'] = 'Enable grade greater than or equal to';
$string['enablegradelessthan'] = 'Enable grade less than';
$string['enrollmentdate'] = 'Enrollment date';
$string['error_empty_aiactivity_prompt'] = 'Create AI activity action executed without a valid prompt message.';
$string['error_required_local_coursegen'] = 'The plugin local_coursegen is required to execute the Create AI activity action.';
$string['error_unexpected_creating_aiactivity'] = 'Unexpected error while creating AI reinforcement activity: {$a}';
$string['errorcustomintervals'] = 'Custom intervals must be positive whole numbers in ascending order, separated by commas (e.g. 7,14,30).';
$string['errorgradeoutofrange'] = 'The value must be between {$a->min} and {$a->max}.';
$string['errorinvalidgradeitems'] = 'The grade conditions data is invalid. Please reload the page and configure the condition again.';
$string['errormaxgradeexceeded'] = 'The grade cannot exceed the maximum grade for the activity.';
$string['errornegativegrade'] = 'The grade must be 0 or greater.';
$string['errornocoursemodule'] = 'You must select an activity from this course.';
$string['errornocoursestart'] = 'This course has no start date, so "from course start" cannot be used. Set a course start date or choose a different base date.';
$string['errornogradeconditions'] = 'Enable at least one grade condition and enter a grade value.';
$string['errorperiodvalue'] = 'The period must be a whole number greater than 0.';
$string['errorrecurringinterval'] = 'The recurring interval must be a whole number greater than 0.';
$string['event:action_created'] = 'Dynamic rule action created';
$string['event:action_deleted'] = 'Dynamic rule action deleted';
$string['event:condition_created'] = 'Dynamic rule condition created';
$string['event:condition_deleted'] = 'Dynamic rule condition deleted';
$string['event:rule_created'] = 'Dynamic rule created';
$string['event:rule_deleted'] = 'Dynamic rule deleted';
$string['event:rule_updated'] = 'Dynamic rule updated';
$string['expectedcompletiondate'] = 'Expected completion date';
$string['firstname'] = 'User firstname';
$string['fullname'] = 'User fullname';
$string['generalsettings'] = 'General settings';
$string['grade'] = 'Grade';
$string['grade_in_activity'] = 'Grade in activity';
$string['grade_in_activity_condition_info'] = 'This condition will check which user has obtained the specified grade in the selected activity module.';
$string['grade_in_activity_description'] = 'For "{$a->moddescription}", the following grades must be obtained: {$a->gradestring}';
$string['gradegreaterthanorequal'] = 'must be &#x2265;';
$string['gradegreaterthanorequal_help'] = 'The condition is met if the user\'s grade is greater than or equal to the specified value.';
$string['gradegreaterthanorequalvalue'] = '&#x2265; {$a}';
$string['gradelessthan'] = 'must be <';
$string['gradelessthan_help'] = 'The condition is met if the user\'s grade is less than the specified value.';
$string['gradelessthanvalue'] = '< {$a}';
$string['hours'] = 'Hours';
$string['intervaltype'] = 'Interval type';
$string['intervaltype_help'] = 'Select how the interval will be evaluated:

* **Custom intervals**: To add comma-separated values (e.g., 7,14,30) to evaluate inactivity at specific time points.
* **Recurring interval**: To evaluate inactivity at recurring intervals (e.g., every 7 days).';
$string['intervalunit'] = 'Time unit';
$string['intervalunit_help'] = 'Select the unit of time for the intervals.';
$string['invalidbasedate'] = 'Invalid base date type {$a}';
$string['invalidruleid'] = 'Invalid rule id';
$string['lastname'] = 'User lastname';
$string['licensekey'] = 'License Key';
$string['licensekey_desc'] = 'License key required to use this plugin';
$string['licensekeycompany'] = 'License Key for: {$a}';
$string['licensekeycompany_desc'] = 'License key required to use this plugin for company: {$a}';
$string['licensekeyinvalid'] = 'License key has expired or is invalid. Please go to <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> to renew or purchase a new license.';
$string['licensekeyvalid'] = 'License key is valid';
$string['messagebody'] = 'Body';
$string['messagebody_help'] = 'The following placeholders may be included in the message:

* Course name {$a->coursename}
* User fullname {$a->fullname}
* User firstname {$a->firstname}
* User lastname {$a->lastname}
* Course activity module name {$a->modulename}
* Course activity module instance name {$a->moduleinstancename}';
$string['messageprovider:smart_rules_ai_notification'] = 'Smart Rules AI notification';
$string['messagesubject'] = 'Subject';
$string['minutes'] = 'Minutes';
$string['missing_plugins_warning'] = '🔔 Enhance your notifications! Our <strong>Datacurso Message Hub</strong> plugins let you send notifications via WhatsApp and SMS using providers like Twilio.
<br>
<a href="https://shop.datacurso.com/clientarea.php" target="_blank">Click here to purchase and enable them now!</a>';
$string['moduleinstancename'] = 'Course activity module instance name';
$string['modulename'] = 'Course activity module name';
$string['months'] = 'Months';
$string['mustselectoneobservedrole'] = 'You must select at least one observed role.';
$string['mustselectoneprimaryrole'] = 'You must select at least one primary recipient role.';
$string['mustselectonerole'] = 'You must select at least one role.';
$string['name'] = 'Name';
$string['no_complete_activity'] = 'Activity not completed';
$string['no_complete_activity_condition_info'] = 'This condition will check which user has not completed the selected activity module after the specified date.';
$string['no_complete_activity_description'] = 'Users who have not completed the course activity module \'{$a->moddescription}\' after {$a->expectedcompletiondate}';
$string['no_complete_activity_task'] = 'No complete activity task';
$string['no_course_access'] = 'No course access';
$string['no_course_access_condition_info'] = 'This condition will check which users have not accessed this course within the specified time period.';
$string['no_course_access_description'] = 'Users who take more than {$a->periodvalue} {$a->periodunit} without accessing this course.';
$string['no_course_access_task'] = 'No course access task';
$string['notification_action_info'] = 'This action will send a notification to users who meet the rule conditions criteria.';
$string['notificationtargeting'] = 'Notification targeting';
$string['notificationtargeting_help'] = 'Define who receives the primary notification and who receives an informational copy. If a condition is met for a user in the primary recipients roles, that user receives the primary notification and copy recipients receive an informational copy.';
$string['notificationtargeting_info'] = 'Define who receives the primary notification and who receives an informational copy. If a condition is met for a user in the primary recipients roles, that user receives the primary notification and copy recipients receive an informational copy.';
$string['now'] = 'Now';
$string['observer_notification_intro'] = 'Observation copy: this notification was generated for {$a} based on the rule conditions.';
$string['observer_notification_subject'] = 'Observation: {$a->fullname} received "{$a->subject}"';
$string['passgrade'] = 'Activity completion with passing grade';
$string['passgrade_condition_info'] = 'This condition will check which user has completed the selected activity module with a passing grade.';
$string['passgrade_description'] = 'Users who have completed the course activity module \'{$a}\' with a passing grade';
$string['period'] = 'Period';
$string['period_help'] = 'The minimum amount of time a user must go without accessing the course. Enter a whole number greater than 0.';
$string['plugin_disabled'] = 'This action requires the plugin <strong>{$a->pluginname}</strong> to be enabled. Please access to the <a href="{$a->enableurl}" target="_blank">{$a->enableurl}</a> page, search <strong>{$a->visiblename}</strong> and enable it.';
$string['plugin_missing'] = 'This action requires the plugin <strong>{$a->pluginname}</strong> to be installed and enabled. Please download it from <a href="{$a->downloadurl}" target="_blank">{$a->downloadurl}</a> and install it.';
$string['pluginname'] = 'Smart Rules AI';
$string['pluginnotavailable'] = 'This plugin is not available, because the product license has expired or is invalid. Please go to <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> to renew or purchase a new license.';
$string['primaryrecipients'] = 'Primary recipients';
$string['primaryrecipients_help'] = 'Select which roles receive the primary notification when the condition is met for a user in those roles.';
$string['privacy:metadata:datacurso_ai'] = 'Course context is sent to the Datacurso AI service to generate reinforcement activities.';
$string['privacy:metadata:datacurso_ai:courseid'] = 'The id of the course the reinforcement activity is generated for.';
$string['privacy:metadata:datacurso_ai:courseurl'] = 'The URL of the course, sent as context for the generated activity.';
$string['privacy:metadata:datacurso_ai:prompt'] = 'The configured prompt describing the reinforcement activity to generate.';
$string['privacy:metadata:datacurso_ai:userid'] = 'The id of the user the reinforcement activity is generated for.';
$string['provider_not_enabled_warning'] = 'Enable notifications with <strong>Datacurso Message Hub</strong> to this action to send notifications via WhatsApp and SMS using providers like Twilio.
You can enable it from <a href="{$a}" target="_blank">Notification settings</a> and searching <strong>Smart Rules AI notification</strong>.
<br>
<a href="https://docs.datacurso.com/index.php?title=Message_Hub" target="_blank">See documentation for more information.</a>';
$string['recurringinterval'] = 'Recurring interval';
$string['recurringinterval_help'] = 'Enter a whole number greater than 0 representing a recurring inactivity interval (e.g., "7" for every 7 days of inactivity).';
$string['rolestonotify'] = 'Roles to notify';
$string['rolestonotify_help'] = 'Select the roles the user must have to receive the notification. You must select at least one.';
$string['ruleactive'] = 'Active';
$string['ruleactive_help'] = 'Enable or disable the rule';
$string['ruleadd'] = 'Add rule';
$string['ruleaddedsuccessfully'] = 'Rule added successfully';
$string['ruleinactive'] = 'Inactive';
$string['rules'] = 'Rules';
$string['rules_help'] = 'Rules are used to define set of conditions and actions that will be executed';
$string['ruleupdatedsuccessfully'] = 'Rule updated successfully';
$string['searchcourseactivitymodules'] = 'Search course activity modules';
$string['selectanactivity'] = 'Select an activity';
$string['sendnotification'] = 'Send notification';
$string['sendnotification_description'] = 'Send notification \'{$a}\' to users';
$string['sendnotification_description_details'] = 'Recipients: {$a->primaryroles}. Copy to: {$a->copyroles}. Message: "{$a->body}"';
$string['typemissing'] = 'Missing value "type"';
$string['weeks'] = 'Weeks';
