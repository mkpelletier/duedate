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
 * Move legacy quiz due dates and extensions into core (Moodle 5.3), or report on them.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use quizaccess_duedate\local\core_migration;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'execute' => false,
    'courseid' => 0,
    'penalties' => false,
    'status' => false,
    'keep-penalties' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Move quizaccess_duedate due dates and extensions into the quiz's own fields (Moodle 5.3).

Without --execute nothing is written: every quiz is listed with what would happen.
Works on Moodle 5.0 too, as a pre-upgrade inventory; --execute needs 5.3.

Options:
  --execute        Write the dates into core.
  --courseid=N     Only quizzes in this course.
  --penalties      Print each quiz's late penalty settings as CSV, with the
                   gradepenalty_duedate tiers that would reproduce them. 'exact' is
                   'no' when a tier is a fractional percent, which core rounds.
  --status         Show whether the migrations have run and the plugin can be uninstalled.
  --keep-penalties Needs --courseid, which can list several courses (--courseid=1028,856).
                   Write quiz-level gradepenalty_duedate rules that keep each quiz's legacy
                   penalty, where the inherited rules would give a different one for a
                   submission the quiz still allows. Works on 5.0 and 5.3; without --execute
                   nothing is written.
  -h, --help       Show this help.

Outcomes: set = written (or would be), same = already in core, skip = nothing to move,
conflict = core holds a different date, which is kept, warn = moved, but check the dates.

Example:
  php mod/quiz/accessrule/duedate/cli/migrate_to_core.php --courseid=12
  php mod/quiz/accessrule/duedate/cli/migrate_to_core.php --execute
  php mod/quiz/accessrule/duedate/cli/migrate_to_core.php --keep-penalties --courseid=1028,856
";
    exit(0);
}

if ($options['keep-penalties']) {
    $courseids = array_filter(array_map('intval', explode(',', (string) $options['courseid'])));
    if (!$courseids) {
        cli_error('--keep-penalties needs --courseid, naming the courses whose quizzes should keep their penalty.');
    }
    $execute = (bool) $options['execute'];
    echo ($execute ? "Keeping" : "Dry run: would keep") . " legacy late penalties.\n\n";
    $lastcourse = null;
    $totals = core_migration::keep_penalties($courseids, !$execute, function (stdClass $report) use (&$lastcourse): void {
        if ($report->courseid !== $lastcourse && $report->courseid) {
            echo "Course {$report->courseid} {$report->coursename}\n";
            $lastcourse = $report->courseid;
        }
        echo "  Quiz {$report->quizid} {$report->quizname}\n";
        foreach ($report->messages as $message) {
            echo sprintf("    %-8s %s\n", $message->outcome, $message->text);
        }
    });
    echo "\n" . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($totals), $totals)) . ".\n";
    if (!$execute) {
        echo "Nothing was written. Run again with --execute to write the rules.\n";
    }
    exit(0);
}

$courseid = (int) $options['courseid'];

if ($options['status']) {
    $status = core_migration::status();
    echo "Core quiz due dates (5.3):   " . ($status->incore ? 'yes' : 'no') . "\n";
    echo "Due dates moved into core:   " . ($status->done ? userdate($status->done) : 'no') . "\n";
    echo "Still to migrate:            " . ($status->incore ? $status->pending : 'n/a') . "\n";
    echo "Penalty switches (UG) run:   " . (!$status->ugpresent ? 'Unified Grader not installed' :
        ($status->ugdone ? userdate($status->ugdone) : 'no')) . "\n";
    echo "Penalty switches to save:    " . ($status->ugpresent ? $status->switchespending : 'n/a') . "\n";
    echo "Safe to uninstall:           " . ($status->ready ? 'yes' : 'no') . "\n";
    exit($status->ready ? 0 : 1);
}

if ($options['penalties']) {
    $sql = "SELECT i.*, q.name AS quizname, q.course, c.shortname, cm.id AS cmid
              FROM {quizaccess_duedate_instances} i
              JOIN {quiz} q ON q.id = i.quizid
              JOIN {course} c ON c.id = q.course
              JOIN {modules} m ON m.name = 'quiz'
              JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
             WHERE i.penaltyenabled = 1" . ($courseid ? " AND q.course = :courseid" : "") . "
          ORDER BY q.course, q.id";
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['courseid', 'course', 'cmid', 'quizid', 'quiz', 'duedate', 'penaltyperday', 'cap', 'tiers', 'exact'], ',', '"', '');
    foreach ($DB->get_records_sql($sql, ['courseid' => $courseid]) as $row) {
        $cap = $row->penaltycapenabled ? (float) $row->penaltycap : null;
        $rules = core_migration::penalty_tiers((float) $row->penalty, $cap);
        $tiers = array_map(fn($t) => ($t['overdueby'] / DAYSECS) . 'd:' . round($t['penalty'], 2) . '%', $rules);
        $exact = array_filter($rules, fn($t) => floor($t['penalty']) != $t['penalty']) ? 'no' : 'yes';
        fputcsv($out, [$row->course, $row->shortname, $row->cmid, $row->quizid, $row->quizname,
            $row->duedate ? userdate($row->duedate, '%Y-%m-%d %H:%M') : '', (float) $row->penalty,
            $cap ?? '', implode(' ', $tiers), $exact], ',', '"', '');
    }
    exit(0);
}

$execute = (bool) $options['execute'];
if ($execute && !core_migration::core_has_duedate()) {
    cli_error('This site has no core quiz due dates. Upgrade to Moodle 5.3 first, or run without --execute.');
}

echo ($execute ? "Moving" : "Dry run: would move") . " quiz due dates into core" .
    ($courseid ? " for course {$courseid}" : "") . ".\n\n";

$lastcourse = null;
$totals = core_migration::migrate_all($courseid, !$execute, function (stdClass $report) use (&$lastcourse): void {
    if ($report->courseid !== $lastcourse) {
        echo "Course {$report->courseid} {$report->coursename}\n";
        $lastcourse = $report->courseid;
    }
    echo "  Quiz {$report->quizid} {$report->quizname}\n";
    foreach ($report->messages as $message) {
        echo sprintf("    %-8s %s\n", $message->outcome, $message->text);
    }
});

if ($execute && !$courseid) {
    set_config(core_migration::DONE_FLAG, time(), 'quizaccess_duedate');
}

echo "\n{$totals['quizzes']} quizzes in {$totals['courses']} courses. " .
    "set {$totals['set']}, same {$totals['same']}, skip {$totals['skip']}, " .
    "conflict {$totals['conflict']}, warn {$totals['warn']}.\n";
if (!$execute) {
    echo "Nothing was written. Run again with --execute to migrate.\n";
}
exit(0);
