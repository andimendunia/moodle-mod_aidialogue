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
 * Strings for component 'mod_aidialogue', language 'en'.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Module metadata.
$string['modulename']         = 'AI Dialogue';
$string['modulename_help']    = 'An AI-powered dialogue activity where students engage in a structured conversation with an AI assessor to demonstrate their understanding.';
$string['modulename_link']    = 'mod/aidialogue/view';
$string['modulename_summary'] = 'Create an AI conversation activity for students.';
$string['modulename_tip']     = 'Use the AI Dialogue module for interactive AI conversations.';
$string['modulenameplural']   = 'AI Dialogues';
$string['page-mod-aidialogue-x'] = 'Any AI Dialogue module page';
$string['pluginadministration'] = 'AI Dialogue module administration';
$string['pluginname']         = 'AI Dialogue';
$string['search:activity']    = 'AI Dialogue';

// Capabilities.
$string['aidialogue:addinstance']  = 'Add a new AI Dialogue activity';
$string['aidialogue:view']         = 'View AI Dialogue activity';
$string['aidialogue:viewreport']   = 'View class report';

// Privacy.
$string['privacy:metadata'] = 'The AI Dialogue activity stores conversation transcripts, session outcomes, and AI-generated reports as part of the assessment process. This data includes user-authored messages and is associated with the student\'s account.';
$string['privacy:metadata:aidialogue_session'] = 'Stores one record per student attempt, including status, grades, and AI-generated feedback reports.';
$string['privacy:metadata:aidialogue_session:userid'] = 'The ID of the student who owns this session.';
$string['privacy:metadata:aidialogue_session:attemptnumber'] = 'The sequential attempt number for this session (1-based).';
$string['privacy:metadata:aidialogue_session:status'] = 'The session status (pending, active, or complete).';
$string['privacy:metadata:aidialogue_session:studentreport'] = 'The AI-generated feedback report shown to the student.';
$string['privacy:metadata:aidialogue_session:teacherreport'] = 'The AI-generated assessment narrative shown to the teacher.';
$string['privacy:metadata:aidialogue_session:aigrade'] = 'The AI-suggested grade percentage for the session.';
$string['privacy:metadata:aidialogue_session:teachergrade'] = 'The teacher-overridden grade percentage for the session.';
$string['privacy:metadata:aidialogue_session:earlyexit'] = 'Whether the student ended the session early.';
$string['privacy:metadata:aidialogue_session:timecreated'] = 'The time the session was created.';
$string['privacy:metadata:aidialogue_session:timestarted'] = 'The time the student sent their first message.';
$string['privacy:metadata:aidialogue_session:timefinished'] = 'The time the session was completed.';
$string['privacy:metadata:aidialogue_turn'] = 'Stores individual conversation turns (student and AI messages) within a session.';
$string['privacy:metadata:aidialogue_turn:role'] = 'Whether this turn was authored by the student or the AI.';
$string['privacy:metadata:aidialogue_turn:move'] = 'The conversational move the AI made on this turn (for AI turns only).';
$string['privacy:metadata:aidialogue_turn:content'] = 'The text content of the message.';
$string['privacy:metadata:aidialogue_turn:timecreated'] = 'The time this turn was recorded.';
$string['privacy:metadata:aidialogue_criterion_result'] = 'Stores the assessed outcome for each rubric criterion within a session.';
$string['privacy:metadata:aidialogue_criterion_result:status'] = 'The outcome status for this criterion (pending, in_progress, met, partial, limit, or abandoned).';
$string['privacy:metadata:aidialogue_criterion_result:evidence'] = 'A short evidence excerpt quoted from the student\'s responses.';
$string['privacy:metadata:aiservice'] = 'In order to generate conversational responses and assessment reports, student messages are sent to an external AI service.';
$string['privacy:metadata:aiservice:message'] = 'The student\'s message text, sent to the AI service to generate a response.';
$string['privacy:sessionsubcontext'] = 'Attempt {$a}';

// Admin settings — AI connection.
$string['aiheader']      = 'AI connection';
$string['aiheader_desc'] = 'Configure the OpenAI-compatible API endpoint used by AI Dialogue activities.';
$string['aiurl']         = 'API base URL';
$string['aiurl_desc']    = 'Base URL of an OpenAI-compatible API endpoint. The chat completions path (<code>/chat/completions</code>) will be appended automatically.';
$string['aiapikey']      = 'API key';
$string['aiapikey_desc'] = 'Secret key for authenticating with the AI provider. Sent as the Bearer token on every request.';
$string['aimodel']       = 'Model';
$string['aimodel_desc']  = 'Model identifier to request from the provider (e.g. <code>gpt-4o</code>, <code>gpt-4.1</code>).';

// Activity form — knowledge.
$string['knowledgeheader']    = 'Knowledge';
$string['knowledgetext']      = 'Knowledge text';
$string['knowledgetext_help'] = 'The source material the AI will use as its ground truth during the interview. Write in plain text; this content is injected directly into the AI system prompt.';

// Activity form — rubric.
$string['rubricheader']            = 'Rubric';
$string['addcriterion']            = 'Add criterion';
$string['deletecriterion']         = 'Delete criterion';
$string['bloomslevel']             = 'Bloom\'s level';
$string['bloomslevel_help']        = 'The cognitive level this criterion targets, based on Bloom\'s taxonomy.';
$string['bloom_analyse']           = 'Analyse';
$string['bloom_evaluate']          = 'Evaluate';
$string['bloom_create']            = 'Create';
$string['bloom_custom']            = 'Custom';
$string['criteriondescription']    = 'Description';
$string['criteriondescription_help'] = 'Describe what evidence from the student would satisfy this criterion.';
$string['minturns']                = 'Min turns';
$string['minturns_help']           = 'The minimum number of exchanges the AI must complete before closing this criterion.';
$string['maxturns']                = 'Max turns';
$string['maxturns_help']           = 'The maximum number of exchanges allowed for this criterion. Must be greater than min turns.';

// Activity form — attempt settings.
$string['attemptsettingsheader']  = 'Attempt settings';
$string['maxattempts']            = 'Maximum attempts';
$string['grademethod']            = 'Grading method';
$string['grademethod_help']       = 'Which attempt counts for the gradebook when a student has several attempts. Only attempts that a teacher has graded are used. The AI grade is only a suggestion and never reaches the gradebook.';
$string['gradehighest']           = 'Highest grade';
$string['gradeaverage']           = 'Average grade';
$string['gradefirst']             = 'First graded attempt';
$string['gradelast']              = 'Last graded attempt';
$string['err_nogradescale']       = 'Scales cannot be used here. Teachers grade in percentages, so choose a maximum grade of 1 or more, or no grade.';
$string['yourgrade']              = 'Your grade';
$string['awaitingreview']         = 'Your teacher has not graded this yet. Your grade will appear here and in the gradebook once they have.';
$string['maxattempts_help']       = 'The maximum number of times a student can attempt this activity. Set to 0 for unlimited attempts.';

// Completion rules.
$string['completionpassorexhaust']       = 'Student must pass or use all attempts';
$string['completionpassorexhaust_help']  = 'When enabled, the activity is complete once the student passes or has used all allowed attempts, whichever happens first. A pass means the teacher\'s grade in the gradebook reaches the grade to pass. Without a grade to pass, or with unlimited attempts, the student can only complete the activity by passing.';

// Form validation errors.
$string['err_atleastonecriterion'] = 'You must define at least one criterion.';
$string['err_maxfivecriteria']     = 'You can define a maximum of 5 criteria.';
$string['err_maxattemptspositive'] = 'Maximum attempts must be 0 or greater.';
$string['err_minturnspositive']    = 'Min turns must be at least 1.';
$string['err_maxturnspositive']    = 'Max turns must be at least 1.';
$string['err_maxturnsgtminturns']  = 'Max turns must be greater than min turns.';

// View page — state A (no session).
$string['nosessionyet']        = 'In this activity, you\'ll have a one-on-one conversation with an AI tutor. The AI will guide you through a series of questions and prompts based on the topic set by your teacher. Your responses will be assessed against learning criteria, and you\'ll receive personalised feedback and a grade at the end. When you\'re ready, press the button below to begin.';
$string['attemptsallowed']     = 'You have {$a} attempt(s) allowed.';
$string['startsession']        = 'Start session';
$string['previousattempt']     = 'Your previous attempt';
$string['attemptcount']        = 'Attempts used: {$a->used} / {$a->max}';
$string['noattemptsremaining'] = 'You have no attempts remaining for this activity.';

// View page — state B (active session).
$string['conversation']    = 'Conversation';
$string['privacynotice']   = 'Your conversation is private to you and your teacher.';
$string['you']             = 'You';
$string['ai']              = 'AI';
$string['typeyourmessage'] = 'Type your response...';
$string['send']            = 'Send';
$string['thinking']        = 'AI is thinking…';
$string['endsession']         = 'End session';
$string['endsession_confirm'] = 'Are you sure you want to end this session? This will count as an attempt and you will not be able to continue.';

// View page — state C (complete).
$string['sessioncomplete']       = 'Session complete';
$string['viewresults']           = 'View results';
$string['yourfeedback']          = 'Your feedback';
$string['yourresults']           = 'Your results';
$string['strengths']             = 'Strengths';
$string['areastoworkon']         = 'Areas to work on';
$string['whattodonext']          = 'What to do next';
$string['aigrade']               = 'AI suggested grade';
$string['passed']                = 'Passed';
$string['notpassed']             = 'Not yet passed';
$string['tryagain']              = 'Try again';
$string['sessionendedearlyinfo'] = 'You ended this session early. The feedback below is based on the criteria you completed.';

// Errors.
$string['error:sessionnotfound']        = 'The session could not be found. It may have been deleted.';
$string['error:sessionownership']        = 'You do not have permission to access this session.';
$string['error:sessionalreadycomplete']  = 'This session has already been completed.';
$string['error:ajax']                    = 'An error occurred. Please try again.';
$string['error:aicredentialsmissing'] = 'AI credentials are not configured. Please ask a site administrator to configure the AI Dialogue settings.';
$string['error:aicurlfailed']         = 'Could not connect to the AI service: {$a}';
$string['error:aiunauthorised']       = 'The AI service rejected the API key. Please ask a site administrator to check the AI Dialogue settings.';
$string['error:airatelimited']        = 'The AI service is currently rate-limited. Please try again in a moment.';
$string['error:aihttperror']          = 'The AI service returned an unexpected error (HTTP {$a}).';
$string['error:aiinvalidjson']        = 'The AI service returned an unreadable response. Please try again.';
$string['error:aiemptyresponse']      = 'The AI service returned an empty response. Please try again.';
$string['error:airesponsetruncated']  = 'The AI response was cut off because it exceeded the maximum token limit. Please try again or contact your administrator.';
$string['error:maxattemptsreached']   = 'You have reached the maximum number of attempts for this activity.';
$string['error:messageempty']         = 'Message cannot be empty.';
$string['error:messagetoolong']       = 'Message exceeds maximum allowed length.';

// Teacher report — navigation.
$string['classreport'] = 'Results';

// Teacher report — class report page (report.php).
$string['criteriaoverview']     = 'Criteria overview';
$string['studentlist']          = 'Student list';
$string['nstudent']             = '{$a} student';
$string['nstudents']            = '{$a} students';
$string['lastattempt']          = 'Last attempt';
$string['clickrowfordetails']   = 'Click any row to view details';
$string['legendmet']            = 'Met';
$string['legendpartial']        = 'Partial';
$string['legendlimit']          = 'Limit';
$string['legendpending']        = 'Pending';
$string['metofn']               = 'Met: {$a->met}/{$a->total}';
$string['moststruggled']        = 'Most students struggled here';
$string['statuscomplete']       = 'Complete';
$string['statusactive']         = 'Active';
$string['statuspending']        = 'Pending';
$string['nostudents']           = 'No students enrolled.';

// Teacher report — session review page (review.php).
$string['rubriceval']            = 'Rubric evaluation';
$string['aisummary']             = 'AI summary';
$string['gradeheader']           = 'Grade';
$string['aisuggested']           = 'AI suggested';
$string['teachergradelabel']     = 'Your grade';
$string['savegrade']             = 'Save grade';
$string['fulltranscript']        = 'Full transcript';
$string['attemptlabel']          = 'Attempt {$a}';
$string['selectattempt']         = 'Select attempt';
$string['nmessages']             = '{$a} messages';
$string['transcriptcol_role']    = 'Role';
$string['transcriptcol_move']    = 'Move type';
$string['transcriptcol_message'] = 'Message';
$string['evidence']              = 'Evidence';
$string['generatedfromsession']  = 'Generated from session: {$a}';

// Criterion result status labels.
$string['criteriastatus_met']         = 'Met';
$string['criteriastatus_partial']     = 'Partial';
$string['criteriastatus_limit']       = 'Limit';
$string['criteriastatus_pending']     = 'Pending';
$string['criteriastatus_inprogress']  = 'In progress';
$string['criteriastatus_abandoned']   = 'Abandoned';

// Turn move labels (shown in Full transcript).
$string['move_session_open']    = 'Session opened';
$string['move_criterion_open']  = 'Criterion opened';
$string['move_probe_deeper']    = 'Probe deeper';
$string['move_probe_clarify']   = 'Probe clarify';
$string['move_criterion_close'] = 'Criterion closed';
$string['move_session_close']   = 'Session closed';
