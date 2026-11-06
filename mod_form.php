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
 * AI Dialogue configuration form.
 *
 * @package    mod_aidialogue
 * @copyright  2026 Yusuf Wibisono <yusuf.wibisono@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_aidialogue\local\activity_config;

/**
 * Module instance settings form.
 */
class mod_aidialogue_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form;

        // Section: General.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '48']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 1333), 'maxlength', 1333, 'client');

        $this->standard_intro_elements();

        // Section: Knowledge.
        $mform->addElement('header', 'knowledgeheader', get_string('knowledgeheader', 'aidialogue'));
        $mform->addElement('textarea', 'knowledgetext', get_string('knowledgetext', 'aidialogue'), ['rows' => 10, 'cols' => 60]);
        $mform->setType('knowledgetext', PARAM_TEXT);
        $mform->addRule('knowledgetext', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('knowledgetext', 'knowledgetext', 'aidialogue');

        // Section: Rubric.
        $mform->addElement('header', 'rubricheader', get_string('rubricheader', 'aidialogue'));
        $mform->addElement('static', 'rubriccriteriaerror', '', '');

        $repeatarray = [];
        $repeatarray[] = $mform->createElement('html', '<hr class="my-3">');
        $repeatarray[] = $mform->createElement(
            'select',
            'bloomslevel',
            get_string('bloomslevel', 'aidialogue'),
            $this->get_blooms_levels(),
        );
        $repeatarray[] = $mform->createElement(
            'textarea',
            'description',
            get_string('criteriondescription', 'aidialogue'),
            ['rows' => 3, 'cols' => 60],
        );
        $repeatarray[] = $mform->createElement('text', 'minturns', get_string('minturns', 'aidialogue'), ['size' => 4]);
        $repeatarray[] = $mform->createElement('text', 'maxturns', get_string('maxturns', 'aidialogue'), ['size' => 4]);
        $repeatarray[] = $mform->createElement('hidden', 'criterionid', 0);
        $repeatarray[] = $mform->createElement(
            'submit',
            'delete_criterion',
            get_string('deletecriterion', 'aidialogue'),
            ['class' => 'btn-danger'],
            false
        );

        if ($this->_instance) {
            $repeatno = $DB->count_records('aidialogue_criterion', ['aidialogueid' => $this->_instance]);
            $repeatno = max($repeatno, 1);
        } else {
            $repeatno = 1;
        }

        $repeatoptions = [];
        $repeatoptions['bloomslevel']['type'] = PARAM_INT;
        $repeatoptions['description']['type'] = PARAM_TEXT;
        $repeatoptions['minturns']['type'] = PARAM_INT;
        $repeatoptions['minturns']['default'] = 1;
        $repeatoptions['maxturns']['type'] = PARAM_INT;
        $repeatoptions['maxturns']['default'] = 2;
        $repeatoptions['criterionid']['type'] = PARAM_INT;

        $this->repeat_elements(
            $repeatarray,
            $repeatno,
            $repeatoptions,
            'criterion_repeats',
            'criterion_add_fields',
            1,
            get_string('addcriterion', 'aidialogue'),
            true,
            'delete_criterion',
        );

        // Section: Attempt settings.
        $mform->addElement('header', 'attemptsettingsheader', get_string('attemptsettingsheader', 'aidialogue'));

        $mform->addElement('text', 'maxattempts', get_string('maxattempts', 'aidialogue'), ['size' => 4]);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addRule('maxattempts', null, 'numeric', null, 'client');
        $mform->addHelpButton('maxattempts', 'maxattempts', 'aidialogue');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Add the custom completion rule to the Completion conditions section.
     *
     * @return string[] Names of the added elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;

        $mform->addElement(
            'advcheckbox',
            'completionpassorexhaust',
            '',
            get_string('completionpassorexhaust', 'aidialogue'),
        );
        $mform->addHelpButton('completionpassorexhaust', 'completionpassorexhaust', 'aidialogue');

        return ['completionpassorexhaust'];
    }

    /**
     * Whether the custom completion rule is enabled in the submitted data.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionpassorexhaust']);
    }

    /**
     * Add help buttons to repeated criterion elements that were not deleted.
     *
     * repeat_elements() applies helpbutton options to all indices including deleted ones,
     * triggering a "non-existent element" warning. We add helpbuttons here instead,
     * after the form is built, guarded by elementExists().
     */
    public function definition_after_data() {
        parent::definition_after_data();

        $mform = $this->_form;
        $repeats = (int)$mform->getElement('criterion_repeats')->getValue();

        for ($i = 0; $i < $repeats; $i++) {
            if (!$mform->elementExists("description[$i]")) {
                continue;
            }
            $mform->addHelpButton("bloomslevel[$i]", 'bloomslevel', 'aidialogue');
            $mform->addHelpButton("description[$i]", 'criteriondescription', 'aidialogue');
            $mform->addHelpButton("minturns[$i]", 'minturns', 'aidialogue');
            $mform->addHelpButton("maxturns[$i]", 'maxturns', 'aidialogue');
        }
    }

    /**
     * Pre-populate form with existing criteria when editing.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        global $DB;

        if (empty($this->_instance)) {
            return;
        }

        $criteria = $DB->get_records(
            'aidialogue_criterion',
            ['aidialogueid' => $this->_instance],
            'sortorder ASC',
        );

        if (!$criteria) {
            return;
        }

        $key = 0;
        foreach ($criteria as $criterion) {
            $defaultvalues['bloomslevel[' . $key . ']']  = $criterion->bloomslevel;
            $defaultvalues['description[' . $key . ']']  = $criterion->description;
            $defaultvalues['minturns[' . $key . ']']     = $criterion->minturns;
            $defaultvalues['maxturns[' . $key . ']']     = $criterion->maxturns;
            $defaultvalues['criterionid[' . $key . ']']  = $criterion->id;
            $key++;
        }
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty(trim($data['knowledgetext'] ?? ''))) {
            $errors['knowledgetext'] = get_string('required');
        }

        $criteria = $data['description'] ?? [];

        if (empty($criteria)) {
            $errors['rubriccriteriaerror'] = get_string('err_atleastonecriterion', 'aidialogue');
        } else {
            $filledcount = 0;

            foreach ($criteria as $key => $description) {
                if (trim($description) === '') {
                    $errors['description[' . $key . ']'] = get_string('required');
                    continue;
                }

                $filledcount++;

                if ($filledcount > 5) {
                    $errors['description[' . $key . ']'] = get_string('err_maxfivecriteria', 'aidialogue');
                    continue;
                }

                $minturns = (int)($data['minturns'][$key] ?? 0);
                $maxturns = (int)($data['maxturns'][$key] ?? 0);

                if ($minturns < 1) {
                    $errors['minturns[' . $key . ']'] = get_string('err_minturnspositive', 'aidialogue');
                }

                if ($maxturns < 1) {
                    $errors['maxturns[' . $key . ']'] = get_string('err_maxturnspositive', 'aidialogue');
                } else if ($maxturns <= $minturns) {
                    $errors['maxturns[' . $key . ']'] = get_string('err_maxturnsgtminturns', 'aidialogue');
                }
            }
        }

        if ((int)($data['maxattempts'] ?? 0) < 0) {
            $errors['maxattempts'] = get_string('err_maxattemptspositive', 'aidialogue');
        }

        return $errors;
    }

    /**
     * Returns the Bloom's taxonomy level options.
     *
     * @return array
     */
    private function get_blooms_levels(): array {
        return [
            activity_config::BLOOMS_ANALYSE  => get_string('bloom_analyse', 'aidialogue'),
            activity_config::BLOOMS_EVALUATE => get_string('bloom_evaluate', 'aidialogue'),
            activity_config::BLOOMS_CREATE   => get_string('bloom_create', 'aidialogue'),
            activity_config::BLOOMS_CUSTOM   => get_string('bloom_custom', 'aidialogue'),
        ];
    }
}
