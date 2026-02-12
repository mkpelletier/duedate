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
 * Due date quiz access rule.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class quizaccess_duedate extends \mod_quiz\local\access_rule_base {

    /**
     * Create an instance of this rule if applicable.
     *
     * @param \mod_quiz\quiz_settings $quizobj Quiz object.
     * @param int $timenow Current time.
     * @param bool $canignoretimelimits Whether to ignore time limits.
     * @return quizaccess_duedate|null
     */
    public static function make(\mod_quiz\quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        $quiz = $quizobj->get_quiz();
        if (empty($quiz->duedate)) {
            return null;
        }
        return new quizaccess_duedate($quizobj, $timenow);
    }

    /**
     * Information shown to students about the rule.
     *
     * @return array of HTML fragments.
     */
    public function description() {
        $result = [];
        $duedatestr = userdate($this->quiz->duedate);
        $result[] = get_string('duedateinfo', 'quizaccess_duedate', $duedatestr);  // Scalar.

        if (!empty($this->quiz->penaltyenabled)) {
            $penalty = (float)($this->quiz->penalty ?? 0);
            $captext = !empty($this->quiz->penaltycapenabled) && !empty($this->quiz->penaltycap)
                ? get_string('latepenaltyinfo_withcap', 'quizaccess_duedate', ['penalty' => $penalty, 'cap' => (float)$this->quiz->penaltycap])
                : get_string('latepenaltyinfo', 'quizaccess_duedate', ['penalty' => $penalty]);  // Associative for consistency.
            $result[] = $captext;
        }

        return $result;
    }
    /**
     * Add settings to the quiz settings form.
     *
     * @param \mod_quiz_mod_form $quizform The quiz form.
     * @param \MoodleQuickForm $mform The form object.
     */
    public static function add_settings_form_fields(\mod_quiz_mod_form $quizform, \MoodleQuickForm $mform) {
        $duedate = $mform->createElement('date_time_selector', 'duedate', get_string('duedate', 'quizaccess_duedate'),
                ['optional' => true]);
        $mform->insertElementBefore($duedate, 'timelimit');
        $mform->addHelpButton('duedate', 'duedate', 'quizaccess_duedate');

        $mform->addElement('header', 'duedatepenaltyheader', get_string('duedatepenaltysettings', 'quizaccess_duedate'));

        $mform->addElement('checkbox', 'penaltyenabled', get_string('enablepenalty', 'quizaccess_duedate'));
        $mform->disabledIf('penaltyenabled', 'duedate[enabled]', 'notchecked');

        $mform->addElement('text', 'penalty', get_string('penaltyperday', 'quizaccess_duedate'), ['size' => 3]);
        $mform->setType('penalty', PARAM_FLOAT);
        $mform->addHelpButton('penalty', 'penaltyperday', 'quizaccess_duedate');
        $mform->disabledIf('penalty', 'penaltyenabled', 'notchecked');

        $mform->addElement('checkbox', 'penaltycapenabled', get_string('enablepenaltycap', 'quizaccess_duedate'));
        $mform->disabledIf('penaltycapenabled', 'penaltyenabled', 'notchecked');

        $mform->addElement('text', 'penaltycap', get_string('penaltycap', 'quizaccess_duedate'), ['size' => 3]);
        $mform->setType('penaltycap', PARAM_FLOAT);
        $mform->addHelpButton('penaltycap', 'penaltycap', 'quizaccess_duedate');
        $mform->disabledIf('penaltycap', 'penaltycapenabled', 'notchecked');
    }

    /**
     * Validate settings form fields.
     *
     * @param array $errors Errors array.
     * @param array $data Form data.
     * @param array $files Files.
     * @param \mod_quiz_mod_form $quizform The quiz form.
     * @return array Updated errors.
     */
    public static function validate_settings_form_fields(array $errors, array $data, $files, \mod_quiz_mod_form $quizform) {
        if (!empty($data['duedate']) && $data['duedate'] < $data['timeopen']) {
            $errors['duedate'] = get_string('duedateafteropen', 'quizaccess_duedate');
        }
        if (!empty($data['duedate']) && $data['timeclose'] && $data['duedate'] > $data['timeclose']) {
            $errors['duedate'] = get_string('duedatebeforeclose', 'quizaccess_duedate');
        }
        if (!empty($data['penaltyenabled']) && (empty($data['penalty']) || $data['penalty'] < 0 || $data['penalty'] > 100)) {
            $errors['penalty'] = get_string('invalidpenalty', 'quizaccess_duedate');
        }
        if (!empty($data['penaltyenabled']) && empty($data['duedate'])) {
            $errors['penaltyenabled'] = get_string('penaltyrequiresduedate', 'quizaccess_duedate');
        }
        if (!empty($data['penaltycapenabled']) && (empty($data['penaltycap']) || $data['penaltycap'] < 0 || $data['penaltycap'] > 100)) {
            $errors['penaltycap'] = get_string('invalidpenaltycap', 'quizaccess_duedate');
        }
        if (!empty($data['penaltycapenabled']) && empty($data['penaltyenabled'])) {
            $errors['penaltycapenabled'] = get_string('penaltycaprequirespenalty', 'quizaccess_duedate');
        }
        return $errors;
    }

    /**
     * Save settings to the database and update calendar event.
     *
     * @param \stdClass $quiz The quiz object.
     */
    public static function save_settings($quiz) {
        global $DB;

        $record = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quiz->id]);
        $newrecord = (object) [
            'quizid' => $quiz->id,
            'duedate' => !empty($quiz->duedate) ? $quiz->duedate : 0,
            'penaltyenabled' => !empty($quiz->penaltyenabled) ? 1 : 0,
            'penalty' => !empty($quiz->penalty) ? $quiz->penalty : 0,
            'penaltycapenabled' => !empty($quiz->penaltycapenabled) ? 1 : 0,
            'penaltycap' => !empty($quiz->penaltycap) ? $quiz->penaltycap : 0,
        ];

        if ($newrecord->duedate == 0 && $newrecord->penaltyenabled == 0) {
            if ($record) {
                $DB->delete_records('quizaccess_duedate_instances', ['id' => $record->id]);
            }
            $event = $DB->get_record('event', ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'due']);
            if ($event) {
                $calendarevent = \calendar_event::load($event);
                $calendarevent->delete();
            }
            return;
        }

        if ($record) {
            $newrecord->id = $record->id;
            $DB->update_record('quizaccess_duedate_instances', $newrecord);
        } else {
            $DB->insert_record('quizaccess_duedate_instances', $newrecord);
        }

        $event = $DB->get_record('event', ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'due']);
        if ($newrecord->duedate) {
            $eventdata = [
                'name' => get_string('duedatefor', 'quizaccess_duedate', $quiz->name),
                'description' => '',
                'format' => FORMAT_HTML,
                'courseid' => $quiz->course,
                'groupid' => 0,
                'userid' => 0,
                'modulename' => 'quiz',
                'instance' => $quiz->id,
                'eventtype' => 'due',
                'timestart' => $newrecord->duedate,
                'timeduration' => 0,
                'visible' => 1,
                'priority' => null,
            ];
            if ($event) {
                $calendarevent = \calendar_event::load($event);
                $calendarevent->update($eventdata);
            } else {
                \calendar_event::create($eventdata);
            }
        } else if ($event) {
            $calendarevent = \calendar_event::load($event);
            $calendarevent->delete();
        }
    }

    /**
     * Delete settings and calendar event.
     *
     * @param \stdClass $quiz The quiz object.
     */
    public static function delete_settings($quiz) {
        global $DB;

        $DB->delete_records('quizaccess_duedate_instances', ['quizid' => $quiz->id]);
        $DB->delete_records('quizaccess_duedate_penalties', ['quizid' => $quiz->id]);

        $event = $DB->get_record('event', ['modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'due']);
        if ($event) {
            $calendarevent = \calendar_event::load($event);
            $calendarevent->delete();
        }
    }

    /**
     * Get SQL to load settings.
     *
     * @param int $quizid Quiz ID.
     * @return array SQL fragments.
     */
    public static function get_settings_sql($quizid) {
        return [
            'duedate.duedate, duedate.penaltyenabled, duedate.penalty, duedate.penaltycapenabled, duedate.penaltycap',
            'LEFT JOIN {quizaccess_duedate_instances} duedate ON duedate.quizid = quiz.id',
            []
        ];
    }
}