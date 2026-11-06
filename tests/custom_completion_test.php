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
     * @param float $gradepass Grade to pass in the gradebook (0 = none).
     * @return array [course, student, activity]
     */
    private function create_activity(int $maxattempts, float $gradepass = 50): array {
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
        if ($gradepass > 0) {
            $gradeitem = \grade_item::fetch([
                'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'aidialogue',
                'iteminstance' => $aidialogue->id, 'itemnumber' => 0,
            ]);
            $gradeitem->gradepass = $gradepass;
            $gradeitem->update();
        }
        return [$course, $student, $aidialogue];
    }

    /**
     * Adds a session, optionally graded by a teacher.
     *
     * @param \stdClass $aidialogue Activity record.
     * @param int $userid Student id.
     * @param string $sessionstatus Session status (complete, active).
     * @param float|null $teachergrade Teacher grade as a percentage, or null when ungraded.
     * @return \stdClass The session record.
     */
    private function add_session(\stdClass $aidialogue, int $userid, string $sessionstatus, ?float $teachergrade): \stdClass {
        global $DB;

        $manager = new session_manager();
        $config = activity_config::load_from_db($aidialogue->id);
        $session = $manager->create_session($aidialogue->id, $userid, $config);
        $DB->set_field('aidialogue_session', 'status', $sessionstatus, ['id' => $session->id]);
        $DB->set_field('aidialogue_session', 'aigrade', 100, ['id' => $session->id]);
        if ($teachergrade !== null) {
            $manager->save_teacher_grade($session->id, $teachergrade);
        }
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
     * The single rule is met by a teacher grade at or above the grade to pass, or by using all attempts.
     *
     * @param int $maxattempts Attempt limit.
     * @param float $gradepass Grade to pass (0 = none).
     * @param array $sessions List of [session status, teacher grade or null].
     * @param bool $expectcomplete Whether the rule should be met.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rule_state_provider')]
    public function test_rule_state(int $maxattempts, float $gradepass, array $sessions, bool $expectcomplete): void {
        [$course, $student, $aidialogue] = $this->create_activity($maxattempts, $gradepass);
        foreach ($sessions as [$sessionstatus, $teachergrade]) {
            $this->add_session($aidialogue, $student->id, $sessionstatus, $teachergrade);
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
            'no sessions' => [2, 50, [], false],
            'teacher grade above grade to pass' => [2, 50, [['complete', 80]], true],
            'teacher grade exactly the grade to pass' => [2, 50, [['complete', 50]], true],
            'teacher grade below grade to pass' => [2, 50, [['complete', 40]], false],
            'ai grade only, not yet graded by a teacher' => [2, 50, [['complete', null]], false],
            'below then above grade to pass' => [2, 50, [['complete', 40], ['complete', 90]], true],
            'no grade to pass, high teacher grade' => [2, 0, [['complete', 100]], false],
            'no grade to pass, all attempts used' => [2, 0, [['complete', 100], ['complete', null]], true],
            'both attempts failed (exhausted)' => [2, 50, [['complete', 10], ['complete', 20]], true],
            'one failed and one still active' => [2, 50, [['complete', 10], ['active', null]], false],
            'unlimited attempts, many failures' => [0, 50, [['complete', 10], ['complete', 20], ['complete', 30]], false],
            'unlimited attempts, passed' => [0, 50, [['complete', 70]], true],
        ];
    }

    /**
     * A teacher grading a session updates completion straight away.
     */
    public function test_teacher_grade_updates_completion(): void {
        global $DB;

        [$course, $student, $aidialogue] = $this->create_activity(0, 50);
        $session = $this->add_session($aidialogue, $student->id, 'complete', null);
        $params = ['coursemoduleid' => $aidialogue->cmid, 'userid' => $student->id];

        (new session_manager())->save_teacher_grade($session->id, 75.0);
        $this->assertEquals(COMPLETION_COMPLETE, $DB->get_field('course_modules_completion', 'completionstate', $params));

        (new session_manager())->save_teacher_grade($session->id, null);
        $this->assertEquals(COMPLETION_INCOMPLETE, $DB->get_field('course_modules_completion', 'completionstate', $params));
    }

    /**
     * Closing a session updates completion straight away, without the student viewing the activity.
     *
     * Here it is the last allowed attempt, so closing it completes the activity.
     */
    public function test_close_session_updates_completion(): void {
        global $DB;

        [$course, $student, $aidialogue] = $this->create_activity(1);
        $session = $this->add_session($aidialogue, $student->id, 'active', null);

        $params = ['coursemoduleid' => $aidialogue->cmid, 'userid' => $student->id];
        $this->assertFalse($DB->record_exists('course_modules_completion', $params));

        (new session_manager())->close_session($session->id, 'student report', 'teacher report', 100.0);

        $this->assertEquals(COMPLETION_COMPLETE, $DB->get_field('course_modules_completion', 'completionstate', $params));
    }
}
