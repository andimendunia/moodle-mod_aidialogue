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
 * Backup and restore tests for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aidialogue;

use core_courseformat\formatactions;
use restore_date_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Backup and restore tests for mod_aidialogue.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_aidialogue_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_aidialogue_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_aidialogue_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_aidialogue_activity_structure_step::class)]
final class backup_restore_test extends restore_date_testcase {
    /**
     * Adds one student session with an opening turn, a criterion turn, a closing turn and one result per criterion.
     *
     * @param \stdClass $aidialogue The activity record.
     * @param int $userid The student id.
     * @return \stdClass The session record.
     */
    private function add_session(\stdClass $aidialogue, int $userid): \stdClass {
        global $DB;

        $criteria = array_values($DB->get_records('aidialogue_criterion', ['aidialogueid' => $aidialogue->id], 'sortorder'));
        $now = time();

        $session = (object) [
            'aidialogueid' => $aidialogue->id,
            'userid' => $userid,
            'attemptnumber' => 1,
            'status' => 'complete',
            'studentreport' => 'Student report text',
            'teacherreport' => 'Teacher report text',
            'aigrade' => 80,
            'teachergrade' => 90,
            'timecreated' => $now,
            'timestarted' => $now,
            'timefinished' => $now,
            'earlyexit' => 0,
            'timemodified' => $now,
        ];
        $session->id = $DB->insert_record('aidialogue_session', $session);

        // The opening and closing turns have no criterion.
        $turns = [
            [null, 'ai', 'session_open', 'Hello'],
            [$criteria[0]->id, 'student', null, 'My answer'],
            [null, 'ai', 'session_close', 'Bye'],
        ];
        foreach ($turns as $number => [$criterionid, $role, $move, $content]) {
            $DB->insert_record('aidialogue_turn', (object) [
                'sessionid' => $session->id,
                'criterionid' => $criterionid,
                'role' => $role,
                'move' => $move,
                'content' => $content,
                'turnnumber' => $number + 1,
                'timecreated' => $now,
            ]);
        }

        foreach ($criteria as $criterion) {
            $DB->insert_record('aidialogue_criterion_result', (object) [
                'sessionid' => $session->id,
                'criterionid' => $criterion->id,
                'status' => 'met',
                'evidence' => 'Evidence for ' . $criterion->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        return $session;
    }

    /**
     * A course backup with user data restores the activity, criteria, sessions, turns and results.
     */
    public function test_course_backup_restore_with_user_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $aidialogue = $this->getDataGenerator()->create_module('aidialogue', [
            'course' => $course->id,
            'maxattempts' => 3,
            'completionpassorexhaust' => 1,
        ]);
        $session = $this->add_session($aidialogue, $student->id);

        $newcourseid = $this->backup_and_restore($course);

        $new = $DB->get_record('aidialogue', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals($aidialogue->id, $new->id);
        $this->assertEquals($aidialogue->name, $new->name);
        $this->assertEquals('Test knowledge text.', $new->knowledgetext);
        $this->assertEquals(3, $new->maxattempts);
        $this->assertEquals(1, $new->completionpassorexhaust);

        // Criteria are copied with new ids and the same content.
        $newcriteria = array_values($DB->get_records('aidialogue_criterion', ['aidialogueid' => $new->id], 'sortorder'));
        $this->assertCount(2, $newcriteria);
        $this->assertEquals('Explains the main idea.', $newcriteria[0]->description);
        $this->assertEquals(8, $newcriteria[1]->bloomslevel);
        $oldcriterionids = $DB->get_fieldset_select('aidialogue_criterion', 'id', 'aidialogueid = ?', [$aidialogue->id]);
        $this->assertEmpty(array_intersect($oldcriterionids, array_column($newcriteria, 'id')));

        // The session keeps the user and all its fields.
        $newsession = $DB->get_record('aidialogue_session', ['aidialogueid' => $new->id], '*', MUST_EXIST);
        $this->assertNotEquals($session->id, $newsession->id);
        $this->assertEquals($student->id, $newsession->userid);
        $this->assertEquals('Student report text', $newsession->studentreport);
        $this->assertEquals('Teacher report text', $newsession->teacherreport);
        $this->assertEquals(80, $newsession->aigrade);
        $this->assertEquals(90, $newsession->teachergrade);

        // Turns point at the new session. Criterion turns use the new criterion, other turns keep NULL.
        $turns = array_values($DB->get_records('aidialogue_turn', ['sessionid' => $newsession->id], 'turnnumber'));
        $this->assertCount(3, $turns);
        $this->assertNull($turns[0]->criterionid);
        $this->assertEquals($newcriteria[0]->id, $turns[1]->criterionid);
        $this->assertNull($turns[2]->criterionid);
        $this->assertEquals('session_close', $turns[2]->move);

        // One result per new criterion, none left pointing at the old ones.
        $results = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $newsession->id], '', 'criterionid, status');
        $this->assertEqualsCanonicalizing(array_column($newcriteria, 'id'), array_keys($results));

        // The original data is untouched.
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $aidialogue->id]));
    }

    /**
     * Duplicating an activity copies the setup but not the student data.
     */
    public function test_duplicate_copies_setup_without_user_data(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $aidialogue = $this->getDataGenerator()->create_module('aidialogue', ['course' => $course->id]);
        $this->add_session($aidialogue, $student->id);

        $newcm = formatactions::cm($course->id)->duplicate(cmid: $aidialogue->cmid);

        $this->assertNotEquals($aidialogue->cmid, $newcm->id);
        $new = $DB->get_record('aidialogue', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertNotEquals($aidialogue->id, $new->id);
        $this->assertEquals('Test knowledge text.', $new->knowledgetext);
        $this->assertEquals(2, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $new->id]));
        $this->assertEquals(0, $DB->count_records('aidialogue_session', ['aidialogueid' => $new->id]));

        // The original keeps its own data.
        $this->assertEquals(2, $DB->count_records('aidialogue_criterion', ['aidialogueid' => $aidialogue->id]));
        $this->assertEquals(1, $DB->count_records('aidialogue_session', ['aidialogueid' => $aidialogue->id]));
        $this->assertEquals(3, $DB->count_records('aidialogue_turn'));
    }

    /**
     * The activity declares that it supports backup.
     */
    public function test_supports_backup(): void {
        $this->assertTrue(plugin_supports('mod', 'aidialogue', FEATURE_BACKUP_MOODLE2));
    }
}
