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
 * Library functions for quizaccess_duedate.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add extra course module information for display on the course page.
 *
 * @param stdClass $cm The course module object.
 * @return cached_cm_info|null The course module info with due date added, or null if not applicable.
 */
function quizaccess_duedate_get_extra_coursemodule_info($cm) {
    global $DB;

    if ($cm->modname !== 'quiz') {
        debugging("get_extra_coursemodule_info: Skipping non-quiz module, cmid: {$cm->id}", DEBUG_DEVELOPER);
        return null;
    }

    $info = new cached_cm_info();
    $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $cm->instance]);

    if ($settings && $settings->duedate > 0) {
        $info->customdata['duedate'] = $settings->duedate;
        $info->customdata['duedatetext'] = get_string('duedate', 'quizaccess_duedate') . ': ' .
            userdate($settings->duedate, get_string('strftimedatetime', 'langconfig'));
    } else {
        debugging("get_extra_coursemodule_info: No due date for Quiz ID: {$cm->instance}, cmid: {$cm->id}", DEBUG_DEVELOPER);
    }

    return $info;
}

/**
 * Register the custom renderer for the quiz access rule.
 *
 * @param \mod_quiz\quiz_settings $quizobj The quiz settings object.
 * @param \context_module $context The context module.
 * @return \quiz_access_manager The quiz access manager with custom renderer.
 */
function quizaccess_duedate_quiz_access_manager(\mod_quiz\quiz_settings $quizobj, \context_module $context) {
    global $PAGE;

    $accessmanager = new \quiz_access_manager($quizobj, $context, $PAGE->cm->id);
    $PAGE->set_renderer('mod_quiz', new \quizaccess_duedate\renderer($PAGE->get_renderer('mod_quiz'), $PAGE->target));
    return $accessmanager;
}