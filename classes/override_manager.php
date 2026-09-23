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
 * Override manager for quizaccess_duedate.
 *
 * Handles resolution and CRUD operations for per-user and per-group due date overrides.
 *
 * @todo When Moodle core implements the rule_overridable interface (MDL-80945),
 *       migrate to the native override system and remove this self-managed workaround.
 *       See: https://moodle.atlassian.net/browse/MDL-80945
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_duedate;

defined('MOODLE_INTERNAL') || die();

class override_manager {

    /**
     * Resolve the effective due date for a user on a given quiz.
     *
     * Priority: user override > group override (latest duedate across groups) > quiz default.
     * This mirrors the logic in Moodle core's quiz_update_effective_access().
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     * @return int The effective due date timestamp, or 0 if no due date is set.
     */
    public static function get_effective_duedate(int $quizid, int $userid): int {
        global $DB;

        // Load the quiz-level default.
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);
        if (!$settings || !$settings->duedate) {
            return 0;
        }
        $defaultduedate = (int) $settings->duedate;

        // Check for user-specific override (highest priority).
        $useroverride = $DB->get_record('quizaccess_duedate_overrides', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);
        if ($useroverride) {
            return (int) $useroverride->duedate;
        }

        // Check for group overrides.
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, course');
        if (!$quiz) {
            return $defaultduedate;
        }

        $groupings = groups_get_user_groups($quiz->course, $userid);
        if (!empty($groupings[0])) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($groupings[0]), SQL_PARAMS_NAMED);
            $params['quizid'] = $quizid;
            $sql = "SELECT duedate FROM {quizaccess_duedate_overrides}
                    WHERE quizid = :quizid AND groupid $insql";
            $records = $DB->get_records_sql($sql, $params);

            if (!empty($records)) {
                // Most permissive = latest due date.
                $duedates = array_map(function ($r) {
                    return (int) $r->duedate;
                }, $records);
                return max($duedates);
            }
        }

        // Fall back to quiz default.
        return $defaultduedate;
    }

    /**
     * Get all overrides for a quiz, optionally filtered by type.
     *
     * @param int $quizid The quiz ID.
     * @param string $mode 'user', 'group', or '' for all.
     * @return array Array of override records.
     */
    public static function get_overrides(int $quizid, string $mode = ''): array {
        global $DB;

        if ($mode === 'user') {
            return $DB->get_records_select(
                'quizaccess_duedate_overrides',
                'quizid = :quizid AND userid IS NOT NULL',
                ['quizid' => $quizid]
            );
        } else if ($mode === 'group') {
            return $DB->get_records_select(
                'quizaccess_duedate_overrides',
                'quizid = :quizid AND groupid IS NOT NULL',
                ['quizid' => $quizid]
            );
        }

        return $DB->get_records('quizaccess_duedate_overrides', ['quizid' => $quizid]);
    }

    /**
     * Save an override (insert or update).
     *
     * @param \stdClass $data Override data with quizid, userid/groupid, duedate.
     * @return int The override record ID.
     */
    public static function save_override(\stdClass $data): int {
        global $DB;

        $data->timemodified = time();

        if (!empty($data->id)) {
            $DB->update_record('quizaccess_duedate_overrides', $data);
            return (int) $data->id;
        } else {
            return (int) $DB->insert_record('quizaccess_duedate_overrides', $data);
        }
    }

    /**
     * Delete an override by ID.
     *
     * @param int $id The override record ID.
     */
    public static function delete_override(int $id): void {
        global $DB;
        $DB->delete_records('quizaccess_duedate_overrides', ['id' => $id]);
    }

    /**
     * Delete all overrides for a quiz.
     *
     * @param int $quizid The quiz ID.
     */
    public static function delete_all_overrides(int $quizid): void {
        global $DB;
        $DB->delete_records('quizaccess_duedate_overrides', ['quizid' => $quizid]);
    }

    /**
     * Check whether a user-level override already exists.
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     * @param int $excludeid Override ID to exclude (when editing).
     * @return bool
     */
    public static function user_override_exists(int $quizid, int $userid, int $excludeid = 0): bool {
        global $DB;
        $sql = 'quizid = :quizid AND userid = :userid';
        $params = ['quizid' => $quizid, 'userid' => $userid];
        if ($excludeid) {
            $sql .= ' AND id != :excludeid';
            $params['excludeid'] = $excludeid;
        }
        return $DB->record_exists_select('quizaccess_duedate_overrides', $sql, $params);
    }

    /**
     * Check whether a group-level override already exists.
     *
     * @param int $quizid The quiz ID.
     * @param int $groupid The group ID.
     * @param int $excludeid Override ID to exclude (when editing).
     * @return bool
     */
    public static function group_override_exists(int $quizid, int $groupid, int $excludeid = 0): bool {
        global $DB;
        $sql = 'quizid = :quizid AND groupid = :groupid';
        $params = ['quizid' => $quizid, 'groupid' => $groupid];
        if ($excludeid) {
            $sql .= ' AND id != :excludeid';
            $params['excludeid'] = $excludeid;
        }
        return $DB->record_exists_select('quizaccess_duedate_overrides', $sql, $params);
    }

    /**
     * Rewrite every due-date calendar event for a quiz from this plugin's tables.
     *
     * Events are written the way mod_quiz writes its own overrides, so the calendar shows
     * each student only the date that applies to them: a student's extension carries
     * courseid 0 and CALENDAR_EVENT_USER_OVERRIDE_PRIORITY, a group's date carries a
     * priority that ranks the latest date first, and the class date carries none. Before
     * v2.0 an extension was written as a course-wide event, so the whole class saw it.
     *
     * The full set is rebuilt rather than patched because core's quiz_update_events()
     * rewrites or deletes every calendar event on a quiz, these included, whenever the quiz
     * settings or one of core's own overrides are saved.
     *
     * @param int $quizid The quiz ID.
     */
    public static function refresh_calendar_events(int $quizid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $events = $DB->get_records('event', [
            'modulename' => 'quiz',
            'instance' => $quizid,
            'eventtype' => 'due',
        ]);
        foreach ($events as $event) {
            \calendar_event::load($event)->delete();
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, course, name');
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $quizid]);
        if (!$quiz || !$settings || !$settings->duedate) {
            // Without a class due date, get_effective_duedate() ignores overrides too.
            return;
        }

        $base = [
            'name' => get_string('duedatefor', 'quizaccess_duedate', $quiz->name),
            'description' => '',
            'format' => FORMAT_HTML,
            'courseid' => $quiz->course,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 'quiz',
            'instance' => $quizid,
            'eventtype' => 'due',
            'timestart' => $settings->duedate,
            'timeduration' => 0,
            'visible' => 1,
            'priority' => null,
        ];
        \calendar_event::create((object) $base, false);

        $overrides = self::get_overrides($quizid);

        // Rank group dates latest first, as quiz_get_group_override_priorities() ranks close
        // dates: the lowest priority wins, and a student in several groups gets the latest.
        $groupdates = [];
        foreach ($overrides as $override) {
            if (!empty($override->groupid)) {
                $groupdates[] = (int) $override->duedate;
            }
        }
        $groupdates = array_unique($groupdates);
        rsort($groupdates);
        $grouppriorities = array_flip($groupdates);

        foreach ($overrides as $override) {
            $event = $base;
            $event['timestart'] = $override->duedate;

            if (!empty($override->userid)) {
                // Core only shows a user event to its owner when courseid is 0.
                $event['courseid'] = 0;
                $event['userid'] = $override->userid;
                $event['priority'] = CALENDAR_EVENT_USER_OVERRIDE_PRIORITY;
            } else if (!empty($override->groupid)) {
                if (groups_get_group_name($override->groupid) === false) {
                    continue;
                }
                $event['groupid'] = $override->groupid;
                $event['priority'] = $grouppriorities[(int) $override->duedate] + 1;
            } else {
                continue;
            }

            \calendar_event::create((object) $event, false);
        }
    }

    /**
     * Rewrite a quiz's due-date calendar events after an override is saved.
     *
     * Kept for callers outside this plugin, such as local_unifiedgrader. It rebuilds the
     * whole quiz, since a group's priority depends on every other group's date.
     *
     * @param \stdClass $override The saved override record (must include quizid).
     * @param string $quizname Unused; the name is read from the quiz.
     * @param int $courseid Unused; the course is read from the quiz.
     */
    public static function update_calendar_event(\stdClass $override, string $quizname, int $courseid): void {
        self::refresh_calendar_events((int) $override->quizid);
    }

    /**
     * Delete the calendar event for an override that is about to be deleted.
     *
     * Kept for callers outside this plugin, such as local_unifiedgrader, which call it
     * before deleting the override record. Only this override's event is removed: a rebuild
     * at this point would write the override straight back.
     *
     * @param \stdClass $override The override record (must include quizid, and userid or groupid).
     */
    public static function delete_calendar_event(\stdClass $override): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $params = [
            'modulename' => 'quiz',
            'instance' => $override->quizid,
            'eventtype' => 'due',
        ];
        if (!empty($override->userid)) {
            $params['userid'] = $override->userid;
            $params['groupid'] = 0;
        } else if (!empty($override->groupid)) {
            $params['groupid'] = $override->groupid;
        } else {
            return;
        }

        foreach ($DB->get_records('event', $params) as $event) {
            \calendar_event::load($event)->delete();
        }
    }

    /**
     * Recalculate the penalty for a single user on a quiz.
     *
     * Clears any gradebook override, then triggers quiz regrading so the
     * user_graded event fires and the observer re-applies the correct penalty
     * based on the current effective due date.
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     */
    public static function recalculate_grades_for_user(int $quizid, int $userid): void {
        global $CFG, $DB;

        // Check the user has at least one finished attempt.
        $hasattempt = $DB->record_exists_select(
            'quiz_attempts',
            'quiz = :quizid AND userid = :userid AND timefinish > 0',
            ['quizid' => $quizid, 'userid' => $userid]
        );
        if (!$hasattempt) {
            return;
        }

        // Get the grade item.
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'courseid' => $quiz->course,
        ]);
        if (!$gradeitem) {
            return;
        }

        // Clear any existing override and reset finalgrade so the quiz module can update
        // the grade. We must nullify finalgrade to force a change — otherwise, if the new
        // rawgrade equals the old finalgrade, user_graded won't fire and the penalty
        // won't be recalculated.
        $gradegrade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $userid,
        ]);
        if ($gradegrade) {
            $gradegrade->overridden = 0;
            $gradegrade->finalgrade = null;
            $DB->update_record('grade_grades', $gradegrade);
        }

        // Trigger quiz regrading — this pushes the raw grade and fires user_graded event,
        // which our observer picks up to re-apply the correct penalty.
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        quiz_save_best_grade($quiz, $userid);
    }

    /**
     * Recalculate grades for all users affected by an override.
     *
     * For user overrides, recalculates the single user's grade.
     * For group overrides, recalculates all members of the group.
     *
     * @param \stdClass $override The override record (must include quizid, userid or groupid).
     */
    public static function recalculate_grades_for_override(\stdClass $override): void {
        if (!empty($override->userid)) {
            self::recalculate_grades_for_user((int) $override->quizid, (int) $override->userid);
        } else if (!empty($override->groupid)) {
            $members = groups_get_members($override->groupid, 'u.id');
            foreach ($members as $member) {
                self::recalculate_grades_for_user((int) $override->quizid, (int) $member->id);
            }
        }
    }
}
