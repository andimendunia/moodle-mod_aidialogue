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

/**
 * Tests for the capability that allows grading student sessions.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_capability_test extends \advanced_testcase {
    /**
     * Seeing the class report must not be enough to grade.
     */
    public function test_viewreport_alone_cannot_grade(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $roleid = $generator->create_role(['shortname' => 'aidauditor']);
        $auditor = $generator->create_and_enrol($course, 'aidauditor');
        $module = $generator->create_module('aidialogue', ['course' => $course->id]);
        $context = \context_module::instance($module->cmid);

        assign_capability('mod/aidialogue:viewreport', CAP_ALLOW, $roleid, $context->id, true);

        $this->assertTrue(has_capability('mod/aidialogue:viewreport', $context, $auditor));
        $this->assertFalse(has_capability('mod/aidialogue:grade', $context, $auditor));
    }

    /**
     * Teachers, editing teachers and managers can grade, students cannot.
     */
    public function test_default_roles(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $module = $generator->create_module('aidialogue', ['course' => $course->id]);
        $context = \context_module::instance($module->cmid);

        $expected = ['student' => false, 'teacher' => true, 'editingteacher' => true];
        foreach ($expected as $archetype => $cangrade) {
            $user = $generator->create_and_enrol($course, $archetype);
            $this->assertSame(
                $cangrade,
                has_capability('mod/aidialogue:grade', $context, $user),
                $archetype
            );
        }
    }
}
