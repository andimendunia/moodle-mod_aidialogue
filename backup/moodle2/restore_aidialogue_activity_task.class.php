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
 * Defines the restore_aidialogue_activity_task class.
 *
 * @package    mod_aidialogue
 * @category   backup
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aidialogue/backup/moodle2/restore_aidialogue_stepslib.php');

/**
 * Provides the settings and steps to perform one complete restore of an AI Dialogue instance.
 */
class restore_aidialogue_activity_task extends restore_activity_task {
    /**
     * No particular settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the restore steps: one structure step reading aidialogue.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_aidialogue_activity_structure_step('aidialogue_structure', 'aidialogue.xml'));
    }

    /**
     * Defines the contents that must be processed by the link decoder.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('aidialogue', ['intro'], 'aidialogue');
        return $contents;
    }

    /**
     * Defines the decoding rules for links belonging to the activity.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule('AIDIALOGUEVIEWBYID', '/mod/aidialogue/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('AIDIALOGUEINDEX', '/mod/aidialogue/index.php?id=$1', 'course');
        return $rules;
    }
}
