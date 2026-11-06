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

namespace mod_aidialogue\tests;

/**
 * Shared test helper that scripts the replies of a mocked ai_client.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_reply_helper {
    /**
     * Build a callback for ai_client::chat() that plays the given replies in order.
     *
     * A reply that is a Throwable is thrown instead of returned, which simulates an
     * AI call that fails (network error, timeout, truncated response). The call that
     * throws still counts as a call, so exactly(N) must include it.
     *
     * @param array<string|\Throwable> $replies Replies in call order.
     * @return \Closure
     */
    public static function script(array $replies): \Closure {
        $call = 0;
        return function () use (&$call, $replies): string {
            if (!array_key_exists($call, $replies)) {
                throw new \coding_exception('Unexpected AI call number ' . ($call + 1));
            }
            $reply = $replies[$call++];
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            return $reply;
        };
    }

    /**
     * Build the exception that stands for a failed AI call.
     *
     * @return \moodle_exception
     */
    public static function failure(): \moodle_exception {
        return new \moodle_exception('error:ajax', 'mod_aidialogue');
    }
}
