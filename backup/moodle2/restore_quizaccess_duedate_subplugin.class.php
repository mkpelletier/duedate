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
 * Restore code for the quizaccess_duedate plugin.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/restore_mod_quiz_access_subplugin.class.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the information to restore the duedate quiz access plugin.
 *
 * Restores quiz-level due date and penalty settings, and per-attempt penalty
 * records when user data is present in the backup.
 */
class restore_quizaccess_duedate_subplugin extends restore_mod_quiz_access_subplugin {

    /**
     * Define the restore paths for quiz-level settings.
     *
     * @return restore_path_element[]
     */
    protected function define_quiz_subplugin_structure() {
        $paths = [];

        $elename = $this->get_namefor('instance');
        $elepath = $this->get_pathfor('/quizaccess_duedate_instance');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Process a restored due date instance record.
     *
     * @param array|object $data The data from the backup XML.
     */
    public function process_quizaccess_duedate_instance($data) {
        global $DB;

        $data = (object) $data;
        $data->quizid = $this->get_new_parentid('quiz');
        $DB->insert_record('quizaccess_duedate_instances', $data);
    }

    /**
     * Define the restore paths for per-attempt penalty records.
     *
     * @return restore_path_element[]
     */
    protected function define_attempt_subplugin_structure() {
        $paths = [];

        $elename = $this->get_namefor('penalty');
        $elepath = $this->get_pathfor('/quizaccess_duedate_penalty');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Process a restored penalty record.
     *
     * @param array|object $data The data from the backup XML.
     */
    public function process_quizaccess_duedate_penalty($data) {
        global $DB;

        $data = (object) $data;

        $newattemptid = $this->get_new_parentid('quiz_attempt');
        if (!$newattemptid) {
            return;
        }

        $data->quizid = $this->get_new_parentid('quiz');
        $data->attemptid = $newattemptid;
        $DB->insert_record('quizaccess_duedate_penalties', $data);
    }
}
