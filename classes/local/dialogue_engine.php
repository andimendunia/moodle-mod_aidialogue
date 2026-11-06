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

            ['move' => $move, 'content' => $content] = $this->promptbuilder->parse_move($rawresponse);

            // Normalise — AI should return session_open but accept any opening move.
            $move = $move ?? 'session_open';

            // Record AI turn (criterionid null for session-level turns).
            $this->sessionmanager->add_turn($session->id, null, 'ai', $move, $content);
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
        // Activate session on first student turn.
        if ($session->status === 'pending') {
            $this->sessionmanager->activate_session($session->id);
        }

        // Find the current criterion (first with status pending or in_progress).
        $currentcriterion = $this->find_current_criterion($config, $session->id);

        if ($currentcriterion === null) {
            // All criteria already resolved but session not yet closed — shouldn't
            // normally happen, but close defensively.
            return $this->do_session_close($config, $session);
        }

        // Mark criterion in_progress if it was pending.
        $results = $this->sessionmanager->get_criterion_results($session->id);
        $crstatus = $results[$currentcriterion->id]->status ?? 'pending';
        if ($crstatus === 'pending') {
            $this->sessionmanager->update_criterion_result($session->id, $currentcriterion->id, 'in_progress');
        }

        // Record the student's turn.
        $this->sessionmanager->add_turn(
            $session->id,
            $currentcriterion->id,
            'student',
            null,
            $content,
        );

        // Count student turns on this criterion (including the one just added).
        $studentturns = $this->sessionmanager->count_student_turns_for_criterion(
            $session->id,
            $currentcriterion->id,
        );

        $forceclose = $studentturns >= $currentcriterion->maxturns;

        // Get all turns for the full conversation context.
        $allturns = $this->sessionmanager->get_turns($session->id);

        // Ask AI for its response.
        $messages = $this->promptbuilder->build_probe_messages(
            $config,
            $currentcriterion,
            $allturns,
            $studentturns,
            $forceclose,
        );
        $rawresponse = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);

        ['move' => $move, 'content' => $aicontent] = $this->promptbuilder->parse_move($rawresponse);

        // Safety fallback — if AI forgot the tag.
        if ($move === null) {
            $move = $forceclose ? 'criterion_close' : 'probe_deeper';
        }

        // Record AI turn.
        $this->sessionmanager->add_turn(
            $session->id,
            $currentcriterion->id,
            'ai',
            $move,
            $aicontent,
        );

        // Handle criterion_close.
        if ($move === 'criterion_close' || $forceclose) {
            $outcome = $this->evaluate_criterion_outcome($config, $session, $currentcriterion, $forceclose);
            $evidence = $this->extract_evidence($config, $session->id, $currentcriterion);
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
                ['move' => $openmove, 'content' => $opencontent] = $this->promptbuilder->parse_move($openraw);
                $openmove = $openmove ?? 'criterion_open';

                $this->sessionmanager->add_turn(
                    $session->id,
                    $nextcriterion->id,
                    'ai',
                    $openmove,
                    $opencontent,
                );

                // Return combined message: criterion close response + criterion open.
                return [
                    'ai_message'  => $aicontent . "\n\n" . $opencontent,
                    'move'        => 'criterion_open',
                    'is_complete' => false,
                ];
            }

            // No more criteria — close the session.
            return $this->do_session_close($config, $session);
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
     * pipeline. The early exit is detected from the abandoned criteria.
     *
     * @param activity_config $config  Activity config.
     * @param \stdClass       $session Current session record.
     * @return array  Same shape as process_student_turn() return.
     */
    public function end_session_early(activity_config $config, \stdClass $session): array {
        $this->sessionmanager->abandon_remaining_criteria($session->id);
        return $this->do_session_close($config, $session);
    }

    /**
     * Fire session_close AI turn, generate reports, and close the session.
     *
     * AI calls cannot sit inside a database transaction, so each step saves its
     * output as soon as it has it and is skipped if that output already exists.
     * If a call fails, the session stays active and the next call (a new student
     * message, or End session) resumes from the failed step instead of repeating
     * finished ones. close_session() always runs last.
     *
     * Whether the student left early is read from the criterion results, not from
     * the caller, so a retry through a different entry point keeps the same meaning.
     *
     * @param activity_config $config  Activity config.
     * @param \stdClass       $session Session record.
     * @return array  Same shape as process_student_turn() return.
     */
    private function do_session_close(activity_config $config, \stdClass $session): array {
        // Read the stored state: this may be a retry of a close that failed part way.
        $session = $this->sessionmanager->get_session((int) $session->id);
        $criterionresults = $this->sessionmanager->get_criterion_results($session->id);

        $earlyexit = false;
        foreach ($criterionresults as $result) {
            if ($result->status === 'abandoned') {
                $earlyexit = true;
                break;
            }
        }

        // Step 1: closing message.
        $allturns = $this->sessionmanager->get_turns($session->id);
        $closecontent = null;
        foreach ($allturns as $turn) {
            if ($turn->move === 'session_close') {
                $closecontent = $turn->content;
                break;
            }
        }

        if ($closecontent === null) {
            $closemessages = $this->promptbuilder->build_session_close_messages($config, $allturns, $earlyexit);
            $closeraw      = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $closemessages);
            ['content' => $closecontent] = $this->promptbuilder->parse_move($closeraw);

            // Always stored as session_close, so a retry can recognise this step as done.
            $this->sessionmanager->add_turn($session->id, null, 'ai', 'session_close', $closecontent);
            $allturns = $this->sessionmanager->get_turns($session->id);
        }

        // Build criterion-scoped turn map for report generation.
        // Assessment calls use per-criterion blocks (not full session history) to
        // prevent position bias and cross-criterion contamination in the AI's scoring
        // (Liu et al., TACL 2023; Zheng et al., NeurIPS 2023).
        $turnsbycriterion = $this->promptbuilder->build_turns_by_criterion($allturns);

        // Step 2: student report.
        if ($session->studentreport === null) {
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
            $this->sessionmanager->save_student_report($session->id, $studentreport);
        } else {
            $studentreport = $session->studentreport;
        }

        // Step 3: teacher report and grade.
        if ($session->teacherreport === null) {
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
            $this->sessionmanager->save_teacher_report($session->id, $teacherreport, $aigrade);
        } else {
            $teacherreport = $session->teacherreport;
            $aigrade = (float) $session->aigrade;
        }

        // Final step: mark the session complete.
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
     * Ask the AI to evaluate the criterion outcome (met / partial / limit).
     *
     * Sends a short structured prompt with just the criterion turns and asks
     * for a single-word verdict.
     *
     * @param activity_config  $config           Activity config.
     * @param \stdClass        $session          Session record.
     * @param criterion_config $criterion        The criterion just closed.
     * @param bool             $forceclose      Whether this was a forced close.
     * @return string  'met', 'partial', or 'limit'.
     */
    private function evaluate_criterion_outcome(
        activity_config $config,
        \stdClass $session,
        criterion_config $criterion,
        bool $forceclose,
    ): string {
        if ($forceclose) {
            return 'limit';
        }

        $criterionturns = $this->sessionmanager->get_turns_for_criterion($session->id, $criterion->id);
        $levellabel     = match ($criterion->bloomslevel) {
            activity_config::BLOOMS_ANALYSE  => 'Analyse',
            activity_config::BLOOMS_EVALUATE => 'Evaluate',
            activity_config::BLOOMS_CREATE   => 'Create',
            default                          => 'Custom',
        };

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
  met     — student provided clear, sufficient evidence
  partial — student showed some understanding but evidence was incomplete
  limit   — max turns reached without sufficient evidence (do not use this here)

One word only. No punctuation.
EOT
,
            ],
        ];

        $raw = $this->aiclient->chat($config->aiurl, $config->aiapikey, $config->aimodel, $messages);
        $verdict = strtolower(trim($raw));

        return in_array($verdict, ['met', 'partial'], true) ? $verdict : 'partial';
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
