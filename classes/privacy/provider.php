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

namespace local_coursedynamicrules\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for local_coursedynamicrules.
 *
 * The plugin stores no personal data in its own tables (rules, conditions and actions hold course
 * configuration only), so it implements no data store. It does, however, transfer course and user
 * context to an external AI service when the "create AI activity" action runs, which is declared
 * here as an external location so administrators can account for it.
 *
 * Two limits of this class are stated plainly rather than implied, because an earlier version of
 * this docblock implied the opposite:
 *
 * 1. Messages and adhoc tasks are core subsystems that declare their own privacy metadata, so they
 *    are correctly absent here. Activity availability is NOT: the enable-activity and create-AI-
 *    activity actions write a user id into {course_modules}.availability, and availability_user is
 *    a null_provider - it declares no personal data (availability/condition/user/classes/privacy/
 *    provider.php). Those ids are therefore declared by nobody. What removes them today is an
 *    event observer on user deletion (classes/observer/user_deleted.php), and that observer covers
 *    the enable-activity action ONLY, so an id written by the create-AI-activity action survives
 *    the user being deleted. CHANGES.md records this under "Privacy exports".
 *
 * 2. This class implements the metadata provider only: it discloses, it does not export or erase.
 *    Under the Privacy API that has two consequences worth stating exactly. Core does not count the
 *    plugin as compliant (privacy/classes/manager.php:143-160 requires a data provider as well),
 *    and the plugin privacy registry therefore lists it as non-compliant WITHOUT rendering the
 *    declaration below (admin/tool/dataprivacy/classes/metadata_registry.php:58-73 formats the
 *    collection of compliant components only). And an approved deletion request runs none of this
 *    class - but it is not true that it runs nothing of this plugin: it ends by deleting the
 *    account (admin/tool/dataprivacy/classes/task/process_data_request_task.php:297), which fires
 *    user_deleted and runs the observer in point 1, for the enable-activity action only.
 *
 * Both are open gaps, not decisions. They are named so that the next reader does not have to
 * rediscover them from the code the way a blind review had to.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the personal data leaving Moodle for external processing.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        // These are the KEYS of the /activity/init payload built by
        // createaiactivity_action::execute(), not a prose description of it: a field named here
        // that the service never receives misdescribes the transfer just as badly as an omission,
        // and external_transfer_declaration_test.php compares both directions against the request
        // the action really sends. The three payload keys left out are operational controls of
        // the request rather than data about a person - with_images (configured per action, so it
        // varies between actions, but it names nobody), auto_approve (always true, since cron has
        // nobody to approve a plan) and service_id (the calling plugin's billing identity). That
        // is this plugin's own classification, pinned in the same test; it is not a legal one.
        //
        // Course name and course URL are deliberately NOT separate entries. They reach the service
        // only inside `instructions`, and only when the teacher writes {$a->coursename} or
        // {$a->courseurl} in the prompt (build_prompt() substitutes them), so declaring them as
        // fields of their own would claim a channel that does not exist. Their travel is disclosed
        // in the `instructions` string instead, which is where it is literally true.
        $collection->add_external_location_link(
            'datacurso_ai',
            [
                'instructions' => 'privacy:metadata:datacurso_ai:instructions',
                'lang' => 'privacy:metadata:datacurso_ai:lang',
                'site_url' => 'privacy:metadata:datacurso_ai:site_url',
                'userid' => 'privacy:metadata:datacurso_ai:userid',
            ],
            'privacy:metadata:datacurso_ai'
        );

        return $collection;
    }
}
