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

/** Grading method: the highest teacher-graded attempt counts. */
const AIDIALOGUE_GRADEHIGHEST = 1;
/** Grading method: the average of the teacher-graded attempts counts. */
const AIDIALOGUE_GRADEAVERAGE = 2;
/** Grading method: the first teacher-graded attempt counts. */
const AIDIALOGUE_GRADEFIRST = 3;
/** Grading method: the last teacher-graded attempt counts. */
const AIDIALOGUE_GRADELAST = 4;

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
        FEATURE_GRADE_HAS_GRADE         => true,
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
    $data->grade               = $data->grade ?? 100;
    $data->grademethod         = $data->grademethod ?? AIDIALOGUE_GRADEHIGHEST;
    $data->completionpassorexhaust = $data->completionpassorexhaust ?? 0;

    $data->id = $DB->insert_record('aidialogue', $data);

    aidialogue_save_criteria((int)$data->id, $data);
    aidialogue_grade_item_update($data);

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
    $data->completionpassorexhaust = $data->completionpassorexhaust ?? 0;

    $DB->update_record('aidialogue', $data);

    aidialogue_save_criteria((int)$data->id, $data);

    // The maximum grade or the grading method may have changed, so recalculate every student.
    aidialogue_update_grades($DB->get_record('aidialogue', ['id' => $data->id], '*', MUST_EXIST));

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

    aidialogue_grade_item_delete($aidialogue);

    return true;
}

/**
 * Work out the grade each student should have in the gradebook.
 *
 * Only a teacher's grade counts. The AI grade is a suggestion and never reaches the gradebook.
 * Only finished (complete) sessions that a teacher has graded are used, and the activity's
 * grading method picks between them. A student who has finished sessions but has no teacher
 * grade gets a null grade, so a cleared teacher grade removes the gradebook entry.
 *
 * @param stdClass $aidialogue Activity record (needs id and grademethod).
 * @param int $userid One user id, or 0 for every student with a finished session.
 * @return array Grade entries keyed by user id, in the format grade_update() expects.
 */
function aidialogue_get_user_grades($aidialogue, $userid = 0) {
    global $DB;

    $params = ['aidialogueid' => $aidialogue->id];
    $usersql = '';
    if ($userid) {
        $usersql = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    $sessions = $DB->get_records_select(
        'aidialogue_session',
        "aidialogueid = :aidialogueid AND status = 'complete'" . $usersql,
        $params,
        'userid ASC, attemptnumber ASC, id ASC',
        'id, userid, attemptnumber, teachergrade, timefinished, timemodified'
    );

    $peruser = [];
    foreach ($sessions as $session) {
        $peruser[$session->userid][] = $session;
    }

    $grades = [];
    foreach ($peruser as $uid => $usersessions) {
        $graded = array_values(array_filter($usersessions, fn($s) => $s->teachergrade !== null));

        $grade = new stdClass();
        $grade->userid = $uid;
        $grade->rawgrade = null;
        if ($graded) {
            $percentages = array_map(fn($s) => (float)$s->teachergrade, $graded);
            $percentage = match ((int)$aidialogue->grademethod) {
                AIDIALOGUE_GRADEAVERAGE => array_sum($percentages) / count($percentages),
                AIDIALOGUE_GRADEFIRST   => reset($percentages),
                AIDIALOGUE_GRADELAST    => end($percentages),
                default                 => max($percentages),
            };
            // Teacher grades are percentages, so rescale them to the activity's maximum grade.
            $grade->rawgrade = $percentage * (float)$aidialogue->grade / 100;
            $grade->dategraded = max(array_map(fn($s) => (int)$s->timemodified, $graded));
            $grade->datesubmitted = max(array_map(fn($s) => (int)$s->timefinished, $graded));
        }
        $grades[$uid] = $grade;
    }

    return $grades;
}

/**
 * Create or update the grade item of an activity, optionally sending grades with it.
 *
 * @param stdClass $aidialogue Activity record (needs id, course, name, grade).
 * @param array|string|null $grades Grades to send, or 'reset' to clear the grades.
 * @return int GRADE_UPDATE_OK or another GRADE_UPDATE_* constant.
 */
function aidialogue_grade_item_update($aidialogue, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => clean_param($aidialogue->name, PARAM_NOTAGS)];
    if (!empty($aidialogue->cmidnumber)) {
        $params['idnumber'] = $aidialogue->cmidnumber;
    }

    if ((float)$aidialogue->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = (float)$aidialogue->grade;
        $params['grademin']  = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/aidialogue', $aidialogue->course, 'mod', 'aidialogue', $aidialogue->id, 0, $grades, $params);
}

/**
 * Recalculate grades from the sessions and send them to the gradebook.
 *
 * @param stdClass $aidialogue Activity record.
 * @param int $userid One user id, or 0 for every student.
 * @param bool $nullifnone When updating one user with nothing to send, send a null grade.
 */
function aidialogue_update_grades($aidialogue, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if ((float)$aidialogue->grade <= 0) {
        aidialogue_grade_item_update($aidialogue);
    } else if ($grades = aidialogue_get_user_grades($aidialogue, $userid)) {
        aidialogue_grade_item_update($aidialogue, $grades);
    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        aidialogue_grade_item_update($aidialogue, $grade);
    } else {
        aidialogue_grade_item_update($aidialogue);
    }
}

/**
 * Delete the grade item of an activity.
 *
 * @param stdClass $aidialogue Activity record.
 * @return int GRADE_UPDATE_OK or another GRADE_UPDATE_* constant.
 */
function aidialogue_grade_item_delete($aidialogue) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/aidialogue', $aidialogue->course, 'mod', 'aidialogue', $aidialogue->id, 0, null, ['deleted' => 1]);
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
        'id, name, intro, introformat, completionpassorexhaust'
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
        'completionpassorexhaust' => $aidialogue->completionpassorexhaust,
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
