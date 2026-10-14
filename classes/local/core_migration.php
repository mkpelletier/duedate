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

namespace quizaccess_duedate\local;

/**
 * Moves this plugin's due dates and extensions into the quiz's own fields, added in Moodle 5.3.
 *
 * The class due date goes to quiz.duedate, and each extension to the duedate on the matching
 * core quiz_overrides row, which is created when the student or group has none. Core resolves
 * an override the way this plugin did (the student's own date, then the latest of their groups'
 * dates, then the class date), so each student keeps the date they had.
 *
 * Nothing that core already holds is overwritten: a date someone set in core after the upgrade
 * is kept and reported as a conflict. The plugin's own tables are left untouched, so the
 * migration can be run again and checked until the plugin is uninstalled.
 *
 * Late penalties move to local_unifiedgrader, which owns them on 5.3: each penalised quiz gets
 * Unified Grader's on/off switch here, and {@see keep_penalty()} writes the rate where the
 * inherited penalty rules would differ.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_migration {
    /** @var int Version of mod_quiz that added quiz.duedate (MDL-82521), as local_unifiedgrader checks it. */
    public const CORE_DUEDATE_VERSION = 2026083100;

    /** @var string Config flag set once a full migration has run. */
    public const DONE_FLAG = 'migratedtocore';

    /** @var string A value was written to core. */
    public const SET = 'set';
    /** @var string Core already held the same value. */
    public const SAME = 'same';
    /** @var string Nothing to move, or the record cannot be moved. */
    public const SKIP = 'skip';
    /** @var string Core holds a different value, which was kept. */
    public const CONFLICT = 'conflict';
    /** @var string Moved, but a teacher should look at the dates. */
    public const WARN = 'warn';

    /**
     * Whether the quiz has its own due date on this site, so this plugin steps aside.
     *
     * @return bool
     */
    public static function core_has_duedate(): bool {
        return (int) get_config('mod_quiz', 'version') >= self::CORE_DUEDATE_VERSION;
    }

    /**
     * IDs of every quiz with a legacy due date or extension, optionally in one course.
     *
     * @param int $courseid Course ID, or 0 for the whole site.
     * @return int[]
     */
    public static function get_quiz_ids(int $courseid = 0): array {
        global $DB;

        $sql = "SELECT q.id
                  FROM {quiz} q
                 WHERE (EXISTS (SELECT 1 FROM {quizaccess_duedate_instances} i WHERE i.quizid = q.id)
                        OR EXISTS (SELECT 1 FROM {quizaccess_duedate_overrides} o WHERE o.quizid = q.id))";
        $params = [];
        if ($courseid) {
            $sql .= " AND q.course = :courseid";
            $params['courseid'] = $courseid;
        }
        return array_map('intval', $DB->get_fieldset_sql($sql . " ORDER BY q.course, q.id", $params));
    }

    /**
     * Migrate every quiz, or every quiz in one course.
     *
     * @param int $courseid Course ID, or 0 for the whole site.
     * @param bool $dryrun Report what would change without writing anything.
     * @param callable|null $progress Called with each quiz's report as it is done.
     * @return array Totals keyed by outcome, plus 'quizzes' and 'courses'.
     */
    public static function migrate_all(int $courseid = 0, bool $dryrun = true, ?callable $progress = null): array {
        $totals = ['quizzes' => 0, 'courses' => 0, self::SET => 0, self::SAME => 0, self::SKIP => 0,
            self::CONFLICT => 0, self::WARN => 0];
        $courses = [];

        foreach (self::get_quiz_ids($courseid) as $quizid) {
            $report = self::migrate_quiz($quizid, $dryrun);
            $totals['quizzes']++;
            $courses[$report->courseid] = true;
            foreach ($report->messages as $message) {
                $totals[$message->outcome]++;
            }
            if ($progress) {
                $progress($report);
            }
        }

        // The course cache carries each quiz's due date for the course page.
        if (!$dryrun) {
            foreach (array_keys($courses) as $id) {
                if ($id) {
                    rebuild_course_cache($id, true);
                }
            }
        }
        $totals['courses'] = count(array_filter(array_keys($courses)));
        return $totals;
    }

    /**
     * Migrate one quiz's due date and extensions.
     *
     * On a site without core quiz due dates only a dry run is possible, which reports what
     * would be written.
     *
     * @param int $quizid Quiz ID.
     * @param bool $dryrun Report what would change without writing anything.
     * @return \stdClass Report with quizid, courseid, coursename, quizname and messages.
     */
    public static function migrate_quiz(int $quizid, bool $dryrun = true): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $incore = self::core_has_duedate();
        if (!$dryrun && !$incore) {
            throw new \coding_exception('Quiz due dates can only be moved into core on Moodle 5.3 or later.');
        }

        $report = (object) ['quizid' => $quizid, 'courseid' => 0, 'coursename' => '', 'quizname' => '',
            'messages' => []];

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            self::note($report, self::SKIP, 'The quiz no longer exists.');
            return $report;
        }
        $report->courseid = (int) $quiz->course;
        $report->coursename = (string) $DB->get_field('course', 'shortname', ['id' => $quiz->course]);
        $report->quizname = $quiz->name;

        $legacy = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);
        $overrides = $DB->get_records('quizaccess_duedate_overrides', ['quizid' => $quizid], 'id');
        if (!$legacy || empty($legacy->duedate)) {
            // The plugin ignored extensions on a quiz without a class due date, so they never applied.
            $text = 'No legacy due date.';
            if ($overrides) {
                $text .= ' ' . count($overrides) . ' extension(s) never applied and are not moved.';
            }
            self::note($report, self::SKIP, $text);
            return $report;
        }
        $legacyduedate = (int) $legacy->duedate;

        $cm = get_coursemodule_from_instance('quiz', $quizid, $quiz->course);
        if (!$cm) {
            self::note($report, self::SKIP, 'The quiz has no course module.');
            return $report;
        }

        $transaction = $dryrun ? null : $DB->start_delegated_transaction();

        // The class due date.
        $coreduedate = $incore ? (int) $quiz->duedate : 0;
        $when = self::date($legacyduedate);
        if ($coreduedate === $legacyduedate) {
            self::note($report, self::SAME, "Due date {$when} is already in core.");
        } else if ($coreduedate) {
            self::note($report, self::CONFLICT, "Core has due date " . self::date($coreduedate) .
                ", kept instead of the legacy {$when}.");
        } else {
            if (!$dryrun) {
                $DB->set_field('quiz', 'duedate', $legacyduedate, ['id' => $quizid]);
            }
            $quiz->duedate = $legacyduedate;
            self::note($report, self::SET, "Due date {$when}.");
        }

        // Core refuses to save the quiz settings with these dates until a teacher changes them.
        if ($quiz->timeopen && $quiz->duedate <= $quiz->timeopen) {
            self::note($report, self::WARN, 'The due date is not after the open date; core will ask for it to be ' .
                'changed the next time the quiz settings are saved.');
        }
        if ($quiz->timeclose && $quiz->duedate > $quiz->timeclose) {
            self::note($report, self::WARN, 'The due date is after the close date; core will ask for it to be ' .
                'changed the next time the quiz settings are saved.');
        }

        // The extensions.
        $touched = [];
        foreach ($overrides as $override) {
            $touched[] = self::migrate_override($report, $quiz, $override, $incore, $dryrun);
        }

        // Unified Grader's late penalty switch, which takes over from this plugin's on 5.3.
        if (!empty($legacy->penaltyenabled)) {
            self::migrate_penalty_switch($report, (int) $cm->id, $dryrun);
        }

        if (!$dryrun) {
            // Core rebuilds every due date event on the quiz, replacing the ones this plugin wrote.
            \mod_quiz\local\quiz_overrides_cache_manager::purge_for_overrides(array_filter($touched));
            $quiz->coursemodule = $cm->id;
            quiz_update_events($quiz);
            $transaction->allow_commit();
        }

        return $report;
    }

    /**
     * Move one extension onto the student's or group's core override.
     *
     * The core row is inserted with only the due date set, even when it matches the class due
     * date: a student's own date outranks their groups' dates, as it did in this plugin.
     *
     * @param \stdClass $report The quiz's report.
     * @param \stdClass $quiz The quiz record, with the due date it will have in core.
     * @param \stdClass $override The legacy override.
     * @param bool $incore Whether core quiz due dates exist on this site.
     * @param bool $dryrun Report what would change without writing anything.
     * @return \stdClass|null The core override written, for the cache purge, or null.
     */
    private static function migrate_override(\stdClass $report, \stdClass $quiz, \stdClass $override, bool $incore,
            bool $dryrun): ?\stdClass {
        global $DB;

        $when = self::date((int) $override->duedate);
        if (!empty($override->userid)) {
            $key = 'userid';
            $user = $DB->get_record('user', ['id' => $override->userid], 'id, deleted, username');
            if (!$user || $user->deleted) {
                self::note($report, self::SKIP, "Extension to {$when} for deleted user {$override->userid}.");
                return null;
            }
            $who = "user {$user->username} ({$user->id})";
        } else if (!empty($override->groupid)) {
            $key = 'groupid';
            $group = groups_get_group($override->groupid);
            if (!$group || (int) $group->courseid !== (int) $quiz->course) {
                self::note($report, self::SKIP, "Extension to {$when} for deleted group {$override->groupid}.");
                return null;
            }
            $who = "group {$group->name} ({$group->id})";
        } else {
            self::note($report, self::SKIP, "Extension {$override->id} has neither a user nor a group.");
            return null;
        }

        $core = $incore ? $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, $key => $override->$key]) : false;
        if ($core && $core->duedate !== null) {
            if ((int) $core->duedate === (int) $override->duedate) {
                self::note($report, self::SAME, "Extension to {$when} for {$who} is already in core.");
            } else {
                self::note($report, self::CONFLICT, "Core has extension to " . self::date((int) $core->duedate) .
                    " for {$who}, kept instead of the legacy {$when}.");
            }
            return null;
        }

        if ($core) {
            $core->duedate = (int) $override->duedate;
            if (!$dryrun) {
                $DB->set_field('quiz_overrides', 'duedate', $core->duedate, ['id' => $core->id]);
            }
            self::note($report, self::SET, "Extension to {$when} for {$who}, added to their existing override.");
        } else {
            $core = (object) ['quiz' => $quiz->id, $key => $override->$key, 'duedate' => (int) $override->duedate];
            if (!$dryrun) {
                $core->id = $DB->insert_record('quiz_overrides', $core);
            }
            self::note($report, self::SET, "Extension to {$when} for {$who}.");
        }

        $timeclose = isset($core->timeclose) ? (int) $core->timeclose : (int) $quiz->timeclose;
        if ($timeclose && $core->duedate > $timeclose) {
            self::note($report, self::WARN, "The extension for {$who} is after the quiz closes (" .
                self::date($timeclose) . "), so the student cannot use it.");
        }

        return $core;
    }

    /**
     * Switch Unified Grader's late penalties on for a quiz this plugin penalised.
     *
     * Until a quiz has a saved switch, Unified Grader reads it from this plugin's table, so it
     * would turn off when the plugin is uninstalled. A saved switch is left as it is, including
     * one a teacher turned off.
     *
     * @param \stdClass $report The quiz's report.
     * @param int $cmid Course module ID.
     * @param bool $dryrun Report what would change without writing anything.
     */
    private static function migrate_penalty_switch(\stdClass $report, int $cmid, bool $dryrun): void {
        global $DB;

        $settings = '\local_unifiedgrader\penalty\activity_settings';
        if (!class_exists($settings) || !$DB->get_manager()->table_exists('local_unifiedgrader_penset')) {
            return;
        }
        $saved = $DB->get_field('local_unifiedgrader_penset', 'enabled', ['cmid' => $cmid]);
        if ($saved !== false) {
            self::note($report, $saved ? self::SAME : self::CONFLICT, $saved
                ? 'Unified Grader late penalties are already on.'
                : 'Unified Grader late penalties were switched off for this quiz, and are kept off.');
            return;
        }
        if (!$dryrun) {
            $settings::set_enabled($cmid, true);
        }
        self::note($report, self::SET, 'Unified Grader late penalties switched on.');
    }

    /**
     * The late penalty tiers that reproduce a per-day penalty in core's gradepenalty_duedate rules.
     *
     * The plugin charged the rate for every started day late, up to the cap or 100%. A rule
     * applies when the submission is late by up to its overdueby, and the last rule applies
     * beyond that, so one rule per day until the cap is reached reproduces it exactly, as long
     * as every tier is a whole percent: core stores the penalty with no decimals on MySQL.
     *
     * @param float $rate Percent per day late.
     * @param float|null $cap Maximum percent, or null for 100.
     * @return array List of ['overdueby' => seconds, 'penalty' => percent].
     */
    public static function penalty_tiers(float $rate, ?float $cap = null): array {
        $max = ($cap !== null && $cap > 0) ? min($cap, 100.0) : 100.0;
        if ($rate <= 0) {
            return [];
        }
        $tiers = [];
        for ($day = 1; $day <= (int) ceil($max / $rate); $day++) {
            $tiers[] = ['overdueby' => $day * DAYSECS, 'penalty' => min($day * $rate, $max)];
        }
        return $tiers;
    }

    /**
     * Where the migration stands, and whether the plugin can be uninstalled.
     *
     * Quizzes are counted directly rather than trusting the done flags: a quiz restored from an
     * old backup after a migration ran has its dates moved on restore, but Unified Grader's
     * penalty switch task runs only once.
     *
     * @return \stdClass incore, done (timestamp or 0), pending, ugpresent, ugdone, switchespending, ready.
     */
    public static function status(): \stdClass {
        global $DB;

        $status = (object) [
            'incore' => self::core_has_duedate(),
            'done' => (int) get_config('quizaccess_duedate', self::DONE_FLAG),
            'pending' => 0,
            'ugpresent' => $DB->get_manager()->table_exists('local_unifiedgrader_penset'),
            'ugdone' => (int) get_config('local_unifiedgrader', 'penalties_migrated_53'),
            'switchespending' => 0,
        ];
        if ($status->incore) {
            foreach (self::get_quiz_ids() as $quizid) {
                foreach (self::migrate_quiz($quizid, true)->messages as $message) {
                    $status->pending += $message->outcome === self::SET ? 1 : 0;
                }
            }
        }
        if ($status->ugpresent) {
            $status->switchespending = $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {quizaccess_duedate_instances} qd
                   JOIN {course_modules} cm ON cm.instance = qd.quizid
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              LEFT JOIN {local_unifiedgrader_penset} ps ON ps.cmid = cm.id
                  WHERE qd.penaltyenabled = 1 AND qd.duedate > 0 AND ps.id IS NULL");
        }
        $status->ready = $status->incore && $status->done && !$status->pending && !$status->switchespending;
        return $status;
    }

    /**
     * Run {@see keep_penalty()} for every legacy quiz in the given courses.
     *
     * @param int[] $courseids Courses whose quizzes should keep their penalty.
     * @param bool $dryrun Report what would change without writing anything.
     * @param callable|null $progress Called with each quiz's report as it is done.
     * @return array Totals keyed by outcome, plus 'quizzes'.
     */
    public static function keep_penalties(array $courseids, bool $dryrun = true, ?callable $progress = null): array {
        global $DB;

        $totals = ['quizzes' => 0, self::SET => 0, self::SAME => 0, self::SKIP => 0, self::CONFLICT => 0, self::WARN => 0];
        foreach ($courseids as $courseid) {
            $quizids = $DB->get_fieldset_sql(
                "SELECT i.quizid
                   FROM {quizaccess_duedate_instances} i
                   JOIN {quiz} q ON q.id = i.quizid
                  WHERE q.course = :courseid
               ORDER BY i.duedate, i.quizid", ['courseid' => $courseid]);
            foreach ($quizids as $quizid) {
                $report = self::keep_penalty((int) $quizid, $dryrun);
                $totals['quizzes']++;
                foreach ($report->messages as $message) {
                    $totals[$message->outcome]++;
                }
                if ($progress) {
                    $progress($report);
                }
            }
        }
        return $totals;
    }

    /**
     * Keep a quiz's legacy late penalty where the inherited gradepenalty_duedate rules would change it.
     *
     * Rules are written on the quiz itself, never the course, so assignments and forums keep the
     * course and site rules. Nothing is written when the inherited rules already give the same
     * penalty for every lateness the quiz allows: a quiz that closes within the site rules' reach
     * is penalised the same either way. Nor for a quiz no one can submit any more, or one that
     * already has rules of its own.
     *
     * @param int $quizid Quiz ID.
     * @param bool $dryrun Report what would change without writing anything.
     * @return \stdClass Report with quizid, courseid, coursename, quizname and messages.
     */
    public static function keep_penalty(int $quizid, bool $dryrun = true): \stdClass {
        global $DB;

        $report = (object) ['quizid' => $quizid, 'courseid' => 0, 'coursename' => '', 'quizname' => '',
            'messages' => []];
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        $legacy = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);
        if (!$quiz || !$legacy || !$legacy->penaltyenabled || !$legacy->duedate || $legacy->penalty <= 0) {
            self::note($report, self::SKIP, 'No legacy late penalty.');
            return $report;
        }
        $report->courseid = (int) $quiz->course;
        $report->coursename = (string) $DB->get_field('course', 'shortname', ['id' => $quiz->course]);
        $report->quizname = $quiz->name;

        $cm = get_fast_modinfo($quiz->course)->instances['quiz'][$quizid] ?? null;
        if (!$cm) {
            self::note($report, self::SKIP, 'The quiz has no course module.');
            return $report;
        }

        $rate = (float) $legacy->penalty;
        $cap = ($legacy->penaltycapenabled && $legacy->penaltycap > 0) ? (float) $legacy->penaltycap : null;
        $tiers = self::penalty_tiers($rate, $cap);
        $describe = "{$rate}% per day, " . ($cap === null ? 'no cap' : "cap {$cap}%");

        // The latest a student can submit: the close date, a grace period, or an override's close date.
        $maxlate = null;
        $overrides = $DB->get_records_select('quiz_overrides', 'quiz = :quiz AND timeclose IS NOT NULL',
            ['quiz' => $quizid], '', 'id, timeclose');
        $openended = array_filter($overrides, fn($o) => (int) $o->timeclose === 0);
        if ($quiz->timeclose && !$openended) {
            $grace = $quiz->overduehandling === 'graceperiod' ? (int) $quiz->graceperiod : 0;
            $lastclose = max(array_merge([(int) $quiz->timeclose + $grace],
                array_map(fn($o) => (int) $o->timeclose, array_values($overrides))));
            if ($lastclose < time()) {
                self::note($report, self::SKIP, 'Closed on ' . self::date($lastclose) . '; no more submissions.');
                return $report;
            }
            $maxlate = $lastclose - (int) $legacy->duedate;
        }

        $contextid = \context_module::instance($cm->id)->id;
        $existing = $DB->get_records('gradepenalty_duedate_rule', ['contextid' => $contextid], 'sortorder');
        if ($existing) {
            $same = count($existing) === count($tiers) && !array_filter(array_map(
                fn($rule, $tier) => (int) $rule->overdueby !== $tier['overdueby'] || (float) $rule->penalty != $tier['penalty'],
                array_values($existing), $tiers));
            self::note($report, $same ? self::SAME : self::CONFLICT, $same
                ? "The quiz already has rules for {$describe}."
                : 'The quiz already has its own penalty rules, kept instead of ' . $describe . '.');
            return $report;
        }

        // Compare the legacy penalty with the inherited rules for each day late the quiz allows.
        $days = min($maxlate === null ? 101 : (int) ceil($maxlate / DAYSECS), 101);
        $due = (int) $legacy->duedate;
        $differs = 0;
        for ($day = 1; $day <= $days && !$differs; $day++) {
            $inherited = \gradepenalty_duedate\penalty_calculator::get_penalty_from_rules($cm, $due + $day * DAYSECS, $due);
            if (abs($inherited - min($day * $rate, $cap ?? 100.0)) > 0.001) {
                $differs = $day;
            }
        }
        $reach = $maxlate === null ? 'no close date' : 'can be up to ' . round($maxlate / DAYSECS, 1) . ' days late';
        if (!$differs) {
            self::note($report, self::SAME, "The inherited rules give the same penalty as {$describe} ({$reach}).");
            return $report;
        }

        if (!$dryrun) {
            foreach ($tiers as $i => $tier) {
                (new \gradepenalty_duedate\penalty_rule(0, (object) ['contextid' => $contextid, 'sortorder' => $i,
                    'overdueby' => $tier['overdueby'], 'penalty' => $tier['penalty']]))->create();
            }
        }
        self::note($report, self::SET, count($tiers) . " rules for {$describe} ({$reach}; the inherited rules " .
            "differ from day {$differs}).");
        if (array_filter($tiers, fn($t) => floor($t['penalty']) != $t['penalty'])) {
            self::note($report, self::WARN, 'Core stores whole percents, so fractional tiers are rounded.');
        }
        return $report;
    }

    /**
     * Add a message to a quiz's report.
     *
     * @param \stdClass $report
     * @param string $outcome One of the outcome constants.
     * @param string $text
     */
    private static function note(\stdClass $report, string $outcome, string $text): void {
        $report->messages[] = (object) ['outcome' => $outcome, 'text' => $text];
    }

    /**
     * A timestamp as an unambiguous date for the report.
     *
     * @param int $time
     * @return string
     */
    private static function date(int $time): string {
        return userdate($time, '%Y-%m-%d %H:%M %Z');
    }
}
