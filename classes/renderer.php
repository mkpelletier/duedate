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
 * Renderer for quizaccess_duedate to modify quiz summary page display.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_duedate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/renderer.php');

class renderer extends \mod_quiz_renderer {

    /**
     * Render the quiz view page (summary page with attempt button).
     *
     * @param \mod_quiz\quiz_settings $quizobj The quiz settings object.
     * @param \moodle_url $redirecturl The URL to redirect to after the attempt.
     * @return string The rendered HTML.
     */
    public function view(\mod_quiz\quiz_settings $quizobj, \moodle_url $redirecturl) {
        $output = parent::view($quizobj, $redirecturl);

        global $USER, $DB;
        $quiz = $quizobj->get_quiz();
        $cm = $quizobj->get_cm();
        $duedate = !empty($cm->customdata['duedate']) ? $cm->customdata['duedate'] : 0;

        // Override with user-specific due date if applicable.
        $effectiveduedate = \quizaccess_duedate\override_manager::get_effective_duedate(
            $quiz->id, $USER->id
        );
        if ($effectiveduedate) {
            $duedate = $effectiveduedate;
        }

        if ($duedate) {
            $date = userdate($duedate, get_string('strftimedate', 'langconfig'));
            $time = userdate($duedate, get_string('strftimetime', 'langconfig'));
            $formattedduedate = "Due: {$date}, {$time}";

            $availabilitydata = $this->availability_data($quizobj, $cm);
            if (!isset($availabilitydata['dates'])) {
                $availabilitydata['dates'] = [];
            }
            $availabilitydata['dates'][] = [
                'label' => get_string('duedate', 'quizaccess_duedate'),
                'data' => $formattedduedate,
            ];

            $availabilityhtml = $this->render_from_template('mod_quiz/availability_info', $availabilitydata);
            $output = str_replace(
                $this->availability_data($quizobj, $cm, true),
                $availabilityhtml,
                $output
            );
            debugging("Renderer: Added due date to availability block for Quiz ID: {$quiz->id}, Due date: {$formattedduedate}", DEBUG_DEVELOPER);
        } else {
            debugging("Renderer: No due date found for Quiz ID: {$quiz->id}", DEBUG_DEVELOPER);
        }

        return $output;
    }
}