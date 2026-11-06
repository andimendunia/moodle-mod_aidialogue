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

namespace mod_aidialogue\local;

/**
 * All database operations for sessions, turns, and criterion results.
 *
 * This class owns every read and write to:
 *   - aidialogue_session
 *   - aidialogue_turn
 *   - aidialogue_criterion_result
 *
 * No AI logic lives here — this is pure DB access. The dialogue_engine
 * orchestrates calls to this class.
 *
 * Turn move values (stored in aidialogue_turn.move):
 *   null            — student turns always have a null move
 *   session_open    — AI greeting + first criterion intro
 *   criterion_open  — AI introduces a new criterion mid-session
 *   probe_deeper    — AI asks student to elaborate or provide more detail
 *   probe_clarify   — AI asks student to clarify a vague or ambiguous statement
 *   criterion_close — AI closes the current criterion and transitions to next
 *   session_close   — AI delivers closing message; session is now complete
 *
 * Criterion result status values (stored in aidialogue_criterion_result.status):
 *   pending     — not yet reached in conversation
 *   in_progress — currently being discussed
 *   met         — student demonstrated the required evidence
 *   partial     — some evidence shown but incomplete
 *   limit       — max turns reached without meeting criterion
 *
 * Session status values (stored in aidialogue_session.status):
 *   pending  — created but student hasn't sent the first message yet
 *   active   — conversation is in progress
 *   complete — session has been closed and reports generated
 *
 * @package    mod_aidialogue
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_manager {
    // Session operations.

    /**
     * Create a new session for a student on an activity.
     *
     * Also pre-inserts one aidialogue_criterion_result row per criterion with
     * status='pending'. This gives us clean queryable rows from the start and
     * avoids implicit states (i.e. "no row = pending").
     *
     * @param int              $aidialogueid  Activity instance ID.
     * @param int              $userid        Student's Moodle user ID.
     * @param activity_config  $config        Activity config (needed for criteria list).
     * @return \stdClass  The newly created session record.
     * @throws \moodle_exception If maxattempts is exceeded.
     */
    public function create_session(int $aidialogueid, int $userid, activity_config $config): \stdClass {
        global $DB;

        // Wrap the attempt count, session insert, and criterion result inserts in a
        // single transaction so the whole set is atomic — a failure midway through
        // leaves no orphaned session row, and the count serialises against concurrent
        // create_session() calls for the same student.
        $transaction = $DB->start_delegated_transaction();

        $existing = $DB->count_records('aidialogue_session', [
            'aidialogueid' => $aidialogueid,
            'userid'       => $userid,
        ]);

        if ($config->maxattempts > 0 && $existing >= $config->maxattempts) {
            throw new \moodle_exception('error:maxattemptsreached', 'mod_aidialogue');
        }

        $attemptnumber = $existing + 1;

        $now = time();

        $session = new \stdClass();
        $session->aidialogueid  = $aidialogueid;
        $session->userid        = $userid;
        $session->attemptnumber = $attemptnumber;
        $session->status        = 'pending';
        $session->timecreated   = $now;
        $session->timemodified  = $now;

        $session->id = $DB->insert_record('aidialogue_session', $session);

        // Pre-insert criterion result rows.
        foreach ($config->criteria as $criterion) {
            $result = new \stdClass();
            $result->sessionid   = $session->id;
            $result->criterionid = $criterion->id;
            $result->status      = 'pending';
            $result->timecreated = $now;
            $result->timemodified = $now;
            $DB->insert_record('aidialogue_criterion_result', $result);
        }

        $transaction->allow_commit();

        return $session;
    }

    /**
     * Delete a session and its criterion_result rows.
     *
     * Used to clean up an orphaned pending session when start_session() fails
     * before the opening AI turn is stored.
     *
     * @param int $sessionid Session ID to delete.
     */
    public function delete_session(int $sessionid): void {
        global $DB;
        $DB->delete_records('aidialogue_criterion_result', ['sessionid' => $sessionid]);
        $DB->delete_records('aidialogue_turn', ['sessionid' => $sessionid]);
        $DB->delete_records('aidialogue_session', ['id'        => $sessionid]);
    }

    /**
     * Return the current active (or pending) session for a student, or null.
     *
     * "Active" means status is 'pending' or 'active' — i.e. not yet complete.
     *
     * @param int $aidialogueid Activity instance ID.
     * @param int $userid       Student's Moodle user ID.
     * @return \stdClass|null
     */
    public function get_active_session(int $aidialogueid, int $userid): ?\stdClass {
        global $DB;

        $sql = "SELECT *
                  FROM {aidialogue_session}
                 WHERE aidialogueid = :aidialogueid
                   AND userid = :userid
                   AND status IN ('pending', 'active')
              ORDER BY attemptnumber DESC";

        $records = $DB->get_records_sql($sql, [
            'aidialogueid' => $aidialogueid,
            'userid'       => $userid,
        ], 0, 1);

        return $records ? reset($records) : null;
    }

    /**
     * Return the most recently completed session for a student, or null.
     *
     * @param int $aidialogueid Activity instance ID.
     * @param int $userid       Student's Moodle user ID.
     * @return \stdClass|null
     */
    public function get_last_completed_session(int $aidialogueid, int $userid): ?\stdClass {
        global $DB;

        $sql = "SELECT *
                  FROM {aidialogue_session}
                 WHERE aidialogueid = :aidialogueid
                   AND userid = :userid
                   AND status = 'complete'
              ORDER BY attemptnumber DESC";

        $records = $DB->get_records_sql($sql, [
            'aidialogueid' => $aidialogueid,
            'userid'       => $userid,
        ], 0, 1);

        return $records ? reset($records) : null;
    }

    /**
     * Count the total number of attempts (any status) for a student on an activity.
     *
     * @param int $aidialogueid Activity instance ID.
     * @param int $userid       Student's Moodle user ID.
     * @return int
     */
    public function count_attempts(int $aidialogueid, int $userid): int {
        global $DB;
        return $DB->count_records('aidialogue_session', [
            'aidialogueid' => $aidialogueid,
            'userid'       => $userid,
        ]);
    }

    /**
     * Set session status to 'active' and record timestarted (first real turn).
     *
     * Only sets timestarted if it hasn't been set yet.
     *
     * @param int $sessionid Session ID.
     */
    public function activate_session(int $sessionid): void {
        global $DB;

        $session = $DB->get_record('aidialogue_session', ['id' => $sessionid], 'id, status, timestarted', MUST_EXIST);

        $update = new \stdClass();
        $update->id           = $sessionid;
        $update->status       = 'active';
        $update->timemodified = time();

        if (empty($session->timestarted)) {
            $update->timestarted = time();
        }

        $DB->update_record('aidialogue_session', $update);
    }

    /**
     * Close a session: set status='complete', store reports and AI grade.
     *
     * @param int    $sessionid     Session ID.
     * @param string $studentreport AI-generated feedback text for the student.
     * @param string $teacherreport AI-generated narrative summary for the teacher.
     * @param float  $aigrade       AI-suggested grade as a percentage (0-100).
     * @param bool   $earlyexit     True if the student manually ended the session early.
     */
    public function close_session(
        int $sessionid,
        string $studentreport,
        string $teacherreport,
        float $aigrade,
        bool $earlyexit = false,
    ): void {
        global $DB;

        $now = time();

        $update = new \stdClass();
        $update->id            = $sessionid;
        $update->status        = 'complete';
        $update->studentreport = $studentreport;
        $update->teacherreport = $teacherreport;
        $update->aigrade       = $aigrade;
        $update->earlyexit     = (int) $earlyexit;
        $update->timefinished  = $now;
        $update->timemodified  = $now;

        $DB->update_record('aidialogue_session', $update);

        $this->update_completion($sessionid);
    }

    /**
     * Save, change or clear the teacher's grade for a session, then update the gradebook and completion.
     *
     * The teacher's grade is the only grade that reaches the gradebook. Passing null clears it,
     * which removes the student's gradebook entry unless another attempt is still graded.
     *
     * @param int        $sessionid Session ID.
     * @param float|null $grade     Teacher grade as a percentage (0-100), or null to clear it.
     */
    public function save_teacher_grade(int $sessionid, ?float $grade): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/aidialogue/lib.php');

        $session = $DB->get_record('aidialogue_session', ['id' => $sessionid], '*', MUST_EXIST);

        $update = new \stdClass();
        $update->id           = $sessionid;
        $update->teachergrade = $grade === null ? null : max(0.0, min(100.0, $grade));
        $update->timemodified = time();
        $DB->update_record('aidialogue_session', $update);

        $aidialogue = $DB->get_record('aidialogue', ['id' => $session->aidialogueid], '*', MUST_EXIST);
        aidialogue_update_grades($aidialogue, $session->userid);

        $this->update_completion($sessionid);
    }

    /**
     * Ask Moodle to recalculate the student's activity completion after a session closed.
     *
     * Without this, completion would only refresh the next time the student views the activity.
     *
     * @param int $sessionid Session ID that was just closed.
     */
    private function update_completion(int $sessionid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $session = $DB->get_record('aidialogue_session', ['id' => $sessionid], 'aidialogueid, userid', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aidialogue', $session->aidialogueid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $completion = new \completion_info(get_course($cm->course));
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $session->userid);
        }
    }

    /**
     * Mark any pending or in_progress criterion results as 'abandoned'.
     *
     * Called before do_session_close() when the student exits early.
     *
     * @param int $sessionid Session ID.
     */
    public function abandon_remaining_criteria(int $sessionid): void {
        global $DB;
        $DB->execute(
            "UPDATE {aidialogue_criterion_result}
                SET status = 'abandoned', timemodified = :now
              WHERE sessionid = :sessionid
                AND status IN ('pending', 'in_progress')",
            ['now' => time(), 'sessionid' => $sessionid],
        );
    }

    // Turn operations.

    /**
     * Insert a new turn into the conversation transcript.
     *
     * Auto-calculates turnnumber as the next sequential value for the session.
     *
     * @param int      $sessionid   Session ID.
     * @param int|null $criterionid FK to aidialogue_criterion; null for session_open/close turns.
     * @param string   $role        'student' or 'ai'.
     * @param string|null $move     AI move type (null for student turns). One of:
     *                              session_open, criterion_open, probe_deeper,
     *                              probe_clarify, criterion_close, session_close.
     * @param string   $content     The message text.
     * @return \stdClass  The newly inserted turn record.
     */
    public function add_turn(
        int $sessionid,
        ?int $criterionid,
        string $role,
        ?string $move,
        string $content,
    ): \stdClass {
        global $DB;

        // Calculate next turnnumber.
        $max = $DB->get_field_sql(
            'SELECT COALESCE(MAX(turnnumber), 0) FROM {aidialogue_turn} WHERE sessionid = :sid',
            ['sid' => $sessionid],
        );

        $turn = new \stdClass();
        $turn->sessionid   = $sessionid;
        $turn->criterionid = $criterionid;
        $turn->role        = $role;
        $turn->move        = $move;
        $turn->content     = $content;
        $turn->turnnumber  = (int) $max + 1;
        $turn->timecreated = time();

        $turn->id = $DB->insert_record('aidialogue_turn', $turn);

        return $turn;
    }

    /**
     * Return all turns for a session, ordered by turnnumber ASC.
     *
     * @param int $sessionid Session ID.
     * @return \stdClass[]  Indexed array of turn records.
     */
    public function get_turns(int $sessionid): array {
        global $DB;
        return array_values(
            $DB->get_records('aidialogue_turn', ['sessionid' => $sessionid], 'turnnumber ASC')
        );
    }

    /**
     * Return turns for a specific criterion within a session, ordered by turnnumber ASC.
     *
     * Excludes session_open and session_close turns (which have criterionid = null).
     *
     * @param int $sessionid   Session ID.
     * @param int $criterionid Criterion ID.
     * @return \stdClass[]
     */
    public function get_turns_for_criterion(int $sessionid, int $criterionid): array {
        global $DB;
        return array_values(
            $DB->get_records('aidialogue_turn', [
                'sessionid'   => $sessionid,
                'criterionid' => $criterionid,
            ], 'turnnumber ASC')
        );
    }

    /**
     * Count the number of student turns for a specific criterion in a session.
     *
     * Used by the engine to decide if minturns/maxturns thresholds have been reached.
     *
     * @param int $sessionid   Session ID.
     * @param int $criterionid Criterion ID.
     * @return int
     */
    public function count_student_turns_for_criterion(int $sessionid, int $criterionid): int {
        global $DB;
        return $DB->count_records('aidialogue_turn', [
            'sessionid'   => $sessionid,
            'criterionid' => $criterionid,
            'role'        => 'student',
        ]);
    }

    // Criterion result operations.

    /**
     * Update the status (and optionally evidence) for a criterion result row.
     *
     * @param int         $sessionid   Session ID.
     * @param int         $criterionid Criterion ID.
     * @param string      $status      New status: in_progress, met, partial, limit.
     * @param string|null $evidence    Quoted excerpt from conversation (set on close).
     */
    public function update_criterion_result(
        int $sessionid,
        int $criterionid,
        string $status,
        ?string $evidence = null,
    ): void {
        global $DB;

        $record = $DB->get_record('aidialogue_criterion_result', [
            'sessionid'   => $sessionid,
            'criterionid' => $criterionid,
        ], 'id', MUST_EXIST);

        $update = new \stdClass();
        $update->id           = $record->id;
        $update->status       = $status;
        $update->timemodified = time();

        if ($evidence !== null) {
            $update->evidence = $evidence;
        }

        $DB->update_record('aidialogue_criterion_result', $update);
    }

    /**
     * Return all criterion result rows for a session, keyed by criterionid.
     *
     * @param int $sessionid Session ID.
     * @return \stdClass[]  Array keyed by criterionid.
     */
    public function get_criterion_results(int $sessionid): array {
        global $DB;
        $records = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $sessionid]);
        $keyed = [];
        foreach ($records as $r) {
            $keyed[(int) $r->criterionid] = $r;
        }
        return $keyed;
    }

    /**
     * Return true if all criterion results for the session have a terminal status
     * (i.e. met, partial, or limit — not pending or in_progress).
     *
     * @param int $sessionid Session ID.
     * @return bool
     */
    public function all_criteria_resolved(int $sessionid): bool {
        global $DB;

        $sql = "SELECT COUNT(*)
                  FROM {aidialogue_criterion_result}
                 WHERE sessionid = :sessionid
                   AND status IN ('pending', 'in_progress')";

        return $DB->count_records_sql($sql, ['sessionid' => $sessionid]) === 0;
    }

    /**
     * Return true if all criteria ended with status='met' (student passed everything).
     *
     * @param int $sessionid Session ID.
     * @return bool
     */
    public function all_criteria_met(int $sessionid): bool {
        global $DB;

        $total = $DB->count_records('aidialogue_criterion_result', ['sessionid' => $sessionid]);
        $met   = $DB->count_records('aidialogue_criterion_result', [
            'sessionid' => $sessionid,
            'status'    => 'met',
        ]);

        return $total > 0 && $met === $total;
    }
}
