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
 * Custom completion tests for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue;

use mod_aidialogue\completion\custom_completion;
use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\session_manager;

/**
 * Custom completion tests for mod_aidialogue.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(custom_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(session_manager::class)]
final class custom_completion_test extends \advanced_testcase {
    /**
     * Creates a course, a student and an activity with automatic completion.
     *
     * @param int $maxattempts Attempt limit (0 = unlimited).
     * @return array [course, student, activity]
     */
    private function create_activity(int $maxattempts): array {
        $this->resetAfterTest();
        set_config('aiurl', 'https://ai.example.invalid/v1', 'mod_aidialogue');
        set_config('aiapikey', 'not-a-real-key', 'mod_aidialogue');
        set_config('aimodel', 'test-model', 'mod_aidialogue');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $aidialogue = $generator->create_module('aidialogue', [
            'course' => $course->id,
            'maxattempts' => $maxattempts,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionpassorexhaust' => 1,
        ]);
        return [$course, $student, $aidialogue];
    }

    /**
     * Adds a session with one result row per criterion.
     *
     * @param \stdClass $aidialogue Activity record.
     * @param int $userid Student id.
     * @param string $sessionstatus Session status (complete, active).
     * @param string $resultstatus Status given to every criterion result (met, partial, ...).
     * @return \stdClass The session record.
     */
    private function add_session(\stdClass $aidialogue, int $userid, string $sessionstatus, string $resultstatus): \stdClass {
        global $DB;

        $manager = new session_manager();
        $config = activity_config::load_from_db($aidialogue->id);
        $session = $manager->create_session($aidialogue->id, $userid, $config);
        foreach ($config->criteria as $criterion) {
            $manager->update_criterion_result($session->id, $criterion->id, $resultstatus);
        }
        $DB->set_field('aidialogue_session', 'status', $sessionstatus, ['id' => $session->id]);
        return $session;
    }

    /**
     * Returns the rule state as Moodle's completion API sees it.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $aidialogue Activity record.
     * @param int $userid Student id.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    private function get_rule_state(\stdClass $course, \stdClass $aidialogue, int $userid): int {
        $cm = get_fast_modinfo($course, $userid)->get_cm($aidialogue->cmid);
        return (new custom_completion($cm, $userid))->get_state('completionpassorexhaust');
    }

    /**
     * The single rule is met by a pass OR by using all attempts, and by nothing else.
     *
     * @param int $maxattempts Attempt limit.
     * @param array $sessions List of [session status, criterion result status].
     * @param bool $expectcomplete Whether the rule should be met.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rule_state_provider')]
    public function test_rule_state(int $maxattempts, array $sessions, bool $expectcomplete): void {
        [$course, $student, $aidialogue] = $this->create_activity($maxattempts);
        foreach ($sessions as [$sessionstatus, $resultstatus]) {
            $this->add_session($aidialogue, $student->id, $sessionstatus, $resultstatus);
        }

        $expected = $expectcomplete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        $this->assertSame($expected, $this->get_rule_state($course, $aidialogue, $student->id));
    }

    /**
     * Data for test_rule_state.
     *
     * @return array
     */
    public static function rule_state_provider(): array {
        return [
            'no sessions' => [2, [], false],
            'passed on first of two attempts' => [2, [['complete', 'met']], true],
            'failed first of two attempts' => [2, [['complete', 'partial']], false],
            'failed both attempts (exhausted)' => [2, [['complete', 'partial'], ['complete', 'limit']], true],
            'failed then passed' => [2, [['complete', 'partial'], ['complete', 'met']], true],
            'one failed and one still active' => [2, [['complete', 'partial'], ['active', 'in_progress']], false],
            'unlimited attempts, many failures' => [
                0,
                [['complete', 'partial'], ['complete', 'partial'], ['complete', 'partial']],
                false,
            ],
            'unlimited attempts, passed' => [0, [['complete', 'met']], true],
        ];
    }

    /**
     * Closing a session updates completion straight away, without the student viewing the activity.
     */
    public function test_close_session_updates_completion(): void {
        global $DB;

        [$course, $student, $aidialogue] = $this->create_activity(2);
        $session = $this->add_session($aidialogue, $student->id, 'active', 'met');

        $params = ['coursemoduleid' => $aidialogue->cmid, 'userid' => $student->id];
        $this->assertFalse($DB->record_exists('course_modules_completion', $params));

        (new session_manager())->close_session($session->id, 'student report', 'teacher report', 100.0);

        $this->assertEquals(COMPLETION_COMPLETE, $DB->get_field('course_modules_completion', 'completionstate', $params));
    }

    /**
     * Closing a failed session on the last attempt completes the activity (exhausted path).
     */
    public function test_close_last_failed_session_completes_activity(): void {
        global $DB;

        [$course, $student, $aidialogue] = $this->create_activity(1);
        $session = $this->add_session($aidialogue, $student->id, 'active', 'partial');

        (new session_manager())->close_session($session->id, 'student report', 'teacher report', 40.0);

        $params = ['coursemoduleid' => $aidialogue->cmid, 'userid' => $student->id];
        $this->assertEquals(COMPLETION_COMPLETE, $DB->get_field('course_modules_completion', 'completionstate', $params));
    }
}
