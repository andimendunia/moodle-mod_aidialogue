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

namespace mod_aidialogue\local;

use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * Thin wrapper around an OpenAI-compatible chat completions endpoint.
 *
 * Supports any provider that implements the OpenAI chat/completions API
 * (OpenAI, Azure OpenAI, Ollama, LM Studio, etc.).
 *
 * Deliberately minimal — one public method, synchronous, no streaming.
 * All error conditions throw typed moodle_exceptions so callers can
 * present sensible error messages without catching generic \Exceptions.
 *
 * HTTP requests go through Moodle's {@see http_client} (a Guzzle wrapper),
 * which honours proxy and security settings. The client may be injected for
 * testability; otherwise it is resolved from the DI container on demand.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_client {
    /** @var int Maximum seconds to wait for a response from the AI endpoint. */
    private const TIMEOUT_SECONDS = 60;

    /** @var int Default maximum tokens to request in the completion response. */
    public const DEFAULT_MAX_TOKENS = 1024;

    /** @var int Minimum allowed value for the maximum tokens setting. */
    public const MIN_MAX_TOKENS = 256;

    /** @var int Maximum allowed value for the maximum tokens setting. */
    public const MAX_MAX_TOKENS = 65536;

    /**
     * Normalise a max_tokens value, enforcing the minimum threshold with fallback.
     *
     * @param int $tokens Token limit to normalise.
     * @return int Validated token limit, or DEFAULT_MAX_TOKENS if below minimum.
     */
    public static function normalise_max_tokens(int $tokens): int {
        return $tokens >= self::MIN_MAX_TOKENS ? $tokens : self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Temperature for all chat completions requests.
     *
     * 0.4 gives deterministic, protocol-following responses suited to structured
     * assessment. Higher values increase creativity but risk the AI ignoring the
     * [MOVE:...] protocol or producing inconsistent criterion outcomes.
     */
    private const TEMPERATURE = 0.4;

    /** @var http_client|null Injected HTTP client; null defers to the DI container. */
    private readonly ?http_client $httpclient;

    /**
     * Constructor.
     *
     * @param http_client|null $httpclient HTTP client used for requests. When null the
     *                                      client is resolved from the DI container at call time.
     */
    public function __construct(?http_client $httpclient = null) {
        $this->httpclient = $httpclient;
    }

    /**
     * Send a messages array to the AI and return the assistant's reply text.
     *
     * The $messages array follows the standard OpenAI format:
     *   [
     *     ['role' => 'system',    'content' => '...'],
     *     ['role' => 'user',      'content' => '...'],
     *     ['role' => 'assistant', 'content' => '...'],
     *     ...
     *   ]
     *
     * @param string $aiurl     Base URL of the endpoint, e.g. https://api.openai.com/v1
     *                          The path /chat/completions is appended automatically.
     * @param string $aiapikey  Bearer token / API key.
     * @param string $aimodel   Model name, e.g. 'gpt-4o'.
     * @param array  $messages  Array of message objects (role + content).
     * @param int    $maxtokens Maximum tokens to request in completion response.
     * @return string  The assistant's reply text (trimmed).
     * @throws \moodle_exception On HTTP error, timeout, or malformed response.
     */
    public function chat(
        string $aiurl,
        string $aiapikey,
        string $aimodel,
        array $messages,
        int $maxtokens = self::DEFAULT_MAX_TOKENS,
    ): string {
        $endpoint = rtrim($aiurl, '/') . '/chat/completions';

        $payload = json_encode([
            'model'       => $aimodel,
            'messages'    => $messages,
            'max_tokens'  => self::normalise_max_tokens($maxtokens),
            'temperature' => self::TEMPERATURE,
        ]);

        $request = new Request(
            method: 'POST',
            uri: $endpoint,
            headers: [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $aiapikey,
            ],
            body: $payload,
        );

        try {
            $response = $this->get_client()->send($request, [
                RequestOptions::TIMEOUT     => self::TIMEOUT_SECONDS,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $e) {
            throw new \moodle_exception('error:aicurlfailed', 'mod_aidialogue', '', $e->getMessage());
        }

        $httpcode = $response->getStatusCode();

        if ($httpcode === 401) {
            throw new \moodle_exception('error:aiunauthorised', 'mod_aidialogue');
        }

        if ($httpcode === 429) {
            throw new \moodle_exception('error:airatelimited', 'mod_aidialogue');
        }

        $rawresponse = (string) $response->getBody();

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \moodle_exception(
                'error:aihttperror',
                'mod_aidialogue',
                '',
                $this->extract_api_error($rawresponse, $httpcode),
            );
        }

        $decoded = json_decode($rawresponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('error:aiinvalidjson', 'mod_aidialogue');
        }

        $finishreason = $decoded['choices'][0]['finish_reason'] ?? null;
        if ($finishreason === 'length') {
            throw new \moodle_exception('error:airesponsetruncated', 'mod_aidialogue');
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;

        if ($text === null) {
            throw new \moodle_exception('error:aiemptyresponse', 'mod_aidialogue');
        }

        return trim($text);
    }

    /**
     * Return the HTTP client, resolving it from the DI container if none was injected.
     *
     * @return http_client
     */
    private function get_client(): http_client {
        return $this->httpclient ?? \core\di::get(http_client::class);
    }

    /**
     * Extract a human-readable error message from an API error response body.
     *
     * OpenAI-compatible APIs return JSON with an error.message field on 4xx errors.
     * 5xx responses typically have no structured body, so we fall back to the status code.
     *
     * @param string $body   Raw response body.
     * @param int    $status HTTP status code.
     * @return string
     */
    private function extract_api_error(string $body, int $status): string {
        if ($status >= 500) {
            return (string) $status;
        }
        $decoded = json_decode($body, true);
        return $decoded['error']['message'] ?? (string) $status;
    }
}
