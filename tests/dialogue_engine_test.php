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
use mod_aidialogue\tests\ai_reply_helper;

/**
 * Unit tests for dialogue_engine state machine using mocked AI client.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dialogue_engine::class)]
final class dialogue_engine_test extends \advanced_testcase {
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
     * Test successful start_session generates opening message and saves initial turn.
     */
    public function test_start_session_success(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->once())
            ->method('chat')
            ->willReturn("Hello! Welcome to our dialogue.\n\n[MOVE:session_open]");

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $result = $engine->start_session($config, (int) $student->id);

        $this->assertSame("Hello! Welcome to our dialogue.", $result['opening_message']);
        $this->assertSame('pending', $result['session']->status);

        // Verify initial AI turn was recorded in DB.
        $turn = $DB->get_record('aidialogue_turn', ['sessionid' => $result['session']->id]);
        $this->assertNotFalse($turn);
        $this->assertSame('ai', $turn->role);
        $this->assertSame('session_open', $turn->move);
    }

    /**
     * Test start_session cleans up orphaned session if AI call fails.
     */
    public function test_start_session_ai_failure_cleanup(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->once())
            ->method('chat')
            ->willThrowException(new \moodle_exception('error:airatelimited', 'mod_aidialogue'));

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());

        try {
            $engine->start_session($config, (int) $student->id);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('error:airatelimited', 'mod_aidialogue'), $e->getMessage());
        }

        // Verify session, turn, and criterion result rows were cleaned up (no orphaned records).
        $this->assertSame(0, $DB->count_records('aidialogue_session', ['aidialogueid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('aidialogue_turn'));
        $this->assertSame(0, $DB->count_records('aidialogue_criterion_result'));
    }

    /**
     * Test process_student_turn activates session and returns probe_deeper.
     */
    public function test_process_student_turn_probe_deeper(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome!\n\n[MOVE:session_open]", // Session open.
                "Can you explain why chlorophyll is green?\n\n[MOVE:probe_deeper]" // Turn 1 probe response.
            );

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $startresult = $engine->start_session($config, (int) $student->id);

        // Student sends first turn.
        $turnresult = $engine->process_student_turn(
            $config,
            $startresult['session'],
            'Chlorophyll absorbs light for photosynthesis.'
        );

        $this->assertSame("Can you explain why chlorophyll is green?", $turnresult['ai_message']);
        $this->assertSame('probe_deeper', $turnresult['move']);
        $this->assertFalse($turnresult['is_complete']);

        // Verify session transitioned from pending to active.
        $session = $DB->get_record('aidialogue_session', ['id' => $startresult['session']->id]);
        $this->assertSame('active', $session->status);
    }

    /**
     * Test reaching max turns triggers force close and completes session.
     */
    public function test_process_student_turn_forceclose(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');

        // Create activity with 1 criterion and maxturns = 1.
        $instance = $generator->create_instance([
            'course' => $course->id,
            'criteria' => [
                [
                    'sortorder'   => 1,
                    'bloomslevel' => activity_config::BLOOMS_ANALYSE,
                    'description' => 'Single criterion for testing',
                    'minturns'    => 1,
                    'maxturns'    => 1,
                ],
            ],
        ]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(6))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome!\n\n[MOVE:session_open]", // Session open.
                "Time is up on this topic.\n\n[MOVE:criterion_close]", // Turn response (forceclose).
                "Student mentioned chlorophyll.", // Extract evidence.
                "Thank you for chatting!\n\n[MOVE:session_close]", // Session close move.
                "Student Summary Feedback", // Student report.
                "Teacher Narrative Report\nGRADE:85.0" // Teacher report with grade.
            );

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $startresult = $engine->start_session($config, (int) $student->id);

        // Student submits message — hits maxturns = 1.
        $turnresult = $engine->process_student_turn(
            $config,
            $startresult['session'],
            'My first and only turn.'
        );

        $this->assertTrue($turnresult['is_complete']);
        $this->assertSame('session_close', $turnresult['move']);

        // Verify session is marked complete in DB.
        $closedsession = $DB->get_record('aidialogue_session', ['id' => $startresult['session']->id]);
        $this->assertSame('complete', $closedsession->status);
        $this->assertSame(85.0, (float) $closedsession->aigrade);

        // Verify criterion result was marked as 'limit' and evidence was stored.
        $criterionresult = $DB->get_record('aidialogue_criterion_result', [
            'sessionid' => $startresult['session']->id,
            'criterionid' => $config->criteria[0]->id,
        ]);
        $this->assertNotFalse($criterionresult);
        $this->assertSame('limit', $criterionresult->status);
        $this->assertSame('Student mentioned chlorophyll.', $criterionresult->evidence);
    }

    /**
     * Test non-forced criterion_close evaluates outcome as met and opens next criterion.
     */
    public function test_process_student_turn_criterion_transition(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');

        $instance = $generator->create_instance([
            'course' => $course->id,
            'criteria' => [
                [
                    'sortorder' => 1,
                    'bloomslevel' => activity_config::BLOOMS_ANALYSE,
                    'description' => 'Criterion 1: Chlorophyll',
                    'minturns' => 1,
                    'maxturns' => 3,
                ],
                [
                    'sortorder' => 2,
                    'bloomslevel' => activity_config::BLOOMS_EVALUATE,
                    'description' => 'Criterion 2: Light intensity',
                    'minturns' => 1,
                    'maxturns' => 3,
                ],
            ],
        ]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(5))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome!\n\n[MOVE:session_open]", // Session open.
                "Great explanation of chlorophyll!\n\n[MOVE:criterion_close]", // Turn response (criterion 1 close).
                "met", // Evaluate criterion 1 outcome.
                "Chlorophyll absorbs red and blue light.", // Extract criterion 1 evidence.
                "Now how does light intensity affect the rate?\n\n[MOVE:criterion_open]" // Criterion 2 open.
            );

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $startresult = $engine->start_session($config, (int) $student->id);

        $turnresult = $engine->process_student_turn(
            $config,
            $startresult['session'],
            'Chlorophyll absorbs red and blue light.'
        );

        $this->assertFalse($turnresult['is_complete']);
        $this->assertSame('criterion_open', $turnresult['move']);
        $this->assertSame(
            "Great explanation of chlorophyll!\n\nNow how does light intensity affect the rate?",
            $turnresult['ai_message']
        );

        // Verify Criterion 1 is 'met' and Criterion 2 is still 'pending'.
        $c1result = $DB->get_record('aidialogue_criterion_result', [
            'sessionid' => $startresult['session']->id,
            'criterionid' => $config->criteria[0]->id,
        ]);
        $c2result = $DB->get_record('aidialogue_criterion_result', [
            'sessionid' => $startresult['session']->id,
            'criterionid' => $config->criteria[1]->id,
        ]);
        $this->assertSame('met', $c1result->status);
        $this->assertSame('Chlorophyll absorbs red and blue light.', $c1result->evidence);
        $this->assertSame('pending', $c2result->status);

        // Verify the criterion_open turn was persisted for Criterion 2.
        $c2turn = $DB->get_record('aidialogue_turn', [
            'sessionid' => $startresult['session']->id,
            'criterionid' => $config->criteria[1]->id,
        ]);
        $this->assertNotFalse($c2turn);
        $this->assertSame('criterion_open', $c2turn->move);
        $this->assertSame('Now how does light intensity affect the rate?', $c2turn->content);
    }

    /**
     * Test ending session early marks remaining criteria abandoned.
     */
    public function test_end_session_early(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(4))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome!\n\n[MOVE:session_open]", // Session open.
                "Session ended early.\n\n[MOVE:session_close]", // Early exit session close move.
                "Early exit student summary", // Student report.
                "Early exit teacher report\nGRADE:50.0" // Teacher report with grade.
            );

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $startresult = $engine->start_session($config, (int) $student->id);

        // Student decides to click End session immediately.
        $endresult = $engine->end_session_early($config, $startresult['session']);

        $this->assertTrue($endresult['is_complete']);
        $this->assertSame('session_close', $endresult['move']);

        $closedsession = $DB->get_record('aidialogue_session', ['id' => $startresult['session']->id]);
        $this->assertSame('complete', $closedsession->status);
        $this->assertSame(1, (int) $closedsession->earlyexit);
        $this->assertSame(50.0, (float) $closedsession->aigrade);

        // Verify criterion was marked abandoned.
        $results = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $startresult['session']->id]);
        $this->assertCount(1, $results);
        foreach ($results as $r) {
            $this->assertSame('abandoned', $r->status);
        }
    }

    /**
     * Test get_session_state transitions across no_session, active, and complete states
     * and respects maxattempts boundary.
     */
    public function test_get_session_state(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance(['course' => $course->id, 'maxattempts' => 2]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(8))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome 1!\n\n[MOVE:session_open]", // Attempt 1: session open.
                "Goodbye 1!\n\n[MOVE:session_close]", // Attempt 1: early exit session close.
                "Student summary 1", // Attempt 1: student report.
                "Teacher report 1\nGRADE:75.0", // Attempt 1: teacher report with grade.
                "Welcome 2!\n\n[MOVE:session_open]", // Attempt 2: session open.
                "Goodbye 2!\n\n[MOVE:session_close]", // Attempt 2: early exit session close.
                "Student summary 2", // Attempt 2: student report.
                "Teacher report 2\nGRADE:90.0" // Attempt 2: teacher report with grade.
            );

        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());

        // 1. Before starting: no_session, attempt_count = 0, can_start = true.
        $state1 = $engine->get_session_state($config, (int) $student->id);
        $this->assertSame('no_session', $state1['state']);
        $this->assertSame(0, $state1['attempt_count']);
        $this->assertTrue($state1['can_start']);

        // 2. After starting attempt 1: active, attempt_count = 1, can_start = false.
        $start1 = $engine->start_session($config, (int) $student->id);
        $state2 = $engine->get_session_state($config, (int) $student->id);
        $this->assertSame('active', $state2['state']);
        $this->assertSame(1, $state2['attempt_count']);
        $this->assertFalse($state2['can_start']);
        $this->assertCount(1, $state2['turns']);

        // 3. After completing attempt 1 (1 < maxattempts 2): complete, can_start = true.
        $engine->end_session_early($config, $start1['session']);
        $state3 = $engine->get_session_state($config, (int) $student->id);
        $this->assertSame('complete', $state3['state']);
        $this->assertSame(1, $state3['attempt_count']);
        $this->assertTrue($state3['can_start']);
        $this->assertNotNull($state3['last_completed']);

        // 4. After completing attempt 2 (2 == maxattempts 2): complete, can_start = false.
        $start2 = $engine->start_session($config, (int) $student->id);
        $engine->end_session_early($config, $start2['session']);
        $state4 = $engine->get_session_state($config, (int) $student->id);
        $this->assertSame('complete', $state4['state']);
        $this->assertSame(2, $state4['attempt_count']);
        $this->assertFalse($state4['can_start']);
    }

    /**
     * Create a student session on a one criterion activity that closes after one student turn.
     *
     * @return array{0: activity_config, 1: \stdClass} The config and the started session.
     */
    private function start_closable_session(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aidialogue');
        $instance = $generator->create_instance([
            'course' => $course->id,
            'criteria' => [
                [
                    'sortorder'   => 1,
                    'bloomslevel' => activity_config::BLOOMS_ANALYSE,
                    'description' => 'Single criterion for testing',
                    'minturns'    => 1,
                    'maxturns'    => 1,
                ],
            ],
        ]);
        $config = activity_config::load_from_db((int) $instance->id);

        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->once())
            ->method('chat')
            ->willReturn("Welcome!\n\n[MOVE:session_open]"); // Session open.
        $engine = new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
        $startresult = $engine->start_session($config, (int) $student->id);

        return [$config, $startresult['session']];
    }

    /**
     * Build an engine whose mocked AI plays the given replies and expects exactly that many calls.
     *
     * @param array $replies Replies in call order (a Throwable is thrown).
     * @return dialogue_engine
     */
    private function engine_with_replies(array $replies): dialogue_engine {
        $mockai = $this->createMock(ai_client::class);
        $mockai->expects($this->exactly(count($replies)))
            ->method('chat')
            ->willReturnCallback(ai_reply_helper::script($replies));
        return new dialogue_engine(new session_manager(), $mockai, new prompt_builder());
    }

    /**
     * Data provider: the close step that fails first.
     *
     * @return array
     */
    public static function close_step_provider(): array {
        return [
            'closing message fails' => [0],
            'student report fails'  => [1],
            'teacher report fails'  => [2],
        ];
    }

    /**
     * Test a close that fails at one AI step is resumed from that step, without repeating earlier steps.
     *
     * @param int $failstep Close step that fails: 0 closing message, 1 student report, 2 teacher report.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('close_step_provider')]
    public function test_close_resumes_after_ai_failure(int $failstep): void {
        global $DB;

        [$config, $session] = $this->start_closable_session();

        // First attempt: the turn is a forced criterion close, then the close pipeline fails at $failstep.
        $closereplies = [
            "First goodbye.\n\n[MOVE:session_close]", // Closing message, first attempt.
            'Student report, first attempt', // Student report, first attempt.
            "Teacher report, first attempt\nGRADE:70.0", // Teacher report, first attempt.
        ];
        $closereplies[$failstep] = ai_reply_helper::failure(); // This step fails.
        $firstreplies = array_merge(
            [
                "Time is up.\n\n[MOVE:criterion_close]", // Turn response (forced close).
                'Student mentioned chlorophyll.', // Evidence.
            ],
            array_slice($closereplies, 0, $failstep + 1), // Steps up to and including the failing one.
        );
        $engine = $this->engine_with_replies($firstreplies);

        try {
            $engine->process_student_turn($config, $session, 'My first and only turn.');
            $this->fail('The failing AI call should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:ajax', $e->errorcode);
        }

        // The session is still active; finished steps are saved, later ones are not.
        $stored = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('active', $stored->status);
        $this->assertSame($failstep >= 1 ? 1 : 0, $DB->count_records('aidialogue_turn', [
            'sessionid' => $session->id,
            'move' => 'session_close',
        ]));
        $this->assertSame($failstep >= 2 ? 'Student report, first attempt' : null, $stored->studentreport);
        $this->assertNull($stored->teacherreport);

        // Retry: only the steps that did not finish are asked again.
        $retryreplies = array_slice([
            "Second goodbye.\n\n[MOVE:session_close]", // Closing message, retry.
            'Student report, retry', // Student report, retry.
            "Teacher report, retry\nGRADE:85.0", // Teacher report, retry.
        ], $failstep);
        $result = $this->engine_with_replies($retryreplies)
            ->process_student_turn($config, $stored, 'Trying again.');

        $this->assertTrue($result['is_complete']);
        $this->assertSame('session_close', $result['move']);
        $this->assertSame($failstep >= 1 ? 'First goodbye.' : 'Second goodbye.', $result['ai_message']);

        $closed = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('complete', $closed->status);
        $this->assertSame($failstep >= 2 ? 'Student report, first attempt' : 'Student report, retry', $closed->studentreport);
        $this->assertSame('Teacher report, retry', $closed->teacherreport);
        $this->assertSame(85.0, (float) $closed->aigrade);
        $this->assertSame(0, (int) $closed->earlyexit);
        $this->assertSame(1, $DB->count_records('aidialogue_turn', [
            'sessionid' => $session->id,
            'move' => 'session_close',
        ]));
    }

    /**
     * Test a retry that fails again leaves the session active and does not add a second closing turn.
     */
    public function test_close_retry_failing_again_keeps_session_active(): void {
        global $DB;

        [$config, $session] = $this->start_closable_session();

        // First attempt fails at the student report.
        try {
            $this->engine_with_replies([
                "Time is up.\n\n[MOVE:criterion_close]", // Turn response (forced close).
                'Student mentioned chlorophyll.', // Evidence.
                "Goodbye.\n\n[MOVE:session_close]", // Closing message.
                ai_reply_helper::failure(), // Student report fails.
            ])->process_student_turn($config, $session, 'Only turn.');
            $this->fail('The failing AI call should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:ajax', $e->errorcode);
        }

        // The retry gets the student report but fails at the teacher report.
        try {
            $this->engine_with_replies([
                'Student report', // Student report.
                ai_reply_helper::failure(), // Teacher report fails.
            ])->process_student_turn($config, $session, 'Trying again.');
            $this->fail('The failing AI call should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:ajax', $e->errorcode);
        }

        $stored = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('active', $stored->status);
        $this->assertSame('Student report', $stored->studentreport);
        $this->assertNull($stored->teacherreport);
        $this->assertNull($stored->timefinished);
        $this->assertSame(1, $DB->count_records('aidialogue_turn', [
            'sessionid' => $session->id,
            'move' => 'session_close',
        ]));

        // A third attempt only needs the teacher report.
        $result = $this->engine_with_replies([
            "Teacher report\nGRADE:60.0", // Teacher report.
        ])->process_student_turn($config, $session, 'One more time.');

        $this->assertTrue($result['is_complete']);
        $this->assertSame('Goodbye.', $result['ai_message']);
        $closed = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('complete', $closed->status);
        $this->assertSame(60.0, (float) $closed->aigrade);
    }

    /**
     * Data provider: which entry point fails first, which one retries, and the expected early exit flag.
     *
     * @return array
     */
    public static function earlyexit_retry_provider(): array {
        return [
            'early exit retried by a chat message' => ['end', 'message', 1],
            'normal close retried by End session'  => ['message', 'end', 0],
        ];
    }

    /**
     * Test the early exit flag follows what happened in the session, not the entry point that retries.
     *
     * @param string $firstvia Entry point of the failing attempt: 'message' or 'end'.
     * @param string $retryvia Entry point of the retry: 'message' or 'end'.
     * @param int $expectedearlyexit Expected value of the earlyexit column after the retry.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('earlyexit_retry_provider')]
    public function test_close_retry_keeps_earlyexit_meaning(string $firstvia, string $retryvia, int $expectedearlyexit): void {
        global $DB;

        [$config, $session] = $this->start_closable_session();

        $trigger = function (dialogue_engine $engine, string $via) use ($config, $session): array {
            return $via === 'end'
                ? $engine->end_session_early($config, $session)
                : $engine->process_student_turn($config, $session, 'Only turn.');
        };

        // First attempt fails at the student report (the closing message is saved).
        $firstreplies = [
            "Goodbye.\n\n[MOVE:session_close]", // Closing message.
            ai_reply_helper::failure(), // Student report fails.
        ];
        if ($firstvia === 'message') {
            array_unshift(
                $firstreplies,
                "Time is up.\n\n[MOVE:criterion_close]", // Turn response (forced close).
                'Student mentioned chlorophyll.', // Evidence.
            );
        }
        try {
            $trigger($this->engine_with_replies($firstreplies), $firstvia);
            $this->fail('The failing AI call should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:ajax', $e->errorcode);
        }

        // Retry from the other entry point: only the two reports are asked.
        $result = $trigger($this->engine_with_replies([
            'Student report', // Student report.
            "Teacher report\nGRADE:75.0", // Teacher report.
        ]), $retryvia);

        $this->assertTrue($result['is_complete']);
        $closed = $DB->get_record('aidialogue_session', ['id' => $session->id]);
        $this->assertSame('complete', $closed->status);
        $this->assertSame($expectedearlyexit, (int) $closed->earlyexit);
    }
}
