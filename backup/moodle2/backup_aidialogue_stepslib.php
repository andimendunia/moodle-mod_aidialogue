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
 * Defines the backup structure step for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @category   backup
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete AI Dialogue structure for backup, with file and id annotations.
 */
class backup_aidialogue_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the backup structure.
     *
     * @return backup_nested_element The root element wrapped in the standard activity structure.
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $aidialogue = new backup_nested_element('aidialogue', ['id'], [
            'name', 'intro', 'introformat', 'timecreated', 'timemodified', 'knowledgetext',
            'maxattempts', 'completionpassed', 'completionexhausted',
        ]);

        $criteria = new backup_nested_element('criteria');
        $criterion = new backup_nested_element('criterion', ['id'], [
            'sortorder', 'bloomslevel', 'description', 'minturns', 'maxturns',
            'timecreated', 'timemodified',
        ]);

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'userid', 'attemptnumber', 'status', 'studentreport', 'teacherreport', 'aigrade',
            'teachergrade', 'timecreated', 'timestarted', 'timefinished', 'earlyexit', 'timemodified',
        ]);

        $turns = new backup_nested_element('turns');
        $turn = new backup_nested_element('turn', ['id'], [
            'criterionid', 'role', 'move', 'content', 'turnnumber', 'timecreated',
        ]);

        $results = new backup_nested_element('results');
        $result = new backup_nested_element('result', ['id'], [
            'criterionid', 'status', 'evidence', 'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $aidialogue->add_child($criteria);
        $criteria->add_child($criterion);

        $aidialogue->add_child($sessions);
        $sessions->add_child($session);

        $session->add_child($turns);
        $turns->add_child($turn);

        $session->add_child($results);
        $results->add_child($result);

        // Define sources.
        $aidialogue->set_source_table('aidialogue', ['id' => backup::VAR_ACTIVITYID]);
        $criterion->set_source_table('aidialogue_criterion', ['aidialogueid' => backup::VAR_PARENTID], 'sortorder ASC');

        // Sessions, turns and results only exist in the backup when user data is included.
        if ($userinfo) {
            $session->set_source_table('aidialogue_session', ['aidialogueid' => backup::VAR_PARENTID], 'id ASC');
            $turn->set_source_table('aidialogue_turn', ['sessionid' => backup::VAR_PARENTID], 'turnnumber ASC');
            $result->set_source_table('aidialogue_criterion_result', ['sessionid' => backup::VAR_PARENTID], 'id ASC');
        }

        // Define id annotations.
        $session->annotate_ids('user', 'userid');

        // Define file annotations.
        $aidialogue->annotate_files('mod_aidialogue', 'intro', null);

        return $this->prepare_activity_structure($aidialogue);
    }
}
