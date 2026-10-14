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
 * Admin page to move legacy due dates into core (Moodle 5.3), the web twin of cli/migrate_to_core.php.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use quizaccess_duedate\local\core_migration;

$action = optional_param('action', '', PARAM_ALPHA);
$courseids = optional_param('courseids', '', PARAM_SEQUENCE);
$showall = optional_param('showall', false, PARAM_BOOL);

admin_externalpage_setup('quizaccess_duedate_migrate');
$url = new moodle_url('/mod/quiz/accessrule/duedate/migrate.php');
$component = 'quizaccess_duedate';

$courselist = array_values(array_filter(array_map('intval', explode(',', $courseids))));
$reports = [];
$collect = function (stdClass $report) use (&$reports): void {
    $reports[] = $report;
};

// The preview as a CSV file.
if ($action === 'download') {
    require_once($CFG->libdir . '/csvlib.class.php');
    core_migration::migrate_all(0, true, $collect);
    $rows = [['courseid', 'course', 'quizid', 'quiz', 'outcome', 'details']];
    foreach ($reports as $report) {
        foreach ($report->messages as $message) {
            $rows[] = [$report->courseid, $report->coursename, $report->quizid, $report->quizname,
                $message->outcome, $message->text];
        }
    }
    csv_export_writer::download_array('quiz-duedate-migration-' . date('Ymd-Hi'), $rows);
    die();
}

// Long runs: one quiz at a time, rebuilding its calendar events.
$writes = ['run', 'keeprun'];
if (in_array($action, $writes)) {
    require_sesskey();
    core_php_time_limit::raise();
    raise_memory_limit(MEMORY_EXTRA);
}

$results = null;
$notice = null;
switch ($action) {
    case 'preview':
    case 'run':
        if ($action === 'run' && !core_migration::core_has_duedate()) {
            $notice = [get_string('migrate_needs53', $component), 'error'];
            break;
        }
        $totals = core_migration::migrate_all(0, $action !== 'run', $collect);
        if ($action === 'run') {
            set_config(core_migration::DONE_FLAG, time(), $component);
        }
        $results = ['dates', $action === 'run', $totals];
        break;

    case 'keeppreview':
    case 'keeprun':
        if (!$courselist) {
            $notice = [get_string('migrate_needcourses', $component), 'error'];
            break;
        }
        $totals = core_migration::keep_penalties($courselist, $action !== 'keeprun', $collect);
        $results = ['penalties', $action === 'keeprun', $totals];
        break;

}

/**
 * Render the per-quiz results as a table, hiding quizzes where nothing changes unless asked.
 *
 * @param stdClass[] $reports Reports from core_migration.
 * @param bool $showall Include quizzes whose only outcome is 'same'.
 * @return string HTML.
 */
function quizaccess_duedate_render_reports(array $reports, bool $showall): string {
    $component = 'quizaccess_duedate';
    $badges = [core_migration::SET => 'bg-success', core_migration::SAME => 'bg-secondary',
        core_migration::SKIP => 'bg-light text-dark', core_migration::CONFLICT => 'bg-warning text-dark',
        core_migration::WARN => 'bg-danger'];

    $table = new html_table();
    $table->head = [get_string('migrate_col_course', $component), get_string('migrate_col_quiz', $component),
        get_string('migrate_col_outcome', $component), get_string('migrate_col_details', $component)];
    $table->attributes['class'] = 'generaltable table-sm';
    foreach ($reports as $report) {
        $outcomes = array_unique(array_column($report->messages, 'outcome'));
        if (!$showall && $outcomes === [core_migration::SAME]) {
            continue;
        }
        foreach ($report->messages as $message) {
            $table->data[] = [
                $report->courseid ? html_writer::link(new moodle_url('/course/view.php', ['id' => $report->courseid]),
                    s($report->coursename)) : '',
                s($report->quizname),
                html_writer::span($message->outcome, 'badge ' . $badges[$message->outcome]),
                s($message->text),
            ];
        }
    }
    return $table->data ? html_writer::table($table) : '';
}

$status = core_migration::status();
$yes = get_string('yes');
$no = get_string('no');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('migratepage', $component));
echo html_writer::tag('p', get_string('migrate_intro', $component));

if ($notice) {
    echo $OUTPUT->notification($notice[0], $notice[1]);
}

// Status.
echo $OUTPUT->heading(get_string('migrate_status', $component), 3);
$table = new html_table();
$table->attributes['class'] = 'generaltable w-auto';
$table->data = [
    [get_string('migrate_status_incore', $component), $status->incore ? $yes : $no],
    [get_string('migrate_status_done', $component), $status->done ? userdate($status->done) : $no],
    [get_string('migrate_status_pending', $component), $status->incore ? $status->pending : '-'],
    [get_string('migrate_status_ugdone', $component), !$status->ugpresent ? get_string('migrate_status_noug', $component)
        : ($status->ugdone ? userdate($status->ugdone) : $no)],
    [get_string('migrate_status_switches', $component), $status->ugpresent ? $status->switchespending : '-'],
    [get_string('migrate_status_ready', $component), $status->ready ? $yes : $no],
];
echo html_writer::table($table);

// Results of the last action, under the section it belongs to.
$renderresults = function (string $section) use ($results, $reports, $showall, $component, $OUTPUT): void {
    if (!$results || $results[0] !== $section) {
        return;
    }
    echo $OUTPUT->notification(get_string($results[1] ? 'migrate_results_done' : 'migrate_results_preview', $component) .
        '. ' . get_string('migrate_totals', $component, (object) $results[2]), $results[1] ? 'success' : 'info');
    echo html_writer::tag('p', get_string('migrate_outcomes', $component), ['class' => 'small text-muted']);
    echo quizaccess_duedate_render_reports($reports, $showall);
};

// 1. Due dates and extensions.
echo $OUTPUT->heading(get_string('migrate_dates', $component), 3);
echo html_writer::tag('p', get_string('migrate_dates_help', $component));
echo html_writer::start_div('d-flex flex-wrap gap-2 mb-3');
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'preview']), get_string('migrate_preview', $component), 'get');
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'preview', 'showall' => 1]),
    get_string('migrate_showall', $component), 'get');
echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'download']), get_string('migrate_download', $component), 'get');
if ($status->incore) {
    $button = new single_button(new moodle_url($url, ['action' => 'run']), get_string('migrate_run', $component),
        'post', single_button::BUTTON_PRIMARY);
    $button->add_confirm_action(get_string('migrate_run_confirm', $component));
    echo $OUTPUT->render($button);
}
echo html_writer::end_div();
if (!$status->incore) {
    echo html_writer::tag('p', get_string('migrate_needs53', $component), ['class' => 'text-muted']);
}
$renderresults('dates');

// 2. Late penalties to keep.
echo $OUTPUT->heading(get_string('migrate_penalties', $component), 3);
echo html_writer::tag('p', get_string('migrate_penalties_help', $component));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label(get_string('migrate_courseids', $component), 'quizaccess_duedate_courseids', true,
    ['class' => 'me-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'courseids', 'id' => 'quizaccess_duedate_courseids',
    'value' => implode(',', $courselist), 'class' => 'form-control d-inline-block w-auto me-2', 'size' => 30]);
echo html_writer::tag('button', get_string('migrate_preview', $component),
    ['type' => 'submit', 'name' => 'action', 'value' => 'keeppreview', 'class' => 'btn btn-secondary me-2']);
echo html_writer::tag('button', get_string('migrate_writerules', $component),
    ['type' => 'submit', 'name' => 'action', 'value' => 'keeprun', 'class' => 'btn btn-primary',
     'onclick' => 'return confirm(' . json_encode(get_string('migrate_writerules_confirm', $component)) . ');']);
echo html_writer::end_tag('form');
$renderresults('penalties');

echo $OUTPUT->footer();
