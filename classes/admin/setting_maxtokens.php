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

namespace mod_aidialogue\admin;

use admin_setting_configtext;
use mod_aidialogue\local\ai_client;

/**
 * Admin setting for AI max_tokens with minimum limit validation.
 *
 * @package    mod_aidialogue
 * @category   admin
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_maxtokens extends admin_setting_configtext {
    /**
     * Validate data before storage, ensuring value is between the minimum and maximum token thresholds.
     *
     * @param string $data The data to validate.
     * @return mixed true if ok, or string with error message.
     */
    public function validate($data) {
        $parentvalidation = parent::validate($data);
        if ($parentvalidation !== true) {
            return $parentvalidation;
        }

        if ((int) $data < ai_client::MIN_MAX_TOKENS) {
            return get_string('err_maxtokensmin', 'mod_aidialogue', ai_client::MIN_MAX_TOKENS);
        }

        if ((int) $data > ai_client::MAX_MAX_TOKENS) {
            return get_string('err_maxtokensmax', 'mod_aidialogue', ai_client::MAX_MAX_TOKENS);
        }

        return true;
    }
}
