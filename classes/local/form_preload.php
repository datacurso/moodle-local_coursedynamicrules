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

namespace local_coursedynamicrules\local;

use local_coursedynamicrules\action\sendnotification\sendnotification_action;
use local_coursedynamicrules\condition\course_inactivity\course_inactivity_condition;

/**
 * Pure mappers from a component's STORED params onto the defaults its edit form preloads.
 *
 * Extracted from the forms' protected preload_defaults() overrides so the mapping logic has a
 * public, instantiation-free API: the forms delegate here, and the tests exercise these mappers
 * directly instead of reaching into form internals via reflection. Every method is a pure
 * transformation - no database, no globals; the one input that used to need the database
 * (the course's assignable roles, for the notification form) is taken as a parameter.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_preload {
    /**
     * The base contract shared by every component form without an override: the stored params
     * preload verbatim, key by key.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function identity($params): array {
        $params = (object) $params;
        return (array) $params;
    }

    /**
     * Stored complete_activity params onto the 'coursemodule' autocomplete.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function complete_activity($params): array {
        $params = (object) $params;
        return ['coursemodule' => $params->cmid ?? null];
    }

    /**
     * Stored passgrade params onto the 'coursemodule' autocomplete.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function passgrade($params): array {
        $params = (object) $params;
        return ['coursemodule' => $params->cmid ?? null];
    }

    /**
     * Stored no_complete_activity params onto the activity picker and the date selector.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function no_complete_activity($params): array {
        $params = (object) $params;
        return [
            'coursemodule' => $params->cmid ?? null,
            'expectedcompletiondate' => $params->expectedcompletiondate ?? null,
        ];
    }

    /**
     * Stored course_inactivity params onto whichever interval text field matches the stored type.
     *
     * The stored 'timeintervals' value lands on 'customintervals' for the custom-list type and on
     * 'recurringinterval' otherwise, mirroring the conditional visibility of the two fields.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function course_inactivity($params): array {
        $params = (object) $params;
        $defaults = (array) $params;

        if (($params->intervaltype ?? null) === course_inactivity_condition::INTERVAL_CUSTOM) {
            $defaults['customintervals'] = $params->timeintervals ?? null;
        } else {
            $defaults['recurringinterval'] = $params->timeintervals ?? null;
        }

        return $defaults;
    }

    /**
     * Stored grade_in_activity params onto the hidden fields the AMD module reads (D5).
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function grade_in_activity($params): array {
        $params = (object) $params;
        return [
            'cmid' => $params->cmid ?? 0,
            'gradeitems' => json_encode($params->gradeitemsconditions ?? new \stdClass()),
        ];
    }

    /**
     * Stored enableactivity params onto the multi-select's plain id list.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function enableactivity($params): array {
        $params = (object) $params;
        return [
            'coursemodules' => array_map(
                fn($cm) => (int) $cm->id,
                $params->coursemodules ?? []
            ),
        ];
    }

    /**
     * Stored createaiactivity params onto the prompt, image toggle and placement fields.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @return array
     */
    public static function createaiactivity($params): array {
        $params = (object) $params;
        return [
            'message' => $params->message ?? '',
            'generateimages' => !empty($params->generateimages) ? 1 : 0,
            'sectionnum' => (int) ($params->sectionnum ?? 0),
            'beforemod' => (int) ($params->beforemod ?? 0),
        ];
    }

    /**
     * Stored sendnotification params onto subject, body and the two role checkbox groups.
     *
     * Every assignable role gets an EXPLICIT 0/1 so set_data() never falls back to a
     * setDefault() (the G3 re-checked-student bug): zero-fill by presence, not by truthiness.
     * The role list is a parameter - the form fetches the course's assignable roles and passes
     * their ids - keeping this mapper pure.
     *
     * @param object|array $params Decoded stored params (object, or empty array from json_decode).
     * @param int[] $courseroleids Ids of the roles assignable in the course.
     * @return array
     */
    public static function sendnotification($params, array $courseroleids): array {
        $params = (object) $params;
        $roleids = sendnotification_action::resolve_roleids($params);
        $primaryroleids = $roleids['primary'];
        $copyroleids = $roleids['copy'];

        $primaryrecipients = [];
        $copyrecipients = [];
        foreach ($courseroleids as $roleid) {
            $primaryrecipients[$roleid] = in_array($roleid, $primaryroleids) ? 1 : 0;
            $copyrecipients[$roleid] = in_array($roleid, $copyroleids) ? 1 : 0;
        }

        return [
            'messagesubject' => $params->messagesubject ?? '',
            'messagebody' => ['text' => $params->messagebody ?? '', 'format' => FORMAT_HTML],
            'primaryrecipients' => $primaryrecipients,
            'copyrecipients' => $copyrecipients,
        ];
    }
}
