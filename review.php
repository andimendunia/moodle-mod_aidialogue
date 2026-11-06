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
 * AI Dialogue session review page.
 *
 * Shows a teacher the full detail for one student session:
 *   - Rubric evaluation per criterion (Bloom level, status, evidence quote).
 *   - AI-generated teacher summary.
 *   - Grade: AI suggested + teacher override input.
 *   - Full conversation transcript with move labels.
 *
 * URL: review.php?id={cmid}&session={sessionid}
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id        = required_param('id', PARAM_INT);
$sessionid = required_param('session', PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aidialogue');

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aidialogue:viewreport', $context);

$session = $DB->get_record(
    'aidialogue_session',
    ['id' => $sessionid, 'aidialogueid' => $cm->instance],
    '*',
    MUST_EXIST
);
$student = $DB->get_record('user', ['id' => $session->userid], '*', MUST_EXIST);

// Handle teacher grade save.
if ($action === 'savegrade' && confirm_sesskey()) {
    // An empty field clears the teacher grade. It must not turn into a real zero.
    $rawgrade = trim(optional_param('teachergrade', '', PARAM_RAW));
    if ($rawgrade === '' || is_numeric($rawgrade)) {
        $grade = $rawgrade === '' ? null : (float) $rawgrade;
        (new \mod_aidialogue\local\session_manager())->save_teacher_grade($session->id, $grade);
    }
    redirect(new moodle_url('/mod/aidialogue/review.php', ['id' => $cm->id, 'session' => $session->id]));
}

$PAGE->set_url('/mod/aidialogue/review.php', ['id' => $cm->id, 'session' => $session->id]);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(fullname($student));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();
$PAGE->set_secondary_active_tab('mod_aidialogue_report');
$PAGE->navbar->add(
    get_string('classreport', 'aidialogue'),
    new moodle_url('/mod/aidialogue/report.php', ['id' => $cm->id])
);
$PAGE->navbar->add(fullname($student));
$PAGE->requires->js_call_amd('mod_aidialogue/report', 'init');

// Load criteria (ordered).
$criteria = array_values(
    $DB->get_records('aidialogue_criterion', ['aidialogueid' => $cm->instance], 'sortorder ASC')
);

// Load criterion results keyed by criterionid.
$criterionresults = [];
$rawresults = $DB->get_records('aidialogue_criterion_result', ['sessionid' => $session->id]);
foreach ($rawresults as $r) {
    $criterionresults[(int)$r->criterionid] = $r;
}

// Load turns ordered by turnnumber.
$turns = array_values(
    $DB->get_records('aidialogue_turn', ['sessionid' => $session->id], 'turnnumber ASC')
);

// Load all sessions for this student on this activity (for the attempt dropdown).
$allsessions = $DB->get_records(
    'aidialogue_session',
    ['aidialogueid' => $cm->instance, 'userid' => $student->id],
    'attemptnumber ASC'
);

// Output.
$renderer = $PAGE->get_renderer('mod_aidialogue');
echo $OUTPUT->header();

// Page header.
echo html_writer::start_div('d-flex align-items-start justify-content-between flex-wrap gap-2 mb-4');

// Student name + attempt subtitle.
echo html_writer::start_div('flex-grow-1');
echo $OUTPUT->heading(s(fullname($student)));
$subinfo = get_string('attemptlabel', 'aidialogue', $session->attemptnumber);
if ($session->timecreated) {
    $subinfo .= ' &middot; ' . userdate($session->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
}
echo html_writer::tag('p', $subinfo, ['class' => 'text-muted mb-0']);
echo html_writer::end_div();

// Attempt dropdown (only shown when student has more than one session).
if (count($allsessions) > 1) {
    $options = [];
    foreach ($allsessions as $s) {
        $url   = new moodle_url('/mod/aidialogue/review.php', ['id' => $cm->id, 'session' => $s->id]);
        $label = get_string('attemptlabel', 'aidialogue', $s->attemptnumber);
        if ($s->timecreated) {
            $label .= ' &middot; ' . userdate($s->timecreated, get_string('strftimedatefullshort', 'langconfig'));
        }
        $statusstrings = ['pending' => 'statuspending', 'active' => 'statusactive', 'complete' => 'statuscomplete'];
        $label .= ' &middot; ' . get_string($statusstrings[$s->status] ?? 'statuspending', 'aidialogue');
        $attrs  = ['value' => $url->out(false)];
        if ((int)$s->id === (int)$session->id) {
            $attrs['selected'] = 'selected';
        }
        $options[] = html_writer::tag('option', $label, $attrs);
    }
    echo html_writer::tag(
        'select',
        implode('', $options),
        [
            'id'         => 'aidialogue-attempt-select',
            'class'      => 'form-select w-auto',
            'aria-label' => get_string('selectattempt', 'aidialogue'),
        ]
    );
}

echo html_writer::end_div();

// Rubric evaluation.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'h3',
    get_string('rubriceval', 'aidialogue'),
    ['class' => 'card-title h5 mb-4']
);

foreach ($criteria as $idx => $criterion) {
    $result = $criterionresults[$criterion->id] ?? null;
    $status = $result ? $result->status : 'pending';

    echo html_writer::start_div('mb-4');
    echo html_writer::tag(
        'div',
        html_writer::tag(
            'div',
            html_writer::tag('span', ($idx + 1) . '. ', ['class' => 'text-muted fw-semibold flex-shrink-0'])
            . html_writer::tag('span', s($criterion->description), ['class' => 'fs-6 fw-semibold'])
            . ' ' . $renderer->review_bloom_badge((int)$criterion->bloomslevel),
            ['class' => 'flex-grow-1']
        )
        . $renderer->report_status_icon($status),
        ['class' => 'd-flex align-items-center gap-3 mb-2']
    );
    if ($result && !empty($result->evidence)) {
        echo html_writer::tag(
            'p',
            get_string('evidence', 'aidialogue'),
            ['class' => 'text-uppercase text-muted small fw-semibold my-3']
        );
        echo html_writer::tag(
            'blockquote',
            html_writer::tag('p', s($result->evidence), ['class' => 'mb-0 fst-italic text-muted']),
            ['class' => 'blockquote fs-6 border-start border-3 ps-3']
        );
    }
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

// AI summary.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');

$summaryheader = get_string('aisummary', 'aidialogue');
if ($session->timefinished) {
    $ago = userdate($session->timefinished, get_string('strftimedatetime', 'langconfig'));
    $summaryheader .= html_writer::tag(
        'small',
        get_string('generatedfromsession', 'aidialogue', $ago),
        ['class' => 'float-end text-muted fw-normal aidialogue-meta']
    );
}
echo html_writer::tag('h3', $summaryheader, ['class' => 'card-title h5 mb-4']);

echo html_writer::tag('p', format_text($session->teacherreport, FORMAT_PLAIN));

echo html_writer::end_div();
echo html_writer::end_div();

// Grade.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'h3',
    get_string('gradeheader', 'aidialogue'),
    ['class' => 'card-title h5 mb-4']
);

echo html_writer::start_div('d-flex align-items-center gap-4 flex-wrap');

if (!empty($session->aigrade)) {
    echo html_writer::tag(
        'div',
        html_writer::tag('small', get_string('aisuggested', 'aidialogue'), ['class' => 'text-muted d-block'])
        . html_writer::tag('span', round($session->aigrade, 1) . '%', ['class' => 'fs-3 fw-bold']),
        ['class' => 'me-4']
    );
}

$currentteachergrade = $session->teachergrade !== null ? round((float)$session->teachergrade, 1) : '';
$formurl = new moodle_url('/mod/aidialogue/review.php', ['id' => $cm->id, 'session' => $session->id]);
echo html_writer::tag(
    'form',
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'savegrade'])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
    . html_writer::tag(
        'div',
        html_writer::tag(
            'label',
            get_string('teachergradelabel', 'aidialogue'),
            ['for' => 'aidialogue-teachergrade', 'class' => 'text-muted d-block mb-1 small']
        )
        . html_writer::tag(
            'div',
            html_writer::empty_tag('input', [
                'type'        => 'number',
                'id'          => 'aidialogue-teachergrade',
                'name'        => 'teachergrade',
                'class'       => 'form-control d-inline-block aidialogue-grade-input',
                'min'         => '0',
                'max'         => '100',
                'step'        => '0.5',
                'value'       => s((string)$currentteachergrade),
                'placeholder' => '--',
            ])
            . html_writer::tag('span', '%', ['class' => 'mx-1'])
            . html_writer::empty_tag('input', [
                'type'  => 'submit',
                'class' => 'btn btn-primary btn-md',
                'value' => get_string('savegrade', 'aidialogue'),
            ]),
            ['class' => 'd-flex align-items-center gap-1']
        )
    ),
    ['method' => 'post', 'action' => $formurl->out(false)]
);

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Full transcript.
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'h3',
    get_string('fulltranscript', 'aidialogue')
    . html_writer::tag(
        'small',
        get_string('nmessages', 'aidialogue', count($turns)),
        ['class' => 'float-end text-muted fw-normal aidialogue-meta']
    ),
    ['class' => 'card-title h5 mb-4']
);

echo html_writer::start_tag('table', ['class' => 'table table-sm align-middle mb-0']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag(
    'th',
    get_string('transcriptcol_role', 'aidialogue'),
    ['scope' => 'col', 'class' => 'visually-hidden aidialogue-col-role']
);
echo html_writer::tag(
    'th',
    get_string('transcriptcol_move', 'aidialogue'),
    ['scope' => 'col', 'class' => 'visually-hidden aidialogue-col-move']
);
echo html_writer::tag(
    'th',
    get_string('transcriptcol_message', 'aidialogue'),
    ['scope' => 'col', 'class' => 'visually-hidden']
);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($turns as $turn) {
    $isstudent = $turn->role === 'student';
    $rolename  = $isstudent ? get_string('you', 'aidialogue') : get_string('ai', 'aidialogue');
    if ($isstudent) {
        $avatarinner = html_writer::tag('span', 'S', ['aria-hidden' => 'true', 'class' => 'opacity-50']);
        $avatarcls   = 'bg-warning bg-opacity-25 text-dark';
    } else {
        $avatarinner = html_writer::tag('i', '', [
            'class'       => 'fa-solid fa-wand-magic-sparkles',
            'aria-hidden' => 'true',
        ]);
        $avatarcls   = 'bg-primary bg-opacity-25 text-primary';
    }
    $avatar = html_writer::tag(
        'span',
        $avatarinner . html_writer::tag('span', $rolename, ['class' => 'visually-hidden']),
        ['class' => 'badge rounded-circle d-inline-flex align-items-center justify-content-center '
            . $avatarcls . ' aidialogue-avatar']
    );
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', $avatar, ['class' => 'text-center align-top pt-2']);
    echo html_writer::tag(
        'td',
        $isstudent ? '' : $renderer->review_move_badge($turn->move),
        ['class' => 'align-top pt-2']
    );
    echo html_writer::tag('td', s($turn->content));
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
