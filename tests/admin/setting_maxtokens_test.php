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

use mod_aidialogue\local\ai_client;

/**
 * Unit tests for setting_maxtokens admin setting validation.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(setting_maxtokens::class)]
final class setting_maxtokens_test extends \basic_testcase {
    /**
     * Test validation accepts valid token counts equal to or above minimum.
     */
    public function test_validate_valid_values(): void {
        $setting = new setting_maxtokens(
            'mod_aidialogue/maxtokens',
            'Max tokens',
            'Desc',
            ai_client::DEFAULT_MAX_TOKENS,
            PARAM_INT,
        );

        $this->assertTrue($setting->validate('256'));
        $this->assertTrue($setting->validate('1024'));
        $this->assertTrue($setting->validate('4096'));
        $this->assertTrue($setting->validate(256));
    }

    /**
     * Test validation rejects values below minimum threshold.
     */
    public function test_validate_below_minimum(): void {
        $setting = new setting_maxtokens(
            'mod_aidialogue/maxtokens',
            'Max tokens',
            'Desc',
            ai_client::DEFAULT_MAX_TOKENS,
            PARAM_INT,
        );

        $expectederr = get_string('err_maxtokensmin', 'mod_aidialogue', ai_client::MIN_MAX_TOKENS);
        $this->assertSame($expectederr, $setting->validate('100'));
        $this->assertSame($expectederr, $setting->validate('255'));
        $this->assertSame($expectederr, $setting->validate('0'));
        $this->assertSame($expectederr, $setting->validate('-5'));
    }

    /**
     * Test validation rejects values above the maximum threshold.
     */
    public function test_validate_above_maximum(): void {
        $setting = new setting_maxtokens(
            'mod_aidialogue/maxtokens',
            'Max tokens',
            'Desc',
            ai_client::DEFAULT_MAX_TOKENS,
            PARAM_INT,
        );

        $this->assertTrue($setting->validate((string) ai_client::MAX_MAX_TOKENS));
        $expectederr = get_string('err_maxtokensmax', 'mod_aidialogue', ai_client::MAX_MAX_TOKENS);
        $this->assertSame($expectederr, $setting->validate((string) (ai_client::MAX_MAX_TOKENS + 1)));
        $this->assertSame($expectederr, $setting->validate('10000000'));
    }

    /**
     * Test validation handles empty, decimal, and non-numeric input.
     */
    public function test_validate_invalid_and_edge_inputs(): void {
        $setting = new setting_maxtokens(
            'mod_aidialogue/maxtokens',
            'Max tokens',
            'Desc',
            ai_client::DEFAULT_MAX_TOKENS,
            PARAM_INT,
        );

        // Empty string fails parent validation.
        $this->assertSame(get_string('validateerror', 'admin'), $setting->validate(''));

        // Non-numeric string fails parent validation.
        $this->assertSame(get_string('validateerror', 'admin'), $setting->validate('abc'));

        // Decimal string '1.5' fails integer validation in parent::validate.
        $this->assertSame(get_string('validateerror', 'admin'), $setting->validate('1.5'));
    }
}
