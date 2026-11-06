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
 * AI Dialogue activity view page.
 *
 * Renders three states:
 *   no_session — no active attempt; show "Start Session" button.
 *   active     — session in progress; show chat transcript + input.
 *   complete   — session finished; show student report and grade.
 *
 * NOTE: This is a bare/functional UI for development and engine testing.
 * Bea's design will replace the HTML/CSS here without touching the engine.
 *
 * The "Start Session" button POSTs to this page with action=start.
 * Subsequent chat messages are handled by the mod_aidialogue/chat AMD module,
 * which calls the mod_aidialogue external functions.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/aidialogue/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

use mod_aidialogue\local\activity_config;
use mod_aidialogue\local\session_manager;
use mod_aidialogue\local\ai_client;
use mod_aidialogue\local\prompt_builder;
use mod_aidialogue\local\dialogue_engine;

$id     = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aidialogue');
$aidialogue    = $DB->get_record('aidialogue', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aidialogue:view', $context);

aidialogue_view($aidialogue, $course, $cm, $context);

$PAGE->set_url('/mod/aidialogue/view.php', ['id' => $cm->id]);
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title($course->shortname . ': ' . $aidialogue->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($aidialogue);

$activityheader = ['hidecompletion' => false];
if (!$PAGE->activityheader->is_title_allowed()) {
    $activityheader['title'] = '';
}
$PAGE->activityheader->set_attrs($activityheader);

// Build the engine.
$config  = activity_config::load_from_db($cm->instance);
$engine  = new dialogue_engine(
    new session_manager(),
    new ai_client(),
    new prompt_builder(),
);

// Handle actions.
$errormessage = '';

if ($action === 'start' && confirm_sesskey()) {
    try {
        $result  = $engine->start_session($config, $USER->id);
        $session = $result['session'];
        // Reload page to show active state with the opening message already in DB.
        redirect(new moodle_url('/mod/aidialogue/view.php', ['id' => $cm->id]));
    } catch (\moodle_exception $e) {
        $errormessage = $e->getMessage();
    }
}

// Determine current state.
$state = $engine->get_session_state($config, $USER->id);

// Output.
echo $OUTPUT->header();

// Error banner (if any action failed).
if ($errormessage) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('error')) . ': ' . s($errormessage),
        'alert alert-danger'
    );
}

// STATE A: No active session.
if ($state['state'] === 'no_session') {
    $attemptcount = $state['attempt_count'];
    $maxattempts   = $config->maxattempts;
    $last          = $state['last_completed'];

    echo html_writer::start_div('aidialogue-notsession p-4');

    // Show previous attempt result if any.
    if ($last !== null) {
        echo html_writer::tag('h4', get_string('previousattempt', 'aidialogue'));
        echo html_writer::tag(
            'p',
            get_string('attemptcount', 'aidialogue', ['used' => $attemptcount, 'max' => $maxattempts > 0 ? $maxattempts : '∞'])
        );
        if (!empty($last->studentreport)) {
            echo html_writer::tag('h5', get_string('yourfeedback', 'aidialogue'));
            echo html_writer::tag('div', format_text($last->studentreport, FORMAT_PLAIN), ['class' => 'border p-3 mb-3 bg-light']);
        }
        if (!empty($last->aigrade)) {
            echo html_writer::tag('p', get_string('aigrade', 'aidialogue') . ': ' . round($last->aigrade, 1) . '%');
        }
    } else {
        echo html_writer::tag('p', get_string('nosessionyet', 'aidialogue'));
        if ($maxattempts > 0) {
            echo html_writer::tag('p', get_string('attemptsallowed', 'aidialogue', $maxattempts));
        }
    }

    if ($state['can_start']) {
        echo html_writer::tag(
            'form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'start'])
            . html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => get_string('startsession', 'aidialogue'), 'class' => 'btn btn-primary',
            ]),
            ['method' => 'post', 'action' => new moodle_url('/mod/aidialogue/view.php')]
        );
    } else {
        echo html_writer::tag('p', html_writer::tag('em', get_string('noattemptsremaining', 'aidialogue')));
    }

    echo html_writer::end_div();
}

// STATE B: Active session — chat UI.
if ($state['state'] === 'active') {
    $session = $state['session'];
    $turns   = $state['turns'];

    echo html_writer::start_div('aidialogue-chat mx-auto');

    // Privacy notice.
    echo html_writer::tag(
        'div',
        html_writer::tag('i', '', ['class' => 'fa fa-shield fa-fw me-1', 'aria-hidden' => 'true'])
            . s(get_string('privacynotice', 'aidialogue')),
        ['class' => 'aidialogue-privacy-notice text-muted small text-center mb-4']
    );

    // Transcript.
    echo html_writer::start_div('aidialogue-transcript mb-3', ['id' => 'aidialogue-transcript']);

    foreach ($turns as $turn) {
        $isstudent = $turn->role === 'student';
        $timestr   = userdate($turn->timecreated, '%H:%M');
        $srlabel   = html_writer::tag(
            'span',
            $isstudent ? get_string('you', 'aidialogue') : get_string('ai', 'aidialogue'),
            ['class' => 'sr-only visually-hidden']
        );

        if ($isstudent) {
            $avatar = html_writer::tag(
                'span',
                html_writer::tag('span', 'S', ['aria-hidden' => 'true', 'class' => 'opacity-50']),
                ['class' => 'badge rounded-circle d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-25 text-dark flex-shrink-0 ms-2 aidialogue-avatar', 'aria-hidden' => 'true']
            );
            $inner = html_writer::tag('div', s($turn->content), ['class' => 'aidialogue-bubble aidialogue-bubble-student'])
                   . html_writer::tag('div', $timestr, ['class' => 'aidialogue-timestamp']);
            echo html_writer::tag(
                'div',
                $srlabel . html_writer::tag('div', $inner, ['class' => 'flex-grow-1 d-flex flex-column align-items-end']) . $avatar,
                ['class' => 'aidialogue-turn aidialogue-turn-student d-flex align-items-start mb-3']
            );
        } else {
            $avatar = html_writer::tag(
                'span',
                html_writer::tag('i', '', ['class' => 'fa-solid fa-wand-magic-sparkles', 'aria-hidden' => 'true']),
                ['class' => 'badge rounded-circle d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-25 text-primary flex-shrink-0 me-2 aidialogue-avatar', 'aria-hidden' => 'true']
            );
            $inner = html_writer::tag('div', s($turn->content), ['class' => 'aidialogue-bubble aidialogue-bubble-ai'])
                   . html_writer::tag('div', $timestr, ['class' => 'aidialogue-timestamp']);
            echo html_writer::tag(
                'div',
                $srlabel . $avatar . html_writer::tag('div', $inner, ['class' => 'flex-grow-1']),
                ['class' => 'aidialogue-turn aidialogue-turn-ai d-flex align-items-start mb-3']
            );
        }
    }

    echo html_writer::end_div(); // End transcript.

    // Status line.
    echo html_writer::tag('p', '', ['id' => 'aidialogue-status', 'class' => 'text-muted small my-2']);

    // Input row.
    echo html_writer::start_tag('div', ['class' => 'd-flex gap-2 align-items-end', 'id' => 'aidialogue-input-area']);
    echo html_writer::tag(
        'textarea',
        '',
        [
            'id'          => 'aidialogue-input',
            'class'       => 'form-control aidialogue-input-flex',
            'rows'        => '3',
            'placeholder' => get_string('typeyourmessage', 'aidialogue'),
        ]
    );
    echo html_writer::tag(
        'button',
        html_writer::tag('i', '', ['class' => 'fa fa-arrow-up fa-fw', 'aria-hidden' => 'true'])
            . html_writer::tag('span', get_string('send', 'aidialogue'), ['class' => 'sr-only visually-hidden']),
        ['id' => 'aidialogue-send', 'class' => 'btn btn-primary aidialogue-send-btn', 'type' => 'button']
    );
    echo html_writer::end_tag('div');

    // End session button sits below the input row.
    echo html_writer::tag(
        'button',
        get_string('endsession', 'aidialogue'),
        ['id' => 'aidialogue-end', 'class' => 'btn btn-outline-danger btn-sm mt-2', 'type' => 'button']
    );

    echo html_writer::end_div(); // End aidialogue-chat.

    $PAGE->requires->js_call_amd('mod_aidialogue/chat', 'init', [(int) $session->id, (int) $cm->id]);
}

// STATE C: Session complete — results screen.
if ($state['state'] === 'complete') {
    $session      = $state['last_completed'];
    $attemptcount = $state['attempt_count'];
    $maxattempts  = $config->maxattempts;

    $renderer = $PAGE->get_renderer('mod_aidialogue');

    // Load criterion results keyed by criterionid.
    $rawresults        = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $session->id]);
    $resultsbycriteria = [];
    foreach ($rawresults as $r) {
        $resultsbycriteria[$r->criterionid] = $r;
    }

    // Parse student report into the three structured sections.
    $reportparts = prompt_builder::parse_student_report($session->studentreport ?? '');

    echo html_writer::start_div('aidialogue-feedback mx-auto');

    // Early-exit info banner.
    if (!empty($session->earlyexit)) {
        echo html_writer::div(get_string('sessionendedearlyinfo', 'aidialogue'), 'alert alert-warning mb-4');
    }

    // "Your results" card — numbered criteria list with status icons.
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('yourresults', 'aidialogue'), ['class' => 'card-title h5 mb-3']);

    foreach ($config->criteria as $i => $criterion) {
        $result = $resultsbycriteria[$criterion->id] ?? null;
        $status = $result ? $result->status : 'pending';

        echo html_writer::start_div('d-flex align-items-center gap-2 py-2');
        echo html_writer::tag('span', ($i + 1) . '.', ['class' => 'text-muted fw-semibold flex-shrink-0']);
        echo html_writer::tag('span', s($criterion->description), ['class' => 'flex-grow-1']);
        echo $renderer->report_status_icon($status);
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div(); // End card.

    // Feedback cards — one per section.
    if (!empty($reportparts['strengths'])) {
        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('strengths', 'aidialogue'), ['class' => 'card-title h5 mb-3']);
        echo html_writer::tag('p', format_text($reportparts['strengths'], FORMAT_PLAIN), ['class' => 'mb-0']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    if (!empty($reportparts['areas'])) {
        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('areastoworkon', 'aidialogue'), ['class' => 'card-title h5 mb-3']);
        echo html_writer::tag('p', format_text($reportparts['areas'], FORMAT_PLAIN), ['class' => 'mb-0']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    if (!empty($reportparts['next'])) {
        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('whattodonext', 'aidialogue'), ['class' => 'card-title h5 mb-3']);
        echo html_writer::tag('p', format_text($reportparts['next'], FORMAT_PLAIN), ['class' => 'mb-0']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    // Grade card. The final grade is the teacher's grade in the gradebook, never the AI grade.
    $gradinginfo = grade_get_grades($course->id, 'mod', 'aidialogue', $cm->instance, $USER->id);
    if (!empty($gradinginfo->items[0])) {
        $usergrade = $gradinginfo->items[0]->grades[$USER->id] ?? null;
        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h3', get_string('yourgrade', 'aidialogue'), ['class' => 'card-title h5 mb-3']);
        if ($usergrade && $usergrade->grade !== null && !$usergrade->hidden) {
            echo html_writer::tag('p', $usergrade->str_long_grade, ['class' => 'fs-4 fw-bold mb-0']);
        } else {
            echo html_writer::tag('p', get_string('awaitingreview', 'aidialogue'), ['class' => 'text-muted mb-0']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    // Attempt count.
    echo html_writer::tag('p', get_string('attemptcount', 'aidialogue', [
        'used' => $attemptcount,
        'max'  => $maxattempts > 0 ? $maxattempts : '∞',
    ]), ['class' => 'text-muted small mb-3']);

    // Try again / exhausted.
    if ($state['can_start']) {
        echo html_writer::tag(
            'form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'start'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => get_string('tryagain', 'aidialogue'), 'class' => 'btn btn-outline-primary',
            ]),
            ['method' => 'post', 'action' => new moodle_url('/mod/aidialogue/view.php')]
        );
    } else {
        echo html_writer::tag('p', html_writer::tag('em', get_string('noattemptsremaining', 'aidialogue')));
    }

    echo html_writer::end_div(); // End aidialogue-feedback.
}

echo $OUTPUT->footer();
