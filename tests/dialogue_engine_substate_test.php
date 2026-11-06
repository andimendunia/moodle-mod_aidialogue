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

namespace mod_aidialogue;

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\dialogue_engine;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\session_manager;

/**
 * Tests for the struggling student sub-state of the dialogue engine.
 *
 * The AI client is mocked, so every test scripts the exact replies the AI gives.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dialogue_engine::class)]
final class dialogue_engine_substate_test extends \advanced_testcase {
    /** @var string The reply the AI gives when the session opens. */
    private const OPENING = "Welcome!\n\n[MOVE:session_open]";

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        set_config('aiurl', 'https://api.openai.com/v1', 'mod_aidialogue');
        set_config('aiapikey', 'dummy-key', 'mod_aidialogue');
        set_config('aimodel', 'gpt-4o', 'mod_aidialogue');
    }

    /**
     * Create an activity with one criterion per given maxturns value, and a student.
     *
     * @param int[] $maxturns The maxturns of each criterion.
     * @return array{0: activity_config, 1: \stdClass} The activity config and the student.
     */
    private function create_activity(array $maxturns): array {
        $criteria = [];
        foreach ($maxturns as $index => $max) {
            $criteria[] = [
                'sortorder'   => $index + 1,
                'bloomslevel' => activity_config::BLOOMS_ANALYSE,
                'description' => 'Criterion ' . ($index + 1),
                'minturns'    => 1,
                'maxturns'    => $max,
            ];
        }

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_aidialogue')
            ->create_instance(['course' => $course->id, 'criteria' => $criteria]);

        return [activity_config::load_from_db((int) $instance->id), $student];
    }

    /**
     * Create an engine whose AI client gives the opening reply and then the scripted replies, in order.
     *
     * The mock fails the test if the engine makes more or fewer AI calls than scripted.
     *
     * @param string[] $replies The AI replies after the opening.
     * @return dialogue_engine
     */
    private function create_engine(array $replies): dialogue_engine {
        $replies = [self::OPENING, ...$replies];

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(count($replies)))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(...$replies);

        return new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
    }

    /**
     * Get the status of a criterion result.
     *
     * @param \stdClass $session The session.
     * @param int $criterionid The criterion id.
     * @return string
     */
    private function get_result_status(\stdClass $session, int $criterionid): string {
        global $DB;

        return $DB->get_field(
            'aidialogue_criterion_result',
            'status',
            ['sessionid' => $session->id, 'criterionid' => $criterionid],
            MUST_EXIST,
        );
    }

    /**
     * The sub-state follows the three branches, and the engine bounds and corrects whatever the AI does.
     *
     * @param int $maxturns The maxturns of the single criterion.
     * @param string[] $replies The AI replies after the opening.
     * @param string[] $messages The student messages.
     * @param string[] $moves The move returned for each student message.
     * @param string $status The expected status of the criterion result.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('struggling_paths_provider')]
    public function test_struggling_paths(
        int $maxturns,
        array $replies,
        array $messages,
        array $moves,
        string $status,
    ): void {
        global $DB;

        [$config, $student] = $this->create_activity([$maxturns]);
        $engine = $this->create_engine($replies);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $actual = [];
        foreach ($messages as $message) {
            $actual[] = $engine->process_student_turn($config, $session, $message)['move'];
        }

        $this->assertSame($moves, $actual);
        $this->assertSame($status, $this->get_result_status($session, $config->criteria[0]->id));

        // A criterion never takes more than maxturns + 2 student turns.
        $studentturns = $DB->count_records('aidialogue_turn', ['sessionid' => $session->id, 'role' => 'student']);
        $this->assertSame(count($messages), $studentturns);
        $this->assertLessThanOrEqual($maxturns + 2, $studentturns);
    }

    /**
     * Data provider for test_struggling_paths.
     *
     * @return array[]
     */
    public static function struggling_paths_provider(): array {
        $closing = ["Session complete!\n\n[MOVE:session_close]", 'Student report', "Teacher report\nGRADE:60.0"];

        return [
            'A: hint, then the final answer is met' => [
                2,
                [
                    "Elaborate?\n\n[MOVE:probe_deeper]",
                    "Tricky. Hint or review?\n\n[MOVE:probe_scaffold]",
                    "Look at chlorophyll.\n\n[MOVE:probe_hint]",
                    "Great!\n\n[MOVE:criterion_close]",
                    'met',
                    'Evidence.',
                    ...$closing,
                ],
                ['Vague.', 'Still unsure.', 'A hint please.', 'Chlorophyll absorbs light.'],
                ['probe_deeper', 'probe_scaffold', 'probe_hint', 'session_close'],
                'met',
            ],
            'B: student skips and the criterion is limit' => [
                2,
                [
                    "Elaborate?\n\n[MOVE:probe_deeper]",
                    "Tricky. Hint or review?\n\n[MOVE:probe_scaffold]",
                    "Take time to review.\n\n[MOVE:criterion_skip]",
                    ...$closing,
                ],
                ['Vague.', 'Still unsure.', 'I will review first.'],
                ['probe_deeper', 'probe_scaffold', 'session_close'],
                'limit',
            ],
            'C: valid answer to the offer is met, not limit' => [
                2,
                [
                    "Elaborate?\n\n[MOVE:probe_deeper]",
                    "Tricky. Hint or review?\n\n[MOVE:probe_scaffold]",
                    "Exactly right.\n\n[MOVE:criterion_close]",
                    'met',
                    'Evidence.',
                    ...$closing,
                ],
                ['Vague.', 'Still unsure.', 'Chlorophyll traps light energy.'],
                ['probe_deeper', 'probe_scaffold', 'session_close'],
                'met',
            ],
            'maxturns of one still offers a hint' => [
                1,
                [
                    "Hint or review?\n\n[MOVE:probe_scaffold]",
                    "Hint.\n\n[MOVE:probe_hint]",
                    "Done.\n\n[MOVE:criterion_close]",
                    'met',
                    'Evidence.',
                    ...$closing,
                ],
                ['One.', 'Hint please.', 'Final.'],
                ['probe_scaffold', 'probe_hint', 'session_close'],
                'met',
            ],
            'wrong moves are reissued once and the hint closes the criterion' => [
                2,
                [
                    "Elaborate?\n\n[MOVE:probe_deeper]",
                    "Offer with the wrong tag\n\n[MOVE:probe_clarify]",
                    "Offer again\n\n[MOVE:probe_scaffold]",
                    "Tag over twenty characters\n\n[MOVE:probe_scaffold_very_long_string_exceeding_twenty]",
                    "Hint again\n\n[MOVE:probe_hint]",
                    "Stay open\n\n[MOVE:probe_hint]",
                    'partial',
                    'Evidence.',
                    ...$closing,
                ],
                ['One.', 'Two.', 'Help.', 'Final.'],
                ['probe_deeper', 'probe_scaffold', 'probe_hint', 'session_close'],
                'partial',
            ],
            'tags that stay missing after one retry fall back to the sub-state move' => [
                1,
                ['Offer without a tag', 'Still no tag', 'Hint without a tag', 'Still no tag'],
                ['One.', 'Two.'],
                ['probe_scaffold', 'probe_hint'],
                'in_progress',
            ],
            'illegal moves in a normal turn become probe_deeper' => [
                5,
                ["Illegal\n\n[MOVE:session_close]", "Illegal\n\n[MOVE:criterion_open]"],
                ['One.', 'Two.'],
                ['probe_deeper', 'probe_deeper'],
                'in_progress',
            ],
            'garbage verdict after a hint is partial' => [
                1,
                [
                    "Offer\n\n[MOVE:probe_scaffold]",
                    "Hint\n\n[MOVE:probe_hint]",
                    "Close\n\n[MOVE:criterion_close]",
                    'garbage_xyz',
                    'Evidence.',
                    ...$closing,
                ],
                ['One.', 'Hint.', 'Final.'],
                ['probe_scaffold', 'probe_hint', 'session_close'],
                'partial',
            ],
            'limit verdict in a normal close is partial' => [
                3,
                ["Close early\n\n[MOVE:criterion_close]", 'limit', 'Evidence.', ...$closing],
                ['One.'],
                ['session_close'],
                'partial',
            ],
        ];
    }

    /**
     * Sub-state history on one criterion does not leak into the next criterion.
     */
    public function test_substate_is_isolated_between_criteria(): void {
        [$config, $student] = $this->create_activity([1, 3]);
        $engine = $this->create_engine([
            "Offer\n\n[MOVE:probe_scaffold]",
            "Hint\n\n[MOVE:probe_hint]",
            "Close\n\n[MOVE:criterion_close]",
            'met',
            'Evidence.',
            "Open second\n\n[MOVE:criterion_open]",
            "Normal probe\n\n[MOVE:probe_deeper]",
        ]);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $engine->process_student_turn($config, $session, 'One.');
        $engine->process_student_turn($config, $session, 'Hint.');
        $closed = $engine->process_student_turn($config, $session, 'Final.');
        $this->assertSame('criterion_open', $closed['move']);
        $this->assertFalse($closed['is_complete']);

        $next = $engine->process_student_turn($config, $session, 'Second criterion.');
        $this->assertSame('probe_deeper', $next['move']);
        $this->assertFalse($next['is_complete']);
    }

    /**
     * Ending the session early while the offer is open abandons the criterion.
     */
    public function test_end_session_early_during_substate(): void {
        global $DB;

        [$config, $student] = $this->create_activity([1]);
        $engine = $this->create_engine([
            "Offer\n\n[MOVE:probe_scaffold]",
            "Early exit\n\n[MOVE:session_close]",
            'Student report',
            "Teacher report\nGRADE:40.0",
        ]);
        $session = $engine->start_session($config, (int) $student->id)['session'];
        $engine->process_student_turn($config, $session, 'I need help.');

        $result = $engine->end_session_early($config, $session);

        $this->assertTrue($result['is_complete']);
        $this->assertSame('abandoned', $this->get_result_status($session, $config->criteria[0]->id));
        $record = $DB->get_record('aidialogue_session', ['id' => $session->id], '*', MUST_EXIST);
        $this->assertSame('complete', $record->status);
        $this->assertSame(1, (int) $record->earlyexit);
    }

    /**
     * Create an engine that scripts the AI replies and records every prompt it is sent.
     *
     * @param (string|\Throwable)[] $replies All AI replies, including the opening, in call order. A throwable is thrown.
     * @param array $log Receives the messages array of each AI call, in call order.
     * @return dialogue_engine
     */
    private function create_logging_engine(array $replies, array &$log): dialogue_engine {
        $queue = $replies;
        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(count($replies)))
            ->method('chat')
            ->willReturnCallback(
                function (string $url, string $key, string $model, array $messages) use (&$queue, &$log): string {
                    $log[] = $messages;
                    $next = array_shift($queue);
                    if ($next instanceof \Throwable) {
                        throw $next;
                    }
                    return $next;
                }
            );

        return new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
    }

    /**
     * Get the last (instruction) message sent in one AI call.
     *
     * @param array $messages The messages array of the call.
     * @return string
     */
    private function last_prompt(array $messages): string {
        return end($messages)['content'];
    }

    /**
     * Describe the turns of a criterion as 'student' or the AI move, in order.
     *
     * @param \stdClass $session The session.
     * @param int $criterionid The criterion id.
     * @return string[]
     */
    private function describe_turns(\stdClass $session, int $criterionid): array {
        $turns = (new session_manager())->get_turns_for_criterion((int) $session->id, $criterionid);

        return array_map(
            static fn(\stdClass $turn): string => $turn->role === 'student' ? 'student' : $turn->move,
            $turns,
        );
    }

    /**
     * A student with a slow start is offered a hint and recovers instead of being cut off at turn 2.
     *
     * This is the scenario that motivated the sub-state: the student misreads the first question,
     * is still unsure on the second reply, and previously the criterion closed as 'limit' right there.
     */
    public function test_slow_start_student_recovers_with_a_hint(): void {
        global $DB;

        [$config, $student] = $this->create_activity([2, 2]);
        $log = [];
        $engine = $this->create_logging_engine([
            self::OPENING,
            "Can you say more about that?\n\n[MOVE:probe_clarify]",
            "This one is tricky. Would you like a quick hint, or skip it for now?\n\n[MOVE:probe_scaffold]",
            "Think about what chlorophyll does with sunlight. What does it do?\n\n[MOVE:probe_hint]",
            "Thanks, that answers it.\n\n[MOVE:criterion_close]",
            'met',
            'Chlorophyll absorbs light energy to make glucose.',
            "Now, how does light intensity affect the rate?\n\n[MOVE:criterion_open]",
            "Clear explanation.\n\n[MOVE:criterion_close]",
            'met',
            'Rate rises then plateaus.',
            "Thank you!\n\n[MOVE:session_close]",
            'Student report',
            "Teacher report\nGRADE:82.0",
        ], $log);
        $session = $engine->start_session($config, (int) $student->id)['session'];
        $first = $config->criteria[0];
        $second = $config->criteria[1];

        // Turn 1: the student misreads the question and gets a clarifying probe.
        $turn1 = $engine->process_student_turn($config, $session, 'Chlorophyll is the green thing in leaves.');
        $this->assertSame('probe_clarify', $turn1['move']);

        // Turn 2: still unsure. The old behaviour closed the criterion as 'limit' here.
        $turn2 = $engine->process_student_turn($config, $session, 'I only know it makes leaves green.');
        $this->assertSame('probe_scaffold', $turn2['move']);
        $this->assertFalse($turn2['is_complete']);
        $this->assertSame('in_progress', $this->get_result_status($session, $first->id));
        $this->assertSame('active', $DB->get_field('aidialogue_session', 'status', ['id' => $session->id]));

        // The AI was asked for a choice, and told to be open about what each option means.
        $offerprompt = $this->last_prompt($log[2]);
        $this->assertStringContainsString('MOVE:probe_scaffold', $offerprompt);
        $this->assertStringContainsString('quick hint', $offerprompt);
        $this->assertStringContainsString('skip this topic', $offerprompt);
        $this->assertStringContainsString('will be noted in their feedback report', $offerprompt);

        // Turn 3: the student asks for a hint. The hint must come from the knowledge base, without the answer.
        $turn3 = $engine->process_student_turn($config, $session, 'Yes, please give me a quick hint.');
        $this->assertSame('probe_hint', $turn3['move']);
        $this->assertStringContainsString('WITHOUT giving away', $this->last_prompt($log[3]));
        $this->assertStringContainsString($config->knowledgetext, $log[3][0]['content']);

        // Turn 4: the final attempt. The engine closes the criterion itself and the verdict is 'met'.
        $turn4 = $engine->process_student_turn(
            $config,
            $session,
            'Chlorophyll absorbs light energy to convert water and carbon dioxide into glucose and oxygen.',
        );
        $this->assertStringContainsString('MUST close', $this->last_prompt($log[4]));
        $this->assertStringContainsString('after a hint', $this->last_prompt($log[5]));
        $this->assertSame('criterion_open', $turn4['move']);
        $this->assertFalse($turn4['is_complete']);
        $this->assertSame('met', $this->get_result_status($session, $first->id));
        $this->assertSame('pending', $this->get_result_status($session, $second->id));

        // The criterion took exactly maxturns + 2 student turns, in the intended order.
        $this->assertSame(
            ['student', 'probe_clarify', 'student', 'probe_scaffold', 'student', 'probe_hint', 'student', 'criterion_close'],
            $this->describe_turns($session, $first->id),
        );

        // The recovered student carries on normally: criterion 2 has no sub-state and the session completes.
        $last = $engine->process_student_turn($config, $session, 'More light speeds it up until another factor limits it.');
        $this->assertSame('session_close', $last['move']);
        $this->assertTrue($last['is_complete']);
        $this->assertSame('met', $this->get_result_status($session, $second->id));

        $record = $DB->get_record('aidialogue_session', ['id' => $session->id], '*', MUST_EXIST);
        $this->assertSame('complete', $record->status);
        $this->assertSame(0, (int) $record->earlyexit);
        $this->assertSame(82.0, (float) $record->aigrade);
    }

    /**
     * A student who prefers to review first is not pushed to answer, and the session carries on.
     */
    public function test_skipping_a_topic_moves_on_without_ending_the_session(): void {
        global $DB;

        [$config, $student] = $this->create_activity([2, 2]);
        $log = [];
        $engine = $this->create_logging_engine([
            self::OPENING,
            "Can you say more about that?\n\n[MOVE:probe_clarify]",
            "This one is tricky. Would you like a quick hint, or skip it for now?\n\n[MOVE:probe_scaffold]",
            "No problem, take time to review.\n\n[MOVE:criterion_skip]",
            "Next topic: how does light intensity affect the rate?\n\n[MOVE:criterion_open]",
        ], $log);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $engine->process_student_turn($config, $session, 'Light makes plants grow.');
        $engine->process_student_turn($config, $session, 'I am not sure about the intensity.');
        $result = $engine->process_student_turn($config, $session, 'I prefer to skip and review the lesson material first.');

        $this->assertSame('criterion_open', $result['move']);
        $this->assertFalse($result['is_complete']);
        $this->assertStringContainsString('review', $result['ai_message']);
        $this->assertStringContainsString('Next topic', $result['ai_message']);
        $this->assertSame('limit', $this->get_result_status($session, $config->criteria[0]->id));
        $this->assertSame('pending', $this->get_result_status($session, $config->criteria[1]->id));
        $this->assertSame('active', $DB->get_field('aidialogue_session', 'status', ['id' => $session->id]));

        // The skip was recorded as its own move, never as a hint, and no evidence was invented for it.
        $this->assertNull($DB->get_field('aidialogue_criterion_result', 'evidence', [
            'sessionid' => $session->id,
            'criterionid' => $config->criteria[0]->id,
        ]));
        $this->assertSame(
            ['student', 'probe_clarify', 'student', 'probe_scaffold', 'student', 'criterion_skip'],
            $this->describe_turns($session, $config->criteria[0]->id),
        );
    }

    /**
     * A sub-state tag on the opening message of the next criterion must not leak into that criterion.
     */
    public function test_substate_tags_on_criterion_open_do_not_leak(): void {
        [$config, $student] = $this->create_activity([1, 3]);
        $engine = $this->create_engine([
            "Good.\n\n[MOVE:criterion_close]",
            'met',
            'Evidence.',
            "Second question?\n\n[MOVE:probe_hint]",
            "Tell me more.\n\n[MOVE:probe_deeper]",
        ]);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $opened = $engine->process_student_turn($config, $session, 'First answer.');
        $this->assertSame('criterion_open', $opened['move']);

        // With a leaked hint tag the engine would force-close criterion 2 (maxturns 3) after this one reply.
        $next = $engine->process_student_turn($config, $session, 'My first answer to criterion 2.');
        $this->assertSame('probe_deeper', $next['move']);
        $this->assertFalse($next['is_complete']);
        $this->assertSame(
            ['criterion_open', 'student', 'probe_deeper'],
            $this->describe_turns($session, $config->criteria[1]->id),
        );
    }

    /**
     * A failed AI call leaves no trace, so the retry counts the student's reply once.
     */
    public function test_failed_ai_call_is_undone_and_the_retry_counts_once(): void {
        global $DB;

        [$config, $student] = $this->create_activity([2]);
        $log = [];
        $engine = $this->create_logging_engine([
            self::OPENING,
            "Say more?\n\n[MOVE:probe_deeper]",
            // Turn 2, first try: the AI replies that the criterion is closed, then grading fails.
            "Good.\n\n[MOVE:criterion_close]",
            new \moodle_exception('error:airatelimited', 'mod_aidialogue'),
            // Turn 2, retry.
            "Good.\n\n[MOVE:criterion_close]",
            'met',
            'Evidence.',
            "Done.\n\n[MOVE:session_close]",
            'Student report',
            "Teacher report\nGRADE:70.0",
        ], $log);
        $session = $engine->start_session($config, (int) $student->id)['session'];
        $criterionid = $config->criteria[0]->id;

        $engine->process_student_turn($config, $session, 'First.');
        try {
            $engine->process_student_turn($config, $session, 'Second.');
            $this->fail('The AI failure should have been rethrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('error:airatelimited', 'mod_aidialogue'), $e->getMessage());
        }

        // Nothing from the failed turn remains: not the student reply, not the announced close.
        $this->assertSame(['student', 'probe_deeper'], $this->describe_turns($session, $criterionid));
        $this->assertSame('in_progress', $this->get_result_status($session, $criterionid));

        $result = $engine->process_student_turn($config, $session, 'Second.');
        $this->assertTrue($result['is_complete']);
        $this->assertSame('met', $this->get_result_status($session, $criterionid));
        $this->assertSame(
            2,
            $DB->count_records('aidialogue_turn', ['sessionid' => $session->id, 'role' => 'student']),
        );
    }

    /**
     * A reply without a valid tag is reissued once, and the instruction names the allowed tags.
     */
    public function test_invalid_tag_gets_one_corrective_retry(): void {
        [$config, $student] = $this->create_activity([1]);
        $log = [];
        $engine = $this->create_logging_engine([
            self::OPENING,
            'An offer that forgot its tag.',
            "Would you like a hint or to skip?\n\n[MOVE:probe_scaffold]",
        ], $log);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $result = $engine->process_student_turn($config, $session, 'I am stuck.');

        $this->assertSame('probe_scaffold', $result['move']);
        $this->assertSame('Would you like a hint or to skip?', $result['ai_message']);
        $this->assertCount(3, $log);

        $retry = $log[2];
        $this->assertSame('An offer that forgot its tag.', $retry[count($retry) - 2]['content']);
        $this->assertSame('assistant', $retry[count($retry) - 2]['role']);
        $this->assertStringContainsString('[MOVE:criterion_close], [MOVE:probe_scaffold]', $this->last_prompt($retry));
    }

    /**
     * The verdict is read tolerantly, but never as "met" when the AI said "not met".
     *
     * @param string $verdict What the AI answers to the outcome question.
     * @param string $status The expected status of the criterion result.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verdict_provider')]
    public function test_verdict_is_normalised(string $verdict, string $status): void {
        [$config, $student] = $this->create_activity([3]);
        $engine = $this->create_engine([
            "Great.\n\n[MOVE:criterion_close]",
            $verdict,
            'Evidence.',
            "Bye.\n\n[MOVE:session_close]",
            'Student report',
            "Teacher report\nGRADE:60.0",
        ]);
        $session = $engine->start_session($config, (int) $student->id)['session'];

        $engine->process_student_turn($config, $session, 'A full answer.');

        $this->assertSame($status, $this->get_result_status($session, $config->criteria[0]->id));
    }

    /**
     * Data provider for test_verdict_is_normalised.
     *
     * @return array[]
     */
    public static function verdict_provider(): array {
        return [
            'plain' => ['met', 'met'],
            'trailing full stop' => ['Met.', 'met'],
            'upper case with newline' => ["MET\n", 'met'],
            'partial with full stop' => ['partial.', 'partial'],
            'not met is not met' => ['not met', 'partial'],
            'limit is not allowed in a normal close' => ['limit', 'partial'],
        ];
    }
}
