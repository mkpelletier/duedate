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
 * Due date override listing page.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_settings;

$cmid = required_param('cmid', PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);

// Check capabilities.
$canedit = has_capability('quizaccess/duedate:manageoverrides', $context);
if (!$canedit) {
    require_capability('quizaccess/duedate:viewoverrides', $context);
}

// Check if duedate is configured.
$settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quiz->id]);
if (!$settings || !$settings->duedate) {
    throw new \moodle_exception('noduedateconfigured', 'quizaccess_duedate');
}

// Default mode.
$groups = groups_get_all_groups($cm->course);
if ($mode !== 'user' && $mode !== 'group') {
    $mode = !empty($groups) ? 'group' : 'user';
}
$groupmode = ($mode === 'group');

$url = new moodle_url('/mod/quiz/accessrule/duedate/overrides.php',
    ['cmid' => $cm->id, 'mode' => $mode]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(get_string('duedateextensions', 'quizaccess_duedate'));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('duedateextensions', 'quizaccess_duedate'));

// Tab navigation between user and group modes.
$userurl = new moodle_url($url, ['mode' => 'user']);
$groupurl = new moodle_url($url, ['mode' => 'group']);
$tabs = [];
if (!empty($groups)) {
    $tabs[] = new tabobject('group', $groupurl, get_string('groupextensions', 'quizaccess_duedate'));
}
$tabs[] = new tabobject('user', $userurl, get_string('userextensions', 'quizaccess_duedate'));
echo $OUTPUT->tabtree($tabs, $mode);

// Show quiz default due date for reference.
echo html_writer::tag('p',
    get_string('duedateinfo', 'quizaccess_duedate', userdate($settings->duedate)),
    ['class' => 'text-muted']
);

// Build the overrides table.
$table = new html_table();
$table->head = [];
$table->attributes['class'] = 'generaltable';

if ($groupmode) {
    $table->head[] = get_string('group');
} else {
    $table->head[] = get_string('user');
}
$table->head[] = get_string('extensionduedate', 'quizaccess_duedate');
if ($canedit) {
    $table->head[] = get_string('action');
}

$overrides = \quizaccess_duedate\override_manager::get_overrides($quiz->id, $mode);

if (empty($overrides)) {
    echo html_writer::tag('p', get_string('noextensions', 'quizaccess_duedate'));
} else {
    foreach ($overrides as $override) {
        $row = [];

        if ($groupmode) {
            $group = $DB->get_record('groups', ['id' => $override->groupid]);
            $row[] = $group ? format_string($group->name) : get_string('unknowngroup', 'quizaccess_duedate');
        } else {
            $user = $DB->get_record('user', ['id' => $override->userid]);
            $row[] = $user ? fullname($user) : get_string('unknownuser', 'quizaccess_duedate');
        }

        $row[] = userdate($override->duedate);

        if ($canedit) {
            $editurl = new moodle_url('/mod/quiz/accessrule/duedate/overrideedit.php',
                ['id' => $override->id]);
            $deleteurl = new moodle_url('/mod/quiz/accessrule/duedate/overridedelete.php',
                ['id' => $override->id]);

            $actions = '';
            $actions .= html_writer::link($editurl,
                $OUTPUT->pix_icon('t/edit', get_string('edit')));
            $actions .= ' ';
            $actions .= html_writer::link($deleteurl,
                $OUTPUT->pix_icon('t/delete', get_string('delete')));

            $row[] = $actions;
        }

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

// Add override button.
if ($canedit) {
    $action = $groupmode ? 'addgroup' : 'adduser';
    $label = $groupmode
        ? get_string('addgroupextension', 'quizaccess_duedate')
        : get_string('adduserextension', 'quizaccess_duedate');
    $addurl = new moodle_url('/mod/quiz/accessrule/duedate/overrideedit.php', [
        'cmid' => $cm->id,
        'action' => $action,
    ]);
    echo $OUTPUT->single_button($addurl, $label, 'get');
}

echo $OUTPUT->footer();
