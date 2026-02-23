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
 * Due date override create/edit page.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_settings;

$cmid = optional_param('cmid', 0, PARAM_INT);
$overrideid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Load override or quiz context.
$override = null;
if ($overrideid) {
    $override = $DB->get_record('quizaccess_duedate_overrides', ['id' => $overrideid], '*', MUST_EXIST);
    $quizobj = quiz_settings::create($override->quizid);
} else {
    $quizobj = quiz_settings::create_for_cmid($cmid);
}

$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);
require_capability('quizaccess/duedate:manageoverrides', $context);

// Check if duedate is configured.
$settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quiz->id]);
if (!$settings || !$settings->duedate) {
    throw new \moodle_exception('noduedateconfigured', 'quizaccess_duedate');
}

// Determine if group mode.
$groupmode = !empty($override->groupid) || ($action === 'addgroup');

$url = new moodle_url('/mod/quiz/accessrule/duedate/overrideedit.php');
if ($overrideid) {
    $url->param('id', $overrideid);
} else {
    $url->param('cmid', $cm->id);
}
if ($action) {
    $url->param('action', $action);
}
$PAGE->set_url($url);

$overridelisturl = new moodle_url('/mod/quiz/accessrule/duedate/overrides.php',
    ['cmid' => $cm->id, 'mode' => $groupmode ? 'group' : 'user']);

// Setup form.
$mform = new \quizaccess_duedate\form\override_form(
    $url, $cm, $quiz, $context, $groupmode, $override
);

if ($override) {
    $mform->set_data($override);
}

if ($mform->is_cancelled()) {
    redirect($overridelisturl);
} else if ($fromform = $mform->get_data()) {
    $record = new stdClass();
    $record->quizid = $quiz->id;
    $record->duedate = $fromform->duedate;

    if ($groupmode) {
        $record->groupid = $fromform->groupid;
        $record->userid = null;
    } else {
        $record->userid = $fromform->userid;
        $record->groupid = null;
    }

    if ($overrideid) {
        $record->id = $overrideid;
    }

    \quizaccess_duedate\override_manager::save_override($record);

    // Create/update calendar event for this override.
    \quizaccess_duedate\override_manager::update_calendar_event($record, $quiz->name, $course->id);

    // Recalculate grades for affected users so penalties reflect the new due date.
    \quizaccess_duedate\override_manager::recalculate_grades_for_override($record);

    redirect($overridelisturl);
}

// Display.
$title = $overrideid
    ? get_string('editduedateextension', 'quizaccess_duedate')
    : ($groupmode
        ? get_string('addgroupextension', 'quizaccess_duedate')
        : get_string('adduserextension', 'quizaccess_duedate'));

$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$mform->display();
echo $OUTPUT->footer();
