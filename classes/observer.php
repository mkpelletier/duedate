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
 * Event observer for quizaccess_duedate.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_duedate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/classes/quiz_settings.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/calendar/lib.php');

class observer {

    /**
     * Handle the quiz attempt submitted event to clear the gradebook override if applicable.
     *
     * @param \mod_quiz\event\attempt_submitted $event The event object.
     */
    public static function attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        global $DB;

        static $processing = false;
        if ($processing) {
            return;
        }
        $processing = true;

        $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
        $quizid = $attempt->quiz;
        $userid = $attempt->userid;

        // Load quiz settings and duedate settings.
        $quizobj = \mod_quiz\quiz_settings::create($quizid);
        $quiz = $quizobj->get_quiz();
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);

        if (!$settings || !$settings->penaltyenabled || !$settings->duedate) {
            $processing = false;
            return;
        }

        // For First Attempt grading method, only process if this is the first finished attempt.
        $firstattempt = $DB->get_record_sql(
            'SELECT * FROM {quiz_attempts} WHERE quiz = ? AND userid = ? AND timefinish > 0 ORDER BY timefinish ASC LIMIT 1',
            [$quizid, $userid]
        );
        if ($quiz->grademethod == QUIZ_ATTEMPTFIRST && $firstattempt->id != $attempt->id) {
            $processing = false;
            return;
        }

        // Get the grade item for the quiz.
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'courseid' => $quiz->course
        ]);

        if (!$gradeitem) {
            $processing = false;
            return;
        }

        // Clear the override in the gradebook to allow the quiz module to update the grade.
        $gradegrade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $userid
        ]);

        if ($gradegrade && $gradegrade->overridden) {
            $gradegrade->overridden = 0;
            $DB->update_record('grade_grades', $gradegrade);
        }

        $processing = false;
    }

    /**
     * Handle the user graded event to apply late penalties for quiz attempts.
     *
     * @param \core\event\user_graded $event The event object.
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        static $processing = false;
        if ($processing) {
            return;
        }
        $processing = true;

        $grade = $event->get_grade();
        $gradeitem = \grade_item::fetch(['id' => $grade->itemid]);

        // Check if the grade is for a quiz.
        if (!$gradeitem || $gradeitem->itemtype !== 'mod' || $gradeitem->itemmodule !== 'quiz') {
            $processing = false;
            return;
        }

        $quizid = $gradeitem->iteminstance;
        $userid = $event->relateduserid;

        // Load quiz settings and duedate settings.
        $quizobj = \mod_quiz\quiz_settings::create($quizid);
        $quiz = $quizobj->get_quiz();
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);

        if (!$settings || !$settings->penaltyenabled || !$settings->duedate) {
            $processing = false;
            return;
        }

        // Resolve the effective due date for this specific user (may be overridden).
        $effectiveduedate = \quizaccess_duedate\override_manager::get_effective_duedate($quizid, $userid);
        if (!$effectiveduedate) {
            $processing = false;
            return;
        }

        // Find the first attempt for the user to determine the penalty.
        $firstattempt = $DB->get_record_sql(
            'SELECT * FROM {quiz_attempts} WHERE quiz = ? AND userid = ? AND timefinish > 0 ORDER BY timefinish ASC LIMIT 1',
            [$quizid, $userid]
        );

        if (!$firstattempt) {
            $processing = false;
            return;
        }

        // Calculate penalty based on the first attempt's submission time.
        $total_penalty = 0;
        if ($firstattempt->timefinish > $effectiveduedate) {
            $seconds_late = $firstattempt->timefinish - $effectiveduedate;
            $days_late = ceil($seconds_late / 86400);
            $total_penalty = $days_late * $settings->penalty;
            if ($settings->penaltycapenabled && $settings->penaltycap > 0) {
                $total_penalty = min($total_penalty, $settings->penaltycap);
            } else {
                $total_penalty = min($total_penalty, 100); // Cap at 100% if no explicit cap.
            }
        }

        // Get the raw grade and compute penalty as a percentage of the max grade.
        $rawgrade = $grade->rawgrade;
        $penaltyamount = ($total_penalty / 100) * $gradeitem->grademax;

        // Apply subtractive penalty.
        $penalizedgrade = max(0, $rawgrade - $penaltyamount);

        // Prepare feedback for the gradebook.
        $feedback = '';
        if ($total_penalty > 0) {
            $feedback = $total_penalty > 0 ? get_string('latepenaltyapplied', 'quizaccess_duedate', $total_penalty) : '';
        }

        // Update the gradebook with the penalized grade and feedback.
        if ($penalizedgrade >= 0) {
            $gradeitem->update_final_grade(
                $userid,
                $penalizedgrade,
                'quizaccess_duedate',
                $feedback,
                FORMAT_HTML
            );
        }

        // For First Attempt method with penalty, set as overridden to prevent future updates.
        if ($quiz->grademethod == QUIZ_ATTEMPTFIRST && $total_penalty > 0) {
            $updatedgrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
            $updatedgrade->set_overridden(true);
            $updatedgrade->update('quizaccess_duedate');
        }

        // Verify the update by checking the grade_grades record.
        $updatedgrade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $userid
        ]);

        $processing = false;
    }
    /**
     * Handle the course module created event to add a due date event.
     *
     * @param \core\event\course_module_created $event The event object.
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        global $DB;

        static $processing = false;
        if ($processing) {
            return;
        }
        $processing = true;

        $cm = get_coursemodule_from_id('', $event->objectid);
        if ($cm->modname !== 'quiz') {
            $processing = false;
            return;
        }

        $quizid = $cm->instance;
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);

        if (!$settings || !$settings->duedate) {
            $processing = false;
            return;
        }

        // Create a due date event.
        $eventdata = new \stdClass();
        $eventdata->name = $cm->name . ' ' . get_string('isdue', 'quizaccess_duedate');
        $eventdata->description = get_string('quizduedate', 'quizaccess_duedate', $cm->name);
        $eventdata->format = FORMAT_HTML;
        $eventdata->courseid = $cm->course;
        $eventdata->groupid = 0;
        $eventdata->userid = 0;
        $eventdata->modulename = 'quiz';
        $eventdata->instance = $quizid;
        $eventdata->eventtype = 'due';
        $eventdata->timestart = $settings->duedate;
        $eventdata->timeduration = 0;
        $eventdata->visible = 1;

        // Delete any existing quiz-level due date event (not override events).
        $DB->delete_records('event', [
            'modulename' => 'quiz',
            'instance' => $quizid,
            'eventtype' => 'due',
            'userid' => 0,
            'groupid' => 0,
        ]);

        \calendar_event::create($eventdata);

        $processing = false;
    }

    /**
     * Handle the course module updated event to update the due date event.
     *
     * @param \core\event\course_module_updated $event The event object.
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        global $DB;

        static $processing = false;
        if ($processing) {
            return;
        }
        $processing = true;

        $cm = get_coursemodule_from_id('', $event->objectid);
        if ($cm->modname !== 'quiz') {
            $processing = false;
            return;
        }

        $quizid = $cm->instance;
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);

        // Delete any existing quiz-level due date event (not override events).
        $DB->delete_records('event', [
            'modulename' => 'quiz',
            'instance' => $quizid,
            'eventtype' => 'due',
            'userid' => 0,
            'groupid' => 0,
        ]);

        if ($settings && $settings->duedate) {
            // Create or update the due date event.
            $eventdata = new \stdClass();
            $eventdata->name = $cm->name . ' ' . get_string('isdue', 'quizaccess_duedate');
            $eventdata->description = get_string('quizduedate', 'quizaccess_duedate', $cm->name);
            $eventdata->format = FORMAT_HTML;
            $eventdata->courseid = $cm->course;
            $eventdata->groupid = 0;
            $eventdata->userid = 0;
            $eventdata->modulename = 'quiz';
            $eventdata->instance = $quizid;
            $eventdata->eventtype = 'due';
            $eventdata->timestart = $settings->duedate;
            $eventdata->timeduration = 0;
            $eventdata->visible = 1;

            \calendar_event::create($eventdata);
        }

        $processing = false;
    }
}