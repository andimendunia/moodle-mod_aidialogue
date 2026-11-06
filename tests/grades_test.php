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
 * Gradebook tests for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue;

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\session_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/aidialogue/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Gradebook tests for mod_aidialogue.
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_get_user_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_update_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_grade_item_update')]
#[\PHPUnit\Framework\Attributes\CoversFunction('aidialogue_grade_item_delete')]
#[\PHPUnit\Framework\Attributes\CoversClass(session_manager::class)]
final class grades_test extends \advanced_testcase {
    /**
     * Creates a course, a student and an activity.
     *
     * @param array $record Extra activity fields.
     * @return array [course, student, activity]
     */
    private function create_activity(array $record = []): array {
        $this->resetAfterTest();
        set_config('aiurl', 'https://ai.example.invalid/v1', 'mod_aidialogue');
        set_config('aiapikey', 'not-a-real-key', 'mod_aidialogue');
        set_config('aimodel', 'test-model', 'mod_aidialogue');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $aidialogue = $generator->create_module('aidialogue', ['course' => $course->id] + $record);
        return [$course, $student, $aidialogue];
    }

    /**
     * Adds a finished session with an AI grade, optionally graded by a teacher.
     *
     * @param \stdClass $aidialogue Activity record.
     * @param int $userid Student id.
     * @param float|null $teachergrade Teacher grade as a percentage, or null when ungraded.
     * @param string $status Session status.
     * @return \stdClass The session record.
     */
    private function add_session(\stdClass $aidialogue, int $userid, ?float $teachergrade, string $status = 'complete'): \stdClass {
        global $DB;

        $manager = new session_manager();
        $config = activity_config::load_from_db($aidialogue->id);
        $session = $manager->create_session($aidialogue->id, $userid, $config);
        $DB->set_field('aidialogue_session', 'status', $status, ['id' => $session->id]);
        $DB->set_field('aidialogue_session', 'aigrade', 90, ['id' => $session->id]);
        if ($teachergrade !== null) {
            $manager->save_teacher_grade($session->id, $teachergrade);
        }
        return $session;
    }

    /**
     * Reads the student's grade from the gradebook.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $aidialogue Activity record.
     * @param int $userid Student id.
     * @return float|null The final grade, or null when there is none.
     */
    private function get_gradebook_grade(\stdClass $course, \stdClass $aidialogue, int $userid): ?float {
        $info = grade_get_grades($course->id, 'mod', 'aidialogue', $aidialogue->id, $userid);
        $grade = $info->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * The activity supports grades and creates a grade item with its maximum grade.
     */
    public function test_grade_item_created(): void {
        [$course, , $aidialogue] = $this->create_activity(['grade' => 20]);

        $this->assertTrue((bool) plugin_supports('mod', 'aidialogue', FEATURE_GRADE_HAS_GRADE));
        $gradeitem = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'aidialogue',
            'iteminstance' => $aidialogue->id, 'itemnumber' => 0,
        ]);
        $this->assertNotFalse($gradeitem);
        $this->assertEquals(20, $gradeitem->grademax);
        $this->assertEquals(GRADE_TYPE_VALUE, $gradeitem->gradetype);
    }

    /**
     * The AI grade never reaches the gradebook, and a finished session waits for the teacher.
     */
    public function test_ai_grade_alone_is_not_graded(): void {
        [$course, $student, $aidialogue] = $this->create_activity();
        $session = $this->add_session($aidialogue, $student->id, null);

        (new session_manager())->close_session($session->id, 'student report', 'teacher report', 95.0);
        aidialogue_update_grades($aidialogue);

        $this->assertNull($this->get_gradebook_grade($course, $aidialogue, $student->id));
    }

    /**
     * The teacher grade is rescaled to the maximum grade and cleared again with null.
     */
    public function test_teacher_grade_rescaled_and_cleared(): void {
        [$course, $student, $aidialogue] = $this->create_activity(['grade' => 20]);
        $session = $this->add_session($aidialogue, $student->id, null);
        $manager = new session_manager();

        $manager->save_teacher_grade($session->id, 75.0);
        $this->assertEqualsWithDelta(15.0, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);

        $manager->save_teacher_grade($session->id, null);
        $this->assertNull($this->get_gradebook_grade($course, $aidialogue, $student->id));
    }

    /**
     * A teacher grade of zero is a real zero, not "no grade".
     */
    public function test_zero_is_a_real_grade(): void {
        [$course, $student, $aidialogue] = $this->create_activity();
        $session = $this->add_session($aidialogue, $student->id, 0.0);

        $this->assertEqualsWithDelta(0.0, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);
        $this->assertNotNull($this->get_gradebook_grade($course, $aidialogue, $student->id));
    }

    /**
     * Grades above 100 or below 0 are limited to the 0-100 range.
     */
    public function test_teacher_grade_is_limited(): void {
        global $DB;

        [, $student, $aidialogue] = $this->create_activity();
        $session = $this->add_session($aidialogue, $student->id, null);

        (new session_manager())->save_teacher_grade($session->id, 250.0);
        $this->assertEquals(100, $DB->get_field('aidialogue_session', 'teachergrade', ['id' => $session->id]));
    }

    /**
     * The grading method picks between the teacher-graded attempts only.
     *
     * @param int $method Grading method constant.
     * @param float $expected Expected percentage.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grading_method_provider')]
    public function test_grading_method(int $method, float $expected): void {
        [$course, $student, $aidialogue] = $this->create_activity(['grademethod' => $method]);
        // Attempt 1: 40, attempt 2: not graded, attempt 3: 80, attempt 4: 60, attempt 5: still active and ungraded.
        $this->add_session($aidialogue, $student->id, 40.0);
        $this->add_session($aidialogue, $student->id, null);
        $this->add_session($aidialogue, $student->id, 80.0);
        $this->add_session($aidialogue, $student->id, 60.0);
        $this->add_session($aidialogue, $student->id, null, 'active');

        $this->assertEqualsWithDelta($expected, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);
    }

    /**
     * Data for test_grading_method.
     *
     * @return array
     */
    public static function grading_method_provider(): array {
        return [
            'highest' => [AIDIALOGUE_GRADEHIGHEST, 80.0],
            'average of graded attempts' => [AIDIALOGUE_GRADEAVERAGE, 60.0],
            'first graded attempt' => [AIDIALOGUE_GRADEFIRST, 40.0],
            'last graded attempt' => [AIDIALOGUE_GRADELAST, 60.0],
        ];
    }

    /**
     * A teacher grade on a session that is not finished does not count.
     */
    public function test_unfinished_session_is_ignored(): void {
        [$course, $student, $aidialogue] = $this->create_activity();
        $this->add_session($aidialogue, $student->id, 70.0, 'active');

        $this->assertNull($this->get_gradebook_grade($course, $aidialogue, $student->id));
    }

    /**
     * Clearing one teacher grade keeps the grade from the other graded attempts.
     */
    public function test_clearing_one_attempt_keeps_the_other(): void {
        [$course, $student, $aidialogue] = $this->create_activity();
        $first = $this->add_session($aidialogue, $student->id, 40.0);
        $this->add_session($aidialogue, $student->id, 80.0);
        $manager = new session_manager();

        $manager->save_teacher_grade($first->id, null);
        $this->assertEqualsWithDelta(80.0, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);
    }

    /**
     * Changing the maximum grade recalculates every student.
     */
    public function test_changing_max_grade_recalculates(): void {
        global $DB;

        [$course, $student, $aidialogue] = $this->create_activity(['grade' => 100]);
        $this->add_session($aidialogue, $student->id, 50.0);
        $this->assertEqualsWithDelta(50.0, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);

        $data = $DB->get_record('aidialogue', ['id' => $aidialogue->id]);
        $data->grade = 10;
        $data->instance = $aidialogue->id;
        $data->coursemodule = $aidialogue->cmid;
        foreach ($DB->get_records('aidialogue_criterion', ['aidialogueid' => $aidialogue->id], 'sortorder') as $criterion) {
            $data->criterionid[] = $criterion->id;
            $data->bloomslevel[] = $criterion->bloomslevel;
            $data->description[] = $criterion->description;
            $data->minturns[] = $criterion->minturns;
            $data->maxturns[] = $criterion->maxturns;
        }
        aidialogue_update_instance($data, null);

        $this->assertEqualsWithDelta(5.0, $this->get_gradebook_grade($course, $aidialogue, $student->id), 0.001);
    }

    /**
     * An activity with no grade gets no grade item, and teacher grades go nowhere.
     */
    public function test_ungraded_activity(): void {
        [$course, $student, $aidialogue] = $this->create_activity(['grade' => 0]);
        $this->add_session($aidialogue, $student->id, 70.0);

        $gradeitem = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'aidialogue',
            'iteminstance' => $aidialogue->id, 'itemnumber' => 0,
        ]);
        $this->assertTrue($gradeitem === false || $gradeitem->gradetype == GRADE_TYPE_NONE);
    }

    /**
     * Deleting the activity removes its grade item.
     */
    public function test_delete_instance_removes_grade_item(): void {
        [$course, , $aidialogue] = $this->create_activity();

        \core_courseformat\formatactions::cm($course->id)->delete($aidialogue->cmid);

        $this->assertFalse(\grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'aidialogue',
            'iteminstance' => $aidialogue->id,
        ]));
    }
}
