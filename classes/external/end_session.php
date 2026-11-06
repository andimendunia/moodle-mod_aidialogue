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
 * External function: end an active session early.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Muhammad Arnaldo <muhammad.arnaldo@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\dialogue_engine;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\session_manager;

/**
 * End an active dialogue session early at the student's request.
 */
class end_session extends external_api {
    /**
     * Describe the input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'Active session ID.'),
            'cmid'      => new external_value(PARAM_INT, 'Course module ID.'),
        ]);
    }

    /**
     * End the given session early.
     *
     * @param int $sessionid Active aidialogue_session.id.
     * @param int $cmid      Course module ID.
     * @return array{iscomplete: bool}
     * @throws \moodle_exception On session ownership/state errors or AI failure.
     */
    public static function execute(int $sessionid, int $cmid): array {
        global $DB, $USER;

        [
            'sessionid' => $sessionid,
            'cmid'      => $cmid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'cmid'      => $cmid,
        ]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aidialogue');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aidialogue:view', $context);

        $sessionmanager = new session_manager();
        $lock = $sessionmanager->get_session_lock($sessionid);

        try {
            $sessionrecord = $DB->get_record('aidialogue_session', ['id' => $sessionid], '*', IGNORE_MISSING);
            if (!$sessionrecord) {
                throw new \moodle_exception('error:sessionnotfound', 'mod_aidialogue');
            }
            if ((int) $sessionrecord->userid !== (int) $USER->id) {
                throw new \moodle_exception('error:sessionownership', 'mod_aidialogue');
            }
            if ((int) $sessionrecord->aidialogueid !== (int) $cm->instance) {
                throw new \moodle_exception('error:sessionnotfound', 'mod_aidialogue');
            }
            if ($sessionrecord->status === 'complete') {
                throw new \moodle_exception('error:sessionalreadycomplete', 'mod_aidialogue');
            }

            $config = activity_config::load_from_db($cm->instance);
            $engine = new dialogue_engine(
                $sessionmanager,
                new ai_client(),
                new prompt_builder(),
            );

            $engine->end_session_early($config, $sessionrecord);
        } finally {
            $lock->release();
        }

        return ['iscomplete' => true];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'iscomplete' => new external_value(PARAM_BOOL, 'Always true on success.'),
        ]);
    }
}
