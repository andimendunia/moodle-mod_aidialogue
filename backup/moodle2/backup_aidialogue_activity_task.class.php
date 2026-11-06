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
 * Defines the backup_aidialogue_activity_task class.
 *
 * @package    mod_aidialogue
 * @category   backup
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aidialogue/backup/moodle2/backup_aidialogue_stepslib.php');

/**
 * Provides the steps to perform one complete backup of an AI Dialogue instance.
 */
class backup_aidialogue_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Defines a backup step to store the instance data in the aidialogue.xml file.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_aidialogue_activity_structure_step('aidialogue_structure', 'aidialogue.xml'));
    }

    /**
     * Encodes URLs to the index.php and view.php scripts.
     *
     * @param string $content HTML text that may contain URLs to the activity scripts.
     * @return string The content with the URLs encoded.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of AI Dialogue activities in a course.
        $search = '/(' . $base . '\/mod\/aidialogue\/index.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@AIDIALOGUEINDEX*$2@$', $content);

        // Link to an AI Dialogue activity by course module id.
        $search = '/(' . $base . '\/mod\/aidialogue\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@AIDIALOGUEVIEWBYID*$2@$', $content);

        return $content;
    }
}
