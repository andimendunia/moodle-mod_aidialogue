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
 * Custom completion rules for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Muhammad Arnaldo <muhammad.arnaldo@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion for mod_aidialogue.
 *
 * Defines one rule, completionpassorexhaust. It is met when EITHER:
 *   - the student has a completed session where ALL criteria ended with status='met' (a pass), OR
 *   - maxattempts > 0 AND the student has finished all their attempts.
 * With unlimited attempts (maxattempts = 0) only a pass counts.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the completion state for a given completion rule.
     *
     * @param string $rule The completion rule identifier.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $aidialogue = $DB->get_record('aidialogue', ['id' => $this->cm->instance], '*', MUST_EXIST);

        if ($this->has_passed($aidialogue, $this->userid) || $this->has_exhausted($aidialogue, $this->userid)) {
            return COMPLETION_COMPLETE;
        }

        return COMPLETION_INCOMPLETE;
    }

    /**
     * Whether the user has at least one completed session with all criteria met.
     *
     * @param \stdClass $aidialogue Activity instance record.
     * @param int       $userid     User ID being evaluated.
     * @return bool
     */
    private function has_passed(\stdClass $aidialogue, int $userid): bool {
        global $DB;

        $sql = "SELECT COUNT(s.id)
                  FROM {aidialogue_session} s
                 WHERE s.aidialogueid = :aidialogueid
                   AND s.userid       = :userid
                   AND s.status       = 'complete'
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {aidialogue_criterion_result} cr
                            WHERE cr.sessionid = s.id
                              AND cr.status   != 'met'
                       )";

        return $DB->count_records_sql($sql, [
            'aidialogueid' => $aidialogue->id,
            'userid'       => $userid,
        ]) > 0;
    }

    /**
     * Whether the activity has an attempt limit and the user has finished that many attempts.
     *
     * Only finished (complete) sessions count, so an attempt still in progress does not
     * complete the activity early.
     *
     * @param \stdClass $aidialogue Activity instance record.
     * @param int       $userid     User ID being evaluated.
     * @return bool
     */
    private function has_exhausted(\stdClass $aidialogue, int $userid): bool {
        global $DB;

        if ($aidialogue->maxattempts <= 0) {
            return false;
        }

        $finished = $DB->count_records('aidialogue_session', [
            'aidialogueid' => $aidialogue->id,
            'userid'       => $userid,
            'status'       => 'complete',
        ]);

        return $finished >= $aidialogue->maxattempts;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionpassorexhaust'];
    }

    /**
     * Return the descriptions of the custom completion rules for display.
     *
     * @return string[] Rule key => localised description string.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionpassorexhaust' => get_string('completionpassorexhaust', 'aidialogue'),
        ];
    }

    /**
     * Return the sort order for completion rules, including core view tracking.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionpassorexhaust',
        ];
    }
}
