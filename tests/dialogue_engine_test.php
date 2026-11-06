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
     * Test the AI closing a criterion at maxturns, because the answer is sufficient, is graded and completes the session.
     */
    public function test_process_student_turn_close_at_maxturns(): void {
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
        $mockai->expects($this->exactly(7))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                "Welcome!\n\n[MOVE:session_open]", // Session open.
                "Time is up on this topic.\n\n[MOVE:criterion_close]", // Turn response (close).
                "met", // Evaluate criterion outcome.
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

        // Verify the verdict was stored: 'limit' is not an allowed outcome when the AI closes on sufficient evidence.
        $criterionresult = $DB->get_record('aidialogue_criterion_result', [
            'sessionid' => $startresult['session']->id,
            'criterionid' => $config->criteria[0]->id,
        ]);
        $this->assertNotFalse($criterionresult);
        $this->assertSame('met', $criterionresult->status);
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
}
