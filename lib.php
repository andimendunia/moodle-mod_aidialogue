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
 * Library of interface functions and constants for mod_aidialogue.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * List of features supported in AI Dialogue module.
 *
 * @param string $feature FEATURE_xx constant for requested feature.
 * @return bool|null True if module supports feature, false if not, null if doesn't know.
 */
function aidialogue_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE           => MOD_ARCHETYPE_OTHER,
        FEATURE_GROUPS                  => false,
        FEATURE_GROUPINGS               => false,
        FEATURE_MOD_INTRO               => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES    => true,
        FEATURE_GRADE_HAS_GRADE         => false,
        FEATURE_GRADE_OUTCOMES          => false,
        FEATURE_BACKUP_MOODLE2          => true,
        FEATURE_SHOW_DESCRIPTION        => true,
        FEATURE_MOD_PURPOSE             => MOD_PURPOSE_COMMUNICATION,
        default                         => null,
    };
}

/**
 * Add new AI Dialogue instance.
 *
 * @param stdClass $data Form data.
 * @param mod_aidialogue_mod_form|null $mform The form instance.
 * @return int New instance id.
 */
function aidialogue_add_instance($data, $mform = null) {
    global $DB;

    $now = time();
    $data->timecreated  = $now;
    $data->timemodified = $now;

    $data->knowledgetext       = $data->knowledgetext ?? '';
    $data->maxattempts         = $data->maxattempts ?? 0;
    $data->completionpassed    = $data->completionpassed ?? 0;
    $data->completionexhausted = $data->completionexhausted ?? 0;

    $data->id = $DB->insert_record('aidialogue', $data);

    aidialogue_save_criteria((int)$data->id, $data);

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aidialogue',
        $data->id,
        $completiontimeexpected,
    );

    return $data->id;
}

/**
 * Update AI Dialogue instance.
 *
 * @param stdClass $data Form data.
 * @param mod_aidialogue_mod_form $mform The form instance.
 * @return bool True on success.
 */
function aidialogue_update_instance($data, $mform) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $data->knowledgetext       = $data->knowledgetext ?? '';
    $data->maxattempts         = $data->maxattempts ?? 0;
    $data->completionpassed    = $data->completionpassed ?? 0;
    $data->completionexhausted = $data->completionexhausted ?? 0;

    $DB->update_record('aidialogue', $data);

    aidialogue_save_criteria((int)$data->id, $data);

    $completiontimeexpected = !empty($data->completionexpected) ? $data->completionexpected : null;
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aidialogue',
        $data->id,
        $completiontimeexpected,
    );

    return true;
}

/**
 * Delete AI Dialogue instance and all associated data.
 *
 * Cascades to: aidialogue_criterion, aidialogue_session (and their child
 * tables aidialogue_turn and aidialogue_criterion_result).
 *
 * @param int $id Instance id.
 * @return bool True on success.
 */
function aidialogue_delete_instance($id) {
    global $DB;

    if (!$aidialogue = $DB->get_record('aidialogue', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('aidialogue', $id);
    if ($cm) {
        \core_completion\api::update_completion_date_event($cm->id, 'aidialogue', $id, null);
    }

    // Collect session IDs so we can delete their child rows.
    $sessionids = $DB->get_fieldset_select('aidialogue_session', 'id', 'aidialogueid = :id', ['id' => $id]);

    if (!empty($sessionids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($sessionids);
        $DB->delete_records_select('aidialogue_turn', "sessionid {$insql}", $inparams);
        $DB->delete_records_select('aidialogue_criterion_result', "sessionid {$insql}", $inparams);
    }

    $DB->delete_records('aidialogue_session', ['aidialogueid' => $id]);
    $DB->delete_records('aidialogue_criterion', ['aidialogueid' => $id]);
    $DB->delete_records('aidialogue', ['id' => $aidialogue->id]);

    return true;
}

/**
 * Upsert criteria for an AI Dialogue instance.
 *
 * Updates existing criteria (criterionid > 0), inserts new ones (criterionid == 0),
 * and deletes criteria removed by the teacher.
 *
 * @param int $aidialogueid The activity instance id.
 * @param stdClass $data Form data containing criteria arrays.
 */
function aidialogue_save_criteria(int $aidialogueid, stdClass $data): void {
    global $DB;

    if (empty($data->description)) {
        debugging(
            'aidialogue_save_criteria: no description data submitted, skipping criteria save.',
            DEBUG_DEVELOPER,
        );
        return;
    }

    $now = time();
    $sortorder = 1;
    $submittedids = [];

    // Pre-fetch all existing criterion IDs for this activity to avoid a record_exists() call per iteration.
    $existingcriterionids = array_flip(
        $DB->get_fieldset_select('aidialogue_criterion', 'id', 'aidialogueid = ?', [$aidialogueid])
    );

    foreach ($data->description as $key => $description) {
        if (trim($description) === '') {
            continue;
        }

        $minturns = (int)$data->minturns[$key];
        $maxturns = (int)$data->maxturns[$key];
        $criterionid = (int)($data->criterionid[$key] ?? 0);

        $isexistingcriterion = $criterionid > 0 && isset($existingcriterionids[$criterionid]);
        if ($isexistingcriterion) {
            $criterion = new stdClass();
            $criterion->id           = $criterionid;
            $criterion->sortorder    = $sortorder;
            $criterion->bloomslevel  = (int)$data->bloomslevel[$key];
            $criterion->description  = $description;
            $criterion->minturns     = $minturns;
            $criterion->maxturns     = $maxturns;
            $criterion->timemodified = $now;
            $DB->update_record('aidialogue_criterion', $criterion);
            $submittedids[] = $criterionid;
        } else {
            $criterion = new stdClass();
            $criterion->aidialogueid = $aidialogueid;
            $criterion->sortorder    = $sortorder;
            $criterion->bloomslevel  = (int)$data->bloomslevel[$key];
            $criterion->description  = $description;
            $criterion->minturns     = $minturns;
            $criterion->maxturns     = $maxturns;
            $criterion->timecreated  = $now;
            $criterion->timemodified = $now;
            $submittedids[] = $DB->insert_record('aidialogue_criterion', $criterion);
        }

        $sortorder++;
    }

    // Delete criteria that were removed by the teacher (not present in the submitted form).
    if ($submittedids) {
        [$notsql, $notparams] = $DB->get_in_or_equal($submittedids, SQL_PARAMS_QM, 'param', false);
        $todelete = $DB->get_fieldset_select(
            'aidialogue_criterion',
            'id',
            "aidialogueid = ? AND id $notsql",
            array_merge([$aidialogueid], $notparams),
        );
    } else {
        $todelete = $DB->get_fieldset_select(
            'aidialogue_criterion',
            'id',
            'aidialogueid = ?',
            [$aidialogueid],
        );
    }

    if ($todelete) {
        [$delsql, $delparams] = $DB->get_in_or_equal($todelete);
        // Null out criterionid on any turns that referenced a deleted criterion so the
        // transcript content is preserved (the turn still appears in get_turns()) while
        // the now-broken foreign key reference is removed.
        $DB->set_field_select('aidialogue_turn', 'criterionid', null, "criterionid $delsql", $delparams);
        $DB->delete_records_select('aidialogue_criterion_result', "criterionid $delsql", $delparams);
        $DB->delete_records_select('aidialogue_criterion', "id $delsql", $delparams);
    }
}

/**
 * Mark the activity completed and trigger the course_module_viewed event.
 *
 * @param stdClass $aidialogue Activity instance.
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param stdClass $context Context object.
 */
function aidialogue_view($aidialogue, $course, $cm, $context) {
    $params = [
        'context' => $context,
        'objectid' => $aidialogue->id,
    ];

    $event = \mod_aidialogue\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aidialogue', $aidialogue);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * List the actions that correspond to a view of this module.
 *
 * @return string[]
 */
function aidialogue_get_view_actions() {
    return ['view', 'view all'];
}

/**
 * List the actions that correspond to a post of this module.
 *
 * @return string[]
 */
function aidialogue_get_post_actions() {
    return ['update', 'add'];
}

/**
 * Return a list of page types.
 *
 * @param string $pagetype Current page type.
 * @param stdClass $parentcontext Block's parent context.
 * @param stdClass $currentcontext Current context of block.
 * @return array
 */
function aidialogue_page_type_list($pagetype, $parentcontext, $currentcontext) {
    return [
        'mod-aidialogue-*' => get_string('page-mod-aidialogue-x', 'aidialogue'),
    ];
}

/**
 * Check if the module has any update that affects the current user since a given time.
 *
 * @param cm_info $cm Course module data.
 * @param int $from The time to check updates from.
 * @param array $filter If we need to check only specific updates.
 * @return stdClass
 */
function aidialogue_check_updates_since(cm_info $cm, $from, $filter = []) {
    return course_check_module_updates_since($cm, $from, ['intro'], $filter);
}

/**
 * Given a course_module object, this function returns any "extra" information
 * that may be needed when printing this activity in a course listing.
 *
 * @param stdClass $coursemodule Course module object.
 * @return cached_cm_info|null
 */
function aidialogue_get_coursemodule_info($coursemodule) {
    global $DB;

    $aidialogue = $DB->get_record(
        'aidialogue',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionpassed, completionexhausted'
    );

    if (!$aidialogue) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $aidialogue->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('aidialogue', $aidialogue, $coursemodule->id, false);
    }

    // Advertise custom completion rules so the completion UI shows them.
    $info->customdata['customcompletionrules'] = [
        'completionpassed'    => $aidialogue->completionpassed,
        'completionexhausted' => $aidialogue->completionexhausted,
    ];

    return $info;
}

/**
 * Add "Class report" to the activity settings navigation (secondary nav).
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param navigation_node $aidialoguenode The module node in the nav tree.
 */
function aidialogue_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $aidialoguenode): void {
    global $PAGE;

    if (has_capability('mod/aidialogue:viewreport', $PAGE->context)) {
        $url = new moodle_url('/mod/aidialogue/report.php', ['id' => $PAGE->cm->id]);
        $aidialoguenode->add(
            get_string('classreport', 'aidialogue'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'mod_aidialogue_report',
        );
    }
}
