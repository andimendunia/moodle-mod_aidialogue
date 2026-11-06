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

/**
 * Value object holding all activity-level configuration needed by the session engine.
 *
 * This class is the firewall between the activity form and the session engine.
 * All reads from the aidialogue and aidialogue_criterion tables go through here.
 * The engine never calls $DB directly for config.
 *
 * AI credentials (URL, API key, model) are read from plugin-wide admin settings,
 * not from per-activity columns.
 *
 * Bloom's level constants (stored as int in aidialogue_criterion.bloomslevel):
 *   BLOOMS_ANALYSE  = 1  — student must demonstrate analysis/breakdown
 *   BLOOMS_EVALUATE = 2  — student must demonstrate judgement/weighing
 *   BLOOMS_CREATE   = 4  — student must demonstrate synthesis/novel combination
 *   BLOOMS_CUSTOM   = 8  — criterion description is used verbatim as AI instruction
 *
 * @package    mod_aidialogue
 * @copyright  2026 Andi Permana <andi.permana@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_config {
    /** @var int Bloom's level: Analyse — break down, identify parts, distinguish. */
    public const BLOOMS_ANALYSE = 1;

    /** @var int Bloom's level: Evaluate — judge, critique, weigh evidence. */
    public const BLOOMS_EVALUATE = 2;

    /** @var int Bloom's level: Create — synthesise, design, propose novel solutions. */
    public const BLOOMS_CREATE = 4;

    /** @var int Bloom's level: Custom — use criterion description verbatim as AI instruction. */
    public const BLOOMS_CUSTOM = 8;

    /** @var int Activity instance ID. */
    public readonly int $id;

    /** @var string Activity name. */
    public readonly string $name;

    /** @var string Teacher-authored knowledge text used in the system prompt. */
    public readonly string $knowledgetext;

    /** @var string Base URL of the OpenAI-compatible AI endpoint. */
    public readonly string $aiurl;

    /** @var string API key for the AI endpoint. */
    public readonly string $aiapikey;

    /** @var string Model name (e.g. gpt-4o, gemini-1.5-pro). */
    public readonly string $aimodel;

    /** @var int Maximum attempts allowed per student. 0 = unlimited. */
    public readonly int $maxattempts;

    /** @var bool Whether the student completes the activity by passing or by using all attempts. */
    public readonly bool $completionpassorexhaust;

    /**
     * @var criterion_config[] Ordered array of criteria (sorted by sortorder ASC).
     *                         Index 0 = first criterion the student encounters.
     */
    public readonly array $criteria;

    /**
     * Private constructor — use the static factory methods.
     */
    private function __construct(
        int $id,
        string $name,
        string $knowledgetext,
        string $aiurl,
        string $aiapikey,
        string $aimodel,
        int $maxattempts,
        bool $completionpassorexhaust,
        array $criteria,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->knowledgetext = $knowledgetext;
        $this->aiurl = $aiurl;
        $this->aiapikey = $aiapikey;
        $this->aimodel = $aimodel;
        $this->maxattempts = $maxattempts;
        $this->completionpassorexhaust = $completionpassorexhaust;
        $this->criteria = $criteria;
    }

    /**
     * Load activity configuration from the database.
     *
     * AI credentials are read from plugin-wide admin settings.
     *
     * @param int $aidialogueid The aidialogue.id for the activity instance.
     * @return self
     * @throws \dml_exception If the record does not exist.
     * @throws \moodle_exception If AI credentials are not configured.
     */
    public static function load_from_db(int $aidialogueid): self {
        global $DB;

        $record = $DB->get_record('aidialogue', ['id' => $aidialogueid], '*', MUST_EXIST);

        $aiurl    = get_config('mod_aidialogue', 'aiurl');
        $aiapikey = get_config('mod_aidialogue', 'aiapikey');
        $aimodel  = get_config('mod_aidialogue', 'aimodel');

        if (empty($aiurl) || empty($aiapikey) || empty($aimodel)) {
            throw new \moodle_exception('error:aicredentialsmissing', 'mod_aidialogue');
        }

        $criteriarecords = $DB->get_records(
            'aidialogue_criterion',
            ['aidialogueid' => $aidialogueid],
            'sortorder ASC',
        );

        $criteria = [];
        foreach ($criteriarecords as $cr) {
            $criteria[] = new criterion_config(
                id: (int) $cr->id,
                sortorder: (int) $cr->sortorder,
                bloomslevel: (int) $cr->bloomslevel,
                description: $cr->description,
                minturns: (int) $cr->minturns,
                maxturns: (int) $cr->maxturns,
            );
        }

        return new self(
            id: (int) $record->id,
            name: $record->name,
            knowledgetext: $record->knowledgetext,
            aiurl: rtrim($aiurl, '/'),
            aiapikey: $aiapikey,
            aimodel: $aimodel,
            maxattempts: (int) $record->maxattempts,
            completionpassorexhaust: (bool) $record->completionpassorexhaust,
            criteria: $criteria,
        );
    }

    /**
     * Return the criterion at the given 0-based index, or null if out of bounds.
     *
     * @param int $index 0-based index into the sorted criteria array.
     * @return criterion_config|null
     */
    public function get_criterion(int $index): ?criterion_config {
        return $this->criteria[$index] ?? null;
    }
}
