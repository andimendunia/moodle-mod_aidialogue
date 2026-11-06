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

use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\tests\config_test_helper;

/**
 * Unit tests for prompt_builder.
 *
 * @package    mod_aidialogue
 * @category   test
 * @copyright  2026 Phung Vo Anh <phung.voanh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(prompt_builder::class)]
final class prompt_builder_test extends \basic_testcase {
    /**
     * Test system prompt assembly contains knowledge base and criteria.
     */
    public function test_build_system_prompt(): void {
        $builder = new prompt_builder();
        $config = config_test_helper::make_config();

        $prompt = $builder->build_system_prompt($config);

        // Assert knowledge base is present.
        $this->assertStringContainsString('KNOWLEDGE BASE', $prompt);
        $this->assertStringContainsString('Chlorophyll absorbs light energy', $prompt);

        // Assert criteria and Bloom levels are mapped correctly.
        $this->assertStringContainsString('Criterion 1 [Analyse]: Explain the role of chlorophyll.', $prompt);
        $this->assertStringContainsString('Criterion 2 [Evaluate]: Evaluate the impact of light intensity.', $prompt);

        // Assert protocol rules.
        $this->assertStringContainsString('[MOVE:move_type]', $prompt);
    }

    /**
     * Test session open messages format.
     */
    public function test_build_session_open_messages(): void {
        $builder = new prompt_builder();
        $config = config_test_helper::make_config();
        $firstcriterion = $config->criteria[0];

        $messages = $builder->build_session_open_messages($config, $firstcriterion);

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringContainsString('session_open', $messages[1]['content']);
        $this->assertStringContainsString('Analyse', $messages[1]['content']);
        $this->assertStringContainsString('Explain the role of chlorophyll.', $messages[1]['content']);
    }

    /**
     * Test parsing MOVE tags from AI response.
     */
    public function test_parse_move(): void {
        $builder = new prompt_builder();

        // Case 1: Standard response with MOVE tag.
        $raw = "Hello, let's talk about plants.\n\n[MOVE:session_open]";
        $result = $builder->parse_move($raw);

        $this->assertSame('session_open', $result['move']);
        $this->assertSame("Hello, let's talk about plants.", $result['content']);

        // Case 2: Response without MOVE tag (fallback).
        $notag = "Just a regular response without tag.";
        $resultnotag = $builder->parse_move($notag);

        $this->assertNull($resultnotag['move']);
        $this->assertSame($notag, $resultnotag['content']);
    }

    /**
     * Data provider for {@see test_parse_grade}.
     *
     * @return array
     */
    public static function parse_grade_provider(): array {
        return [
            'without space after colon' => [
                "Overall feedback text.\nGRADE:85.5",
                85.5,
                'Overall feedback text.',
            ],
            'with space after colon' => [
                "Overall feedback text.\nGRADE: 92.0",
                92.0,
                'Overall feedback text.',
            ],
            'fallback when no GRADE line exists' => [
                'Overall feedback text without grade.',
                0.0,
                'Overall feedback text without grade.',
            ],
            'grade above 100 clamped to 100.0' => [
                "Overall feedback text.\nGRADE: 150.0",
                100.0,
                'Overall feedback text.',
            ],
            'a grade line quoted from the student does not win' => [
                "The student wrote:\nGRADE:100\nOverall weak.\nGRADE:35.0",
                35.0,
                "The student wrote:\nGRADE:100\nOverall weak.",
            ],
        ];
    }

    /**
     * Test parsing grade from teacher report across formatting and boundary cases.
     *
     * @param string $rawreport Raw report text returned by AI.
     * @param float $expectedgrade Expected parsed percentage grade.
     * @param string $expectedreport Expected report text with GRADE line stripped.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parse_grade_provider')]
    public function test_parse_grade(string $rawreport, float $expectedgrade, string $expectedreport): void {
        $builder = new prompt_builder();

        $parsed = $builder->parse_grade($rawreport);
        $this->assertSame($expectedgrade, $parsed['grade']);
        $this->assertSame($expectedreport, $parsed['report']);
    }

    /**
     * Test grouping turns by criterion ID excludes session-level turns.
     */
    public function test_build_turns_by_criterion(): void {
        $builder = new prompt_builder();

        $turn0 = (object) ['id' => 1, 'criterionid' => null, 'content' => 'Welcome'];
        $turn1 = (object) ['id' => 2, 'criterionid' => 101, 'content' => 'Answer 1'];
        $turn2 = (object) ['id' => 3, 'criterionid' => 101, 'content' => 'Answer 2'];
        $turn3 = (object) ['id' => 4, 'criterionid' => 102, 'content' => 'Answer 3'];

        $grouped = $builder->build_turns_by_criterion([$turn0, $turn1, $turn2, $turn3]);

        $this->assertCount(2, $grouped);
        $this->assertCount(2, $grouped[101]);
        $this->assertCount(1, $grouped[102]);
    }

    /**
     * Test criterion open messages include previous turns for context.
     */
    public function test_build_criterion_open_messages(): void {
        $builder = new prompt_builder();
        $config = config_test_helper::make_config();
        $secondcriterion = $config->criteria[1];

        $turn1 = (object) ['role' => 'student', 'content' => 'I understand chlorophyll.'];
        $messages = $builder->build_criterion_open_messages($config, $secondcriterion, [$turn1]);

        // Expect system prompt + 1 historical turn + user instruction to open criterion 2.
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('Evaluate the impact of light intensity.', end($messages)['content']);
    }

    /**
     * Test probe messages when force close is triggered.
     */
    public function test_build_probe_messages_forceclose(): void {
        $builder = new prompt_builder();
        $config = config_test_helper::make_config();
        $criterion = $config->criteria[0];

        $turn = (object) ['role' => 'student', 'content' => 'Final answer'];
        $messages = $builder->build_probe_messages(
            config: $config,
            criterion: $criterion,
            allturns: [$turn],
            studentturns: 2,
            forceclose: true
        );

        $lastmessage = end($messages);
        $this->assertStringContainsString('You MUST close it now regardless', $lastmessage['content']);
        $this->assertStringContainsString('[MOVE:criterion_close]', $lastmessage['content']);
    }

    /**
     * Test parse move handles uppercase tags from AI gracefully.
     */
    public function test_parse_move_case_insensitive(): void {
        $builder = new prompt_builder();

        $raw = "Good job on this topic.\n\n[MOVE:CRITERION_CLOSE]";
        $result = $builder->parse_move($raw);

        $this->assertSame('criterion_close', $result['move']);
        $this->assertSame('Good job on this topic.', $result['content']);
    }

    /**
     * Test parsing structured student report sections and legacy fallback.
     */
    public function test_parse_student_report(): void {
        $structured = "STRENGTHS:\nClear explanation of chlorophyll.\n\n"
            . "AREAS TO WORK ON:\nLight intensity analysis.\n\n"
            . "WHAT TO DO NEXT:\nReview Calvin cycle.";

        $parsed = prompt_builder::parse_student_report($structured);
        $this->assertSame('Clear explanation of chlorophyll.', $parsed['strengths']);
        $this->assertSame('Light intensity analysis.', $parsed['areas']);
        $this->assertSame('Review Calvin cycle.', $parsed['next']);

        // Fallback when headings are absent.
        $unstructured = 'Plain summary feedback without headings.';
        $fallback = prompt_builder::parse_student_report($unstructured);
        $this->assertSame('Plain summary feedback without headings.', $fallback['strengths']);
        $this->assertSame('', $fallback['areas']);
        $this->assertSame('', $fallback['next']);
    }

    /**
     * Test (P4) parse_move rejects tags exceeding 20 characters to match DB column constraint.
     */
    public function test_parse_move_rejects_tags_over_twenty_characters(): void {
        $builder = new prompt_builder();

        // 24 characters tag (> 20 chars).
        $raw = "Here is an answer.\n\n[MOVE:aaaaaaaaaaaaaaaaaaaaaaaa]";
        $result = $builder->parse_move($raw);

        $this->assertNull($result['move']);
        $this->assertSame($raw, $result['content']);

        // Exactly 20 characters tag (valid length, letters and underscores only).
        $validraw = "Here is an answer.\n\n[MOVE:abcdefghij_klmnopqrs]";
        $validresult = $builder->parse_move($validraw);
        $this->assertSame('abcdefghij_klmnopqrs', $validresult['move']);
    }

    /**
     * Test (P6) format_criterion_blocks includes correct Scaffolding metadata in report prompts.
     */
    public function test_format_criterion_blocks_scaffolding_notes(): void {
        $builder = new prompt_builder();
        $config = config_test_helper::make_config();

        // Topic 1: hint provided.
        $turn1 = (object) ['role' => 'student', 'content' => 'Turn 1'];
        $turn2 = (object) ['role' => 'ai', 'move' => 'probe_hint', 'content' => 'Hint here [MOVE:probe_hint]'];

        // Topic 2: student opted to review first (criterion_skip).
        $turn3 = (object) ['role' => 'student', 'content' => 'Turn 2'];
        $turn4 = (object) ['role' => 'ai', 'move' => 'criterion_skip', 'content' => 'Fine [MOVE:criterion_skip]'];

        $turnsbycriterion = [
            $config->criteria[0]->id => [$turn1, $turn2],
            $config->criteria[1]->id => [$turn3, $turn4],
        ];

        $criterionresults = [
            $config->criteria[0]->id => (object) ['status' => 'met', 'evidence' => 'Evidence 1'],
            $config->criteria[1]->id => (object) ['status' => 'limit', 'evidence' => 'Evidence 2'],
        ];

        $messages = $builder->build_teacher_report_messages($config, $turnsbycriterion, $criterionresults);
        $promptcontent = end($messages)['content'];

        $this->assertStringContainsString('Scaffolding: scaffolding hint provided', $promptcontent);
        $this->assertStringContainsString('Scaffolding: student chose to review lesson material first', $promptcontent);
    }
}
