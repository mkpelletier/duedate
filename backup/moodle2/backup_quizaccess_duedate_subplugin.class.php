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
// along with Moodle.  If not, see <http://www.gnu.org/licenses>.

/**
 * Backup code for the quizaccess_duedate plugin.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/backup_mod_quiz_access_subplugin.class.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the information to backup the duedate quiz access plugin.
 *
 * Backs up quiz-level due date settings unconditionally,
 * and per-attempt penalty records when user data is included.
 */
class backup_quizaccess_duedate_subplugin extends backup_mod_quiz_access_subplugin {

    /**
     * Back up the quiz-level due date and penalty settings.
     *
     * @return backup_subplugin_element
     */
    protected function define_quiz_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Quiz-level settings.
        $subpluginsettings = new backup_nested_element('quizaccess_duedate_instance', null,
            ['duedate', 'penaltyenabled', 'penalty', 'penaltycapenabled', 'penaltycap']);

        // Per-user/group override records.
        $subpluginoverrides = new backup_nested_element('quizaccess_duedate_overrides', null, null);
        $subpluginoverride = new backup_nested_element('quizaccess_duedate_override', null,
            ['userid', 'groupid', 'duedate', 'timemodified']);

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginsettings);
        $subpluginwrapper->add_child($subpluginoverrides);
        $subpluginoverrides->add_child($subpluginoverride);

        $subpluginsettings->set_source_table('quizaccess_duedate_instances',
            ['quizid' => backup::VAR_ACTIVITYID]);

        $subpluginoverride->set_source_table('quizaccess_duedate_overrides',
            ['quizid' => backup::VAR_ACTIVITYID]);

        // Annotate user and group IDs for mapping on restore.
        $subpluginoverride->annotate_ids('user', 'userid');
        $subpluginoverride->annotate_ids('group', 'groupid');

        return $subplugin;
    }

    /**
     * Back up per-attempt penalty records (only when user data is included).
     *
     * @return backup_subplugin_element
     */
    protected function define_attempt_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $subpluginpenalty = new backup_nested_element('quizaccess_duedate_penalty', null,
            ['penaltyapplied', 'timemodified']);

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginpenalty);

        $subpluginpenalty->set_source_table('quizaccess_duedate_penalties',
            ['attemptid' => backup::VAR_PARENTID]);

        return $subplugin;
    }
}
