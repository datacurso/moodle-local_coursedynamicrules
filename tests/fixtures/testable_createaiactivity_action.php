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

namespace local_coursedynamicrules\action\createaiactivity;

use aiprovider_datacurso\httpclient\ai_course_api;
use core\lock\lock;

/**
 * Testable subclass exposing injection seams for the AI HTTP client and the coursegen version lookup.
 *
 * @package    local_coursedynamicrules
 * @copyright  2026 Industria Elearning <info@industriaelearning.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class testable_createaiactivity_action extends createaiactivity_action {
    /** @var ai_course_api|null Client double returned by get_api_client() when set. */
    public static $client = null;

    /** @var array|null Base URLs captured on the last get_api_client() call. */
    public static $lasturls = null;

    /** @var int|null|false Coursegen version override; false keeps the real plugin manager lookup. */
    public static $coursegenversiondb = false;

    /** @var array Terminal stream event returned by read_activity_stream(). */
    public static $streamevent = [];

    /** @var string|null Stream URL captured on the last read_activity_stream() call. */
    public static $laststreamurl = null;

    /** @var bool When true, isolate_grades() throws, standing in for any gradebook failure. */
    public static $isolationthrows = false;

    /** @var bool When true, get_generation_lock() reports the lock held by another process. */
    public static $lockrefused = false;

    /** @var int How many times the lock was asked for, and the key of the last request. */
    public static $lockrequests = 0;

    /** @var string|null Resource key of the last lock request. */
    public static $lastlockkey = null;

    /**
     * Reset all static seams between tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$client = null;
        self::$lasturls = null;
        self::$coursegenversiondb = false;
        self::$streamevent = [];
        self::$laststreamurl = null;
        self::$isolationthrows = false;
        self::$lockrefused = false;
        self::$lockrequests = 0;
        self::$lastlockkey = null;
    }

    /**
     * Report the lock as held on demand, and record what production asked for.
     *
     * A seam rather than a real second acquisition, because there cannot be one: with mysqli the
     * factory is mysql_lock_factory and GET_LOCK is re-entrant inside a single database session, so
     * a test asking for the same resource key would be granted it and would prove the opposite of
     * what it set out to prove.
     *
     * The release in execute()'s finally is deliberately NOT wrapped here. Whether the lock was
     * given back is not observable from inside the process that holds it - GET_LOCK's state is
     * readable only through MySQL-specific SQL, which would make the assertion a claim about the
     * database engine rather than about this plugin.
     *
     * @param int $userid
     * @return lock|null
     */
    protected function get_generation_lock(int $userid): ?lock {
        self::$lockrequests++;
        self::$lastlockkey = $this->get_id() . '_' . $userid;

        if (self::$lockrefused) {
            return null;
        }

        return parent::get_generation_lock($userid);
    }

    /**
     * Fail the gradebook step on demand, so a test can check what survives it.
     *
     * @param int $courseid
     * @param object $newcm
     * @param string $mode
     * @param int $recipientuserid
     */
    protected function isolate_grades(int $courseid, $newcm, string $mode, int $recipientuserid = 0): void {
        if (self::$isolationthrows) {
            throw new \moodle_exception('error', 'debug', '', null, 'gradebook step failed');
        }
        parent::isolate_grades($courseid, $newcm, $mode, $recipientuserid);
    }

    /**
     * Return the injected client double when set, recording the URLs it was built with.
     *
     * @param string|null $baseurl Base URL override for the AI service.
     * @param string|null $baseurleu Base URL override for the EU AI service.
     * @return ai_course_api
     */
    protected function get_api_client(?string $baseurl, ?string $baseurleu): ai_course_api {
        self::$lasturls = ['baseurl' => $baseurl, 'baseurleu' => $baseurleu];
        if (self::$client !== null) {
            return self::$client;
        }
        return parent::get_api_client($baseurl, $baseurleu);
    }

    /**
     * Return the overridden coursegen version when set.
     *
     * @return int|null
     */
    protected function get_coursegen_versiondb(): ?int {
        if (self::$coursegenversiondb !== false) {
            return self::$coursegenversiondb;
        }
        return parent::get_coursegen_versiondb();
    }

    /**
     * Return the canned terminal stream event, recording the requested URL.
     *
     * @param string $streamurl Stream URL the action would consume.
     * @return array
     */
    protected function read_activity_stream(string $streamurl): array {
        self::$laststreamurl = $streamurl;
        return self::$streamevent;
    }
}
