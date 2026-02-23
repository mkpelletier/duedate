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
 * Due date override delete confirmation page.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_settings;

$overrideid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);

$override = $DB->get_record('quizaccess_duedate_overrides', ['id' => $overrideid], '*', MUST_EXIST);
$quizobj = quiz_settings::create($override->quizid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);
require_capability('quizaccess/duedate:manageoverrides', $context);

$url = new moodle_url('/mod/quiz/accessrule/duedate/overridedelete.php', ['id' => $override->id]);
$cancelurl = new moodle_url('/mod/quiz/accessrule/duedate/overrides.php',
    ['cmid' => $cm->id, 'mode' => !empty($override->userid) ? 'user' : 'group']);

if ($confirm) {
    require_sesskey();

    // Delete associated calendar event first.
    \quizaccess_duedate\override_manager::delete_calendar_event($override);

    // Delete the override record.
    \quizaccess_duedate\override_manager::delete_override($override->id);

    // Recalculate grades — user now falls back to group override or quiz default.
    \quizaccess_duedate\override_manager::recalculate_grades_for_override($override);

    redirect($cancelurl);
}

// Show confirmation page.
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(get_string('deleteextension', 'quizaccess_duedate'));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

echo $OUTPUT->header();

if ($override->groupid) {
    $group = $DB->get_record('groups', ['id' => $override->groupid]);
    $confirmstr = get_string('extensiondeletegroupconfirm', 'quizaccess_duedate',
        format_string($group->name));
} else {
    $user = $DB->get_record('user', ['id' => $override->userid]);
    $confirmstr = get_string('extensiondeleteuserconfirm', 'quizaccess_duedate',
        fullname($user));
}

$confirmurl = new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]);
echo $OUTPUT->confirm($confirmstr, $confirmurl, $cancelurl);
echo $OUTPUT->footer();
