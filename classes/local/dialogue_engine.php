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
 * Orchestrates the full conversation lifecycle for a single session.
 *
 * This class wires together activity_config, session_manager, ai_client,
 * and prompt_builder. It is the only place where the criterion loop logic lives.
 *
 * Public API (called from view.php and the external functions):
 *
 *   start_session()         — create session row, fire session_open AI turn
 *   process_student_turn()  — record student message, get AI response, advance state
 *   get_session_state()     — return all info needed to render the current UI state
 *
 * Internal flow for process_student_turn():
 *
 *   1. Record student turn.
 *   2. Determine current criterion (find first criterion_result with status
 *      'pending' or 'in_progress').
 *   3. Count student turns on this criterion.
 *   4. If at maxturns → force_close = true.
 *   5. Ask AI for probe/close response.
 *   6. Parse [MOVE:...] tag from AI response.
 *   7. Record AI turn with move.
 *   8. If move is criterion_close:
 *        a. Determine outcome (met / partial / limit) by re-asking AI.
 *        b. Update criterion_result.
 *        c. If more criteria remain → fire criterion_open AI turn.
 *        d. If no more criteria → fire session_close AI turn → generate reports → close session.
 *   9. Return the AI's visible response text + updated session state.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dialogue_engine {
    /**
     * Constructor.
     *
     * @param session_manager $sessionmanager Database access for sessions, turns, and results.
     * @param ai_client       $aiclient       Client for the AI chat completions endpoint.
     * @param prompt_builder  $promptbuilder  Builder for the AI prompt message arrays.
     */
    public function __construct(
        /** @var session_manager Database access for sessions, turns, and results. */
        private readonly session_manager $sessionmanager,
        /** @var ai_client Client for the AI chat completions endpoint. */
        private readonly ai_client $aiclient,
        /** @var prompt_builder Builder for the AI prompt message arrays. */
        private readonly prompt_builder $promptbuilder,
    ) {
    }

    // Public API.

    /**
     * Create a new session and fire the AI's opening turn.
     *
     * Returns the session record and the AI's opening message text.
     *
     * @param activity_config $config Activity config (stub or real).
     * @param int             $userid Student user ID.
     * @return array{session: \stdClass, opening_message: string}
     * @throws \moodle_exception On DB error or AI failure.
     */
    public function start_session(activity_config $config, int $userid): array {
        // Create DB rows.
        $session = $this->sessionmanager->create_session($config->id, $userid, $config);

        try {
            // Ask AI for the session_open message.
            $firstcriterion = $config->get_criterion(0);
            $messages = $this->promptbuilder->build_session_open_messages($config, $firstcriterion);
            $rawresponse = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);

            ['content' => $content] = $this->promptbuilder->parse_move($rawresponse);

            // The engine owns the stored move: never trust the tag the AI chose here.
            $this->sessionmanager->add_turn($session->id, null, 'ai', 'session_open', $content);
        } catch (\Throwable $e) {
            // AI call failed — delete the orphaned session so the student sees
            // the Start button again rather than an empty chat.
            $this->sessionmanager->delete_session($session->id);
            throw $e;
        }

        // Mark session active (timestarted not set yet — that's set on first student turn).
        // Status stays 'pending' until student sends the first message.
        // (activate_session is called in process_student_turn.)

        return [
            'session'          => $session,
            'opening_message'  => $content,
        ];
    }

    /**
     * Process a student's message and return the AI's response.
     *
     * This is the main conversation loop step. Called from the
     * submit_chat_message external function on every student message submission.
     *
     * @param activity_config $config    Activity config.
     * @param \stdClass       $session   Current session record.
     * @param string          $content   Student's message text.
     * @return array{
     *   ai_message:   string,
     *   move:         string,
     *   is_complete:  bool,
     * }
     * @throws \moodle_exception On DB error or AI failure.
     */
    public function process_student_turn(
        activity_config $config,
        \stdClass $session,
        string $content,
    ): array {
        $snapshot = $this->sessionmanager->get_criterion_results($session->id);
        $added = [];

        try {
            return $this->run_student_turn($config, $session, $content, $added);
        } catch (\Throwable $e) {
            // A failed turn must leave no trace, otherwise a retry would count the student's
            // reply twice and could leave a criterion announced as closed but still open.
            $this->undo_failed_turn((int) $session->id, $snapshot, $added);
            throw $e;
        }
    }

    /**
     * Run one student turn, recording the ids of every turn it adds so a failure can be undone.
     *
     * @param activity_config $config  Activity config.
     * @param \stdClass       $session  Current session record.
     * @param string          $content  Student's message text.
     * @param int[]           $added    Receives the ids of the turns added by this call.
     * @return array{ai_message: string, move: string, is_complete: bool}
     */
    private function run_student_turn(
        activity_config $config,
        \stdClass $session,
        string $content,
        array &$added,
    ): array {
        // Activate session on first student turn.
        if ($session->status === 'pending') {
            $this->sessionmanager->activate_session($session->id);
        }

        // Find the current criterion (first with status pending or in_progress).
        $currentcriterion = $this->find_current_criterion($config, $session->id);

        if ($currentcriterion === null) {
            // All criteria already resolved but session not yet closed — shouldn't
            // normally happen, but close defensively.
            return $this->do_session_close($config, $session, $added);
        }

        // Mark criterion in_progress if it was pending.
        $results = $this->sessionmanager->get_criterion_results($session->id);
        $crstatus = $results[$currentcriterion->id]->status ?? 'pending';
        if ($crstatus === 'pending') {
            $this->sessionmanager->update_criterion_result($session->id, $currentcriterion->id, 'in_progress');
        }

        // Record the student's turn.
        $this->record_turn($added, $session->id, $currentcriterion->id, 'student', null, $content);

        // Count student turns on this criterion (including the one just added).
        $studentturns = $this->sessionmanager->count_student_turns_for_criterion(
            $session->id,
            $currentcriterion->id,
        );

        // Fetch turns for this criterion to resolve sub-state and force-close rules.
        $criterionturns = $this->sessionmanager->get_turns_for_criterion(
            $session->id,
            $currentcriterion->id,
        );
        [
            'substate'   => $substate,
            'forceclose' => $forceclose,
            'allowlimit' => $allowlimit,
        ] = $this->resolve_substate($criterionturns, $currentcriterion, $studentturns);

        // Get all turns for the full conversation context.
        $allturns = $this->sessionmanager->get_turns($session->id);

        // Ask AI for its response.
        $messages = $this->promptbuilder->build_probe_messages(
            $config,
            $currentcriterion,
            $allturns,
            $studentturns,
            $forceclose,
            $substate,
        );
        // Enforce move rules and whitelists based on sub-state.
        if ($forceclose) {
            ['content' => $aicontent] = $this->promptbuilder->parse_move(
                $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages),
            );
            $move = 'criterion_close';
        } else if ($substate === prompt_builder::SUBSTATE_OFFER_CHOICE) {
            ['move' => $move, 'content' => $aicontent] = $this->request_move(
                $config,
                $messages,
                ['criterion_close', 'probe_scaffold'],
            );
            $move ??= 'probe_scaffold';
        } else if ($substate === prompt_builder::SUBSTATE_HANDLE_CHOICE) {
            ['move' => $move, 'content' => $aicontent] = $this->request_move(
                $config,
                $messages,
                ['criterion_close', 'criterion_skip', 'probe_hint'],
            );
            $move ??= 'probe_hint';
        } else {
            $rawresponse = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
            ['move' => $move, 'content' => $aicontent] = $this->promptbuilder->parse_move($rawresponse);
            if (!in_array($move, ['criterion_close', 'probe_deeper', 'probe_clarify'], true)) {
                $move = 'probe_deeper';
            }
        }

        // Record AI turn.
        $this->record_turn($added, $session->id, $currentcriterion->id, 'ai', $move, $aicontent);

        // Handle criterion_close and criterion_skip.
        if ($move === 'criterion_close' || $move === 'criterion_skip' || $forceclose) {
            if ($move === 'criterion_skip') {
                // The student chose to review first: the outcome is decided by the engine, not by the AI.
                $outcome = 'limit';
                $evidence = null;
            } else {
                $outcome = $this->evaluate_criterion_outcome(
                    $config,
                    $session,
                    $currentcriterion,
                    $allowlimit,
                );
                $evidence = $this->extract_evidence($config, $session->id, $currentcriterion);
            }
            $this->sessionmanager->update_criterion_result(
                $session->id,
                $currentcriterion->id,
                $outcome,
                $evidence,
            );

            // Find next criterion.
            $nextcriterion = $this->find_next_criterion($config, $session->id, $currentcriterion);

            if ($nextcriterion !== null) {
                // More criteria — fire criterion_open.
                $allturnsafter = $this->sessionmanager->get_turns($session->id);
                $openmessages   = $this->promptbuilder->build_criterion_open_messages(
                    $config,
                    $nextcriterion,
                    $allturnsafter,
                );
                $openraw = $this->aiclient->chat(
                    $config->aiurl,
                    $config->aiapikey,
                    $config->aimodel,
                    $openmessages,
                );
                ['content' => $opencontent] = $this->promptbuilder->parse_move($openraw);

                // The engine owns the stored move. A sub-state tag here would leak into the next criterion.
                $this->record_turn($added, $session->id, $nextcriterion->id, 'ai', 'criterion_open', $opencontent);

                // Return combined message: criterion close response + criterion open.
                return [
                    'ai_message'  => $aicontent . "\n\n" . $opencontent,
                    'move'        => 'criterion_open',
                    'is_complete' => false,
                ];
            }

            // No more criteria — close the session.
            return $this->do_session_close($config, $session, $added);
        }

        return [
            'ai_message'  => $aicontent,
            'move'        => $move,
            'is_complete' => false,
        ];
    }

    /**
     * Return all information needed to render the current view state.
     *
     * Called by view.php to determine which of the three UI states to show:
     *   - no_session  — no active or pending session; show "Start" button
     *   - active      — session in progress; show chat UI
     *   - complete    — session finished; show results screen
     *
     * @param activity_config $config    Activity config.
     * @param int             $userid    Student user ID.
     * @return array{
     *   state:           string,
     *   session:         \stdClass|null,
     *   turns:           array,
     *   attempt_count:   int,
     *   can_start:       bool,
     *   last_completed:  \stdClass|null,
     * }
     */
    public function get_session_state(activity_config $config, int $userid): array {
        $attemptcount  = $this->sessionmanager->count_attempts($config->id, $userid);
        $activesession = $this->sessionmanager->get_active_session($config->id, $userid);
        $lastcompleted = $this->sessionmanager->get_last_completed_session($config->id, $userid);

        $canstart = $config->maxattempts === 0 || $attemptcount < $config->maxattempts;

        if ($activesession !== null) {
            $turns = $this->sessionmanager->get_turns($activesession->id);
            return [
                'state'          => 'active',
                'session'        => $activesession,
                'turns'          => $turns,
                'attempt_count'  => $attemptcount,
                'can_start'      => false, // Already in a session.
                'last_completed' => $lastcompleted,
            ];
        }

        if ($lastcompleted !== null) {
            // Show results screen after any completed session.
            // Try Again button is conditionally rendered based on can_start.
            return [
                'state'          => 'complete',
                'session'        => $lastcompleted,
                'turns'          => [],
                'attempt_count'  => $attemptcount,
                'can_start'      => $canstart,
                'last_completed' => $lastcompleted,
            ];
        }

        return [
            'state'          => 'no_session',
            'session'        => null,
            'turns'          => [],
            'attempt_count'  => $attemptcount,
            'can_start'      => $canstart,
            'last_completed' => $lastcompleted,
        ];
    }

    // Private helpers.

    /**
     * End a session at the student's request before all criteria are completed.
     *
     * Marks incomplete criteria as 'abandoned', then runs the normal close
     * pipeline with the earlyexit flag set so prompts reflect the early exit.
     *
     * @param activity_config $config  Activity config.
     * @param \stdClass       $session Current session record.
     * @return array  Same shape as process_student_turn() return.
     */
    public function end_session_early(activity_config $config, \stdClass $session): array {
        $snapshot = $this->sessionmanager->get_criterion_results($session->id);
        $added = [];

        try {
            $this->sessionmanager->abandon_remaining_criteria($session->id);
            return $this->do_session_close($config, $session, $added, earlyexit: true);
        } catch (\Throwable $e) {
            $this->undo_failed_turn((int) $session->id, $snapshot, $added);
            throw $e;
        }
    }

    /**
     * Add a turn and remember its id so that a failed request can remove it again.
     *
     * @param int[]       $added       Receives the new turn id.
     * @param int         $sessionid   Session ID.
     * @param int|null    $criterionid Criterion ID, or null for session-level turns.
     * @param string      $role        'student' or 'ai'.
     * @param string|null $move        Move type, or null for student turns.
     * @param string      $content     Message text.
     */
    private function record_turn(
        array &$added,
        int $sessionid,
        ?int $criterionid,
        string $role,
        ?string $move,
        string $content,
    ): void {
        $added[] = (int) $this->sessionmanager->add_turn($sessionid, $criterionid, $role, $move, $content)->id;
    }

    /**
     * Remove the turns a failed request added and restore the criterion results it changed.
     *
     * @param int     $sessionid Session ID.
     * @param array   $snapshot  Criterion results before the request, keyed by criterion id.
     * @param int[]   $added     Ids of the turns the request added.
     */
    private function undo_failed_turn(int $sessionid, array $snapshot, array $added): void {
        try {
            $this->sessionmanager->delete_turns($added);
            $this->sessionmanager->restore_criterion_results($sessionid, $snapshot);
        } catch (\Throwable $undoerror) {
            debugging('Could not undo a failed dialogue turn: ' . $undoerror->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Ask the AI for a reply whose move tag must be one of the allowed moves.
     *
     * If the tag is missing or not allowed, the AI is asked once more to reissue the same reply with a
     * valid tag. Forcing a default tag instead would make the stored move contradict what the student
     * was just told. If the second reply is still invalid, the move is null and the caller falls back.
     *
     * @param activity_config $config  Activity config.
     * @param array           $messages Messages for the AI.
     * @param string[]        $allowed Allowed move types.
     * @return array{move: string|null, content: string}
     */
    private function request_move(activity_config $config, array $messages, array $allowed): array {
        $raw = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        $parsed = $this->promptbuilder->parse_move($raw);
        if (in_array($parsed['move'], $allowed, true)) {
            return $parsed;
        }

        $tags = implode(', ', array_map(static fn(string $move): string => "[MOVE:{$move}]", $allowed));
        $messages[] = ['role' => 'assistant', 'content' => $raw];
        $messages[] = [
            'role'    => 'user',
            'content' => 'Your last reply ended with a missing or invalid move tag. Reply again with the same '
                . "intent, ending with exactly one of: {$tags}.",
        ];
        $raw = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        $parsed = $this->promptbuilder->parse_move($raw);

        return [
            'move'    => in_array($parsed['move'], $allowed, true) ? $parsed['move'] : null,
            'content' => $parsed['content'],
        ];
    }

    /**
     * Fire session_close AI turn, generate reports, and close the session.
     *
     * @param activity_config $config    Activity config.
     * @param \stdClass       $session   Session record.
     * @param bool            $earlyexit True when the student manually ended the session.
     * @return array  Same shape as process_student_turn() return.
     */
    private function do_session_close(
        activity_config $config,
        \stdClass $session,
        array &$added,
        bool $earlyexit = false,
    ): array {
        $allturns = $this->sessionmanager->get_turns($session->id);

        // Build the session_close message.
        $closemessages = $this->promptbuilder->build_session_close_messages($config, $allturns, $earlyexit);
        $closeraw      = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $closemessages);
        ['content' => $closecontent] = $this->promptbuilder->parse_move($closeraw);

        $this->record_turn($added, $session->id, null, 'ai', 'session_close', $closecontent);

        // Refresh turns for report generation.
        $allturnsfinal   = $this->sessionmanager->get_turns($session->id);
        $criterionresults = $this->sessionmanager->get_criterion_results($session->id);

        // Build criterion-scoped turn map for report generation.
        // Assessment calls use per-criterion blocks (not full session history) to
        // prevent position bias and cross-criterion contamination in the AI's scoring
        // (Liu et al., TACL 2023; Zheng et al., NeurIPS 2023).
        $turnsbycriterion = $this->promptbuilder->build_turns_by_criterion($allturnsfinal);

        // Student report.
        $studentmessages = $this->promptbuilder->build_student_report_messages(
            $config,
            $turnsbycriterion,
            $criterionresults,
            $earlyexit,
        );
        $studentreport = $this->aiclient->chat(
            $config->aiurl,
            $config->aiapikey,
            $config->aimodel,
            $studentmessages,
        );

        // Teacher report + grade.
        $teachermessages = $this->promptbuilder->build_teacher_report_messages(
            $config,
            $turnsbycriterion,
            $criterionresults,
            $earlyexit,
        );
        $teacherraw = $this->aiclient->chat(
            $config->aiurl,
            $config->aiapikey,
            $config->aimodel,
            $teachermessages,
        );
        ['grade' => $aigrade, 'report' => $teacherreport] = $this->promptbuilder->parse_grade($teacherraw);

        // Persist.
        $this->sessionmanager->close_session($session->id, $studentreport, $teacherreport, $aigrade, $earlyexit);

        return [
            'ai_message'  => $closecontent,
            'move'        => 'session_close',
            'is_complete' => true,
        ];
    }

    /**
     * Find the current criterion — the first one that is 'pending' or 'in_progress'.
     *
     * Returns null if all criteria are resolved (terminal status).
     *
     * @param activity_config $config    Activity config.
     * @param int             $sessionid Session ID.
     * @return criterion_config|null
     */
    private function find_current_criterion(activity_config $config, int $sessionid): ?criterion_config {
        $results = $this->sessionmanager->get_criterion_results($sessionid);

        foreach ($config->criteria as $criterion) {
            $status = $results[$criterion->id]->status ?? 'pending';
            if (in_array($status, ['pending', 'in_progress'], true)) {
                return $criterion;
            }
        }

        return null;
    }

    /**
     * Find the next criterion after the given one, or null if there are no more.
     *
     * @param activity_config  $config           Activity config.
     * @param int              $sessionid        Session ID.
     * @param criterion_config $currentcriterion The criterion just closed.
     * @return criterion_config|null
     */
    private function find_next_criterion(
        activity_config $config,
        int $sessionid,
        criterion_config $currentcriterion,
    ): ?criterion_config {
        $found = false;
        $results = $this->sessionmanager->get_criterion_results($sessionid);

        foreach ($config->criteria as $criterion) {
            if ($found) {
                $status = $results[$criterion->id]->status ?? 'pending';
                if (in_array($status, ['pending', 'in_progress'], true)) {
                    return $criterion;
                }
            }
            if ($criterion->id === $currentcriterion->id) {
                $found = true;
            }
        }

        return null;
    }

    /**
     * Resolve the struggling sub-state and force-close status for the current turn.
     *
     * Rules:
     * - If probe_hint was already given on this criterion, this turn must force-close.
     * - If probe_scaffold was given (and no hint yet), student is responding to choice: handle_choice.
     * - If student has reached or exceeded maxturns without scaffold: offer_choice.
     * - Otherwise: normal turn under max turns.
     *
     * @param array            $criterionturns Turns for this criterion so far (including current student turn).
     * @param criterion_config $criterion      Current criterion being assessed.
     * @param int              $studentturns   Number of student turns on this criterion.
     * @return array Array with keys: 'substate' (?string), 'forceclose' (bool), 'allowlimit' (bool).
     */
    private function resolve_substate(
        array $criterionturns,
        criterion_config $criterion,
        int $studentturns,
    ): array {
        $scaffoldoffered = false;
        $hintgiven = false;

        foreach ($criterionturns as $turn) {
            if ($turn->role === 'ai') {
                if ($turn->move === 'probe_scaffold') {
                    $scaffoldoffered = true;
                } else if ($turn->move === 'probe_hint') {
                    $hintgiven = true;
                }
            }
        }

        if ($hintgiven) {
            return [
                'substate'   => null,
                'forceclose' => true,
                'allowlimit' => true,
            ];
        }

        if ($scaffoldoffered) {
            return [
                'substate'   => prompt_builder::SUBSTATE_HANDLE_CHOICE,
                'forceclose' => false,
                'allowlimit' => false,
            ];
        }

        if ($studentturns >= $criterion->maxturns) {
            return [
                'substate'   => prompt_builder::SUBSTATE_OFFER_CHOICE,
                'forceclose' => false,
                'allowlimit' => false,
            ];
        }

        return [
            'substate'   => null,
            'forceclose' => false,
            'allowlimit' => false,
        ];
    }

    /**
     * Ask the AI to evaluate the criterion outcome (met / partial / limit).
     *
     * Sends a short structured prompt with just the criterion turns and asks
     * for a single-word verdict.
     *
     * @param activity_config  $config      Activity config.
     * @param \stdClass        $session     Session record.
     * @param criterion_config $criterion   The criterion just closed.
     * @param bool             $allowlimit  Whether 'limit' is an allowable outcome.
     * @return string  'met', 'partial', or 'limit'.
     */
    private function evaluate_criterion_outcome(
        activity_config $config,
        \stdClass $session,
        criterion_config $criterion,
        bool $allowlimit = false,
    ): string {
        $criterionturns = $this->sessionmanager->get_turns_for_criterion($session->id, $criterion->id);
        $levellabel     = match ($criterion->bloomslevel) {
            activity_config::BLOOMS_ANALYSE  => 'Analyse',
            activity_config::BLOOMS_EVALUATE => 'Evaluate',
            activity_config::BLOOMS_CREATE   => 'Create',
            default                          => 'Custom',
        };

        if ($allowlimit) {
            $limitguidance = '  limit   — student still did not give sufficient evidence after receiving a hint';
            $validverdicts = ['met', 'partial', 'limit'];
            $fallback      = 'partial';
        } else {
            $limitguidance = '  limit   — max turns reached without sufficient evidence (do not use this here)';
            $validverdicts = ['met', 'partial'];
            $fallback      = 'partial';
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an educational assessor. Respond with exactly one word.',
            ],
            ...$this->criterion_turns_to_messages($criterionturns),
            [
                'role'    => 'user',
                'content' => <<<EOT
Based on the conversation above, did the student meet this criterion?

Criterion [{$levellabel}]: {$criterion->description}

Respond with exactly one of these words:
  met     — student provided clear, sufficient evidence (including if achieved after a hint)
  partial — student showed some understanding but evidence was incomplete
{$limitguidance}

One word only. No punctuation.
EOT
,
            ],
        ];

        $raw = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        // Tolerate "Met.", "MET" or "met\n", but not "not met".
        $verdict = preg_replace('/[^a-z]/', '', strtolower($raw));

        return in_array($verdict, $validverdicts, true) ? $verdict : $fallback;
    }

    /**
     * Ask the AI to extract a brief evidence quote from the criterion turns.
     *
     * @param activity_config  $config      Activity config.
     * @param int              $sessionid   Session ID.
     * @param criterion_config $criterion   The criterion just closed.
     * @return string  A short quoted excerpt, or empty string on failure.
     */
    private function extract_evidence(
        activity_config $config,
        int $sessionid,
        criterion_config $criterion,
    ): string {
        $criterionturns = $this->sessionmanager->get_turns_for_criterion($sessionid, $criterion->id);

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an educational assessor. Be concise.',
            ],
            ...$this->criterion_turns_to_messages($criterionturns),
            [
                'role'    => 'user',
                'content' => 'Quote the single most relevant excerpt from the student\'s responses above '
                    . 'that best demonstrates (or fails to demonstrate) their understanding of the criterion. '
                    . '1-2 sentences maximum. Quote only student text, do not paraphrase.',
            ],
        ];

        try {
            return $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        } catch (\moodle_exception $e) {
            return '';
        }
    }

    /**
     * Convert criterion-specific turns to OpenAI messages format.
     *
     * @param array $turns Turn records for a single criterion.
     * @return array
     */
    private function criterion_turns_to_messages(array $turns): array {
        $messages = [];
        foreach ($turns as $turn) {
            if ($turn->role === 'student') {
                $messages[] = ['role' => 'user', 'content' => $turn->content];
            } else {
                $content = preg_replace('/\[MOVE:[a-z_]+\]\s*$/i', '', $turn->content);
                $messages[] = ['role' => 'assistant', 'content' => trim($content)];
            }
        }
        return $messages;
    }
}
