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

namespace mod_aidialogue\output;

/**
 * Tests for the mod_aidialogue renderer.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(renderer::class)]
final class renderer_test extends \advanced_testcase {
    /**
     * The move badge shows the label of each move, and nothing for a student turn.
     *
     * @param string|null $move The move stored on the turn.
     * @param string $expected The expected label, or an empty string for no badge.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('move_badge_provider')]
    public function test_review_move_badge(?string $move, string $expected): void {
        global $PAGE;

        $this->resetAfterTest();
        $badge = $PAGE->get_renderer('mod_aidialogue')->review_move_badge($move);

        if ($expected === '') {
            $this->assertSame('', $badge);
        } else {
            $this->assertStringContainsString($expected, $badge);
            $this->assertStringContainsString('aidialogue-move-badge', $badge);
        }
    }

    /**
     * Data provider for test_review_move_badge.
     *
     * @return array[]
     */
    public static function move_badge_provider(): array {
        return [
            'offer' => ['probe_scaffold', 'Offer hint or review'],
            'hint' => ['probe_hint', 'Hint provided'],
            'student turn' => [null, ''],
        ];
    }
}
