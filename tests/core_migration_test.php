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

namespace quizaccess_duedate;

use quizaccess_duedate\local\core_migration;

/**
 * Tests for moving legacy due dates and extensions into core.
 *
 * @package    quizaccess_duedate
 * @category   test
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quizaccess_duedate\local\core_migration
 */
final class core_migration_test extends \advanced_testcase {
    /**
     * Skip a test that needs core quiz due dates (Moodle 5.3).
     */
    private function require_core_duedate(): void {
        if (!core_migration::core_has_duedate()) {
            $this->markTestSkipped('Needs core quiz due dates (Moodle 5.3).');
        }
    }

    /**
     * Create a quiz with a legacy due date, as the plugin stored it.
     *
     * @param \stdClass $course
     * @param int $duedate
     * @param array $quizfields Extra quiz fields.
     * @return \stdClass
     */
    private function create_legacy_quiz(\stdClass $course, int $duedate, array $quizfields = []): \stdClass {
        global $DB;

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id] + $quizfields);
        $DB->insert_record('quizaccess_duedate_instances', [
            'quizid' => $quiz->id,
            'duedate' => $duedate,
            'penaltyenabled' => 0,
            'penalty' => 10,
            'penaltycapenabled' => 0,
        ]);
        return $quiz;
    }

    /**
     * Add a legacy extension.
     *
     * @param int $quizid
     * @param string $key 'userid' or 'groupid'.
     * @param int $id
     * @param int $duedate
     */
    private function add_legacy_override(int $quizid, string $key, int $id, int $duedate): void {
        global $DB;
        $DB->insert_record('quizaccess_duedate_overrides',
            ['quizid' => $quizid, $key => $id, 'duedate' => $duedate, 'timemodified' => time()]);
    }

    /**
     * Outcomes in a report, in order.
     *
     * @param \stdClass $report
     * @return string[]
     */
    private function outcomes(\stdClass $report): array {
        return array_column($report->messages, 'outcome');
    }

    /**
     * Per-day penalties become one tier per day up to the cap.
     */
    public function test_penalty_tiers(): void {
        $tiers = core_migration::penalty_tiers(10, 25);
        $this->assertSame([DAYSECS, 2 * DAYSECS, 3 * DAYSECS], array_column($tiers, 'overdueby'));
        $this->assertEquals([10, 20, 25], array_column($tiers, 'penalty'));

        $tiers = core_migration::penalty_tiers(3, null);
        $this->assertCount(34, $tiers);
        $this->assertEquals(100, end($tiers)['penalty']);

        $this->assertSame([], core_migration::penalty_tiers(0, null));
    }

    /**
     * The tiers, saved as gradepenalty_duedate rules, give the penalty the plugin gave.
     */
    public function test_penalty_tiers_match_plugin_formula(): void {
        global $DB;

        if (!class_exists('\gradepenalty_duedate\penalty_calculator')) {
            $this->markTestSkipped('Needs gradepenalty_duedate.');
        }
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($quiz->cmid);
        $contextid = \context_module::instance($quiz->cmid)->id;

        // Whole percents: core stores the rule's penalty with no decimals on MySQL.
        [$rate, $cap] = [5.0, 50.0];
        foreach (core_migration::penalty_tiers($rate, $cap) as $i => $tier) {
            $DB->insert_record('gradepenalty_duedate_rule', ['contextid' => $contextid, 'sortorder' => $i,
                'overdueby' => $tier['overdueby'], 'penalty' => $tier['penalty']]);
        }

        $due = 1800000000;
        foreach ([1, HOURSECS, DAYSECS, DAYSECS + 1, 3 * DAYSECS + 5, 10 * DAYSECS, 30 * DAYSECS] as $late) {
            // The plugin's formula, from observer::user_graded().
            $expected = min(ceil($late / DAYSECS) * $rate, $cap);
            $actual = \gradepenalty_duedate\penalty_calculator::get_penalty_from_rules($cm, $due + $late, $due);
            $this->assertEquals($expected, $actual, "{$late} seconds late");
        }
    }

    /**
     * A dry run reports, on any version, and writes nothing.
     */
    public function test_dry_run_writes_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $due = time() + WEEKSECS;
        $quiz = $this->create_legacy_quiz($course, $due);
        $this->add_legacy_override($quiz->id, 'userid', $student->id, $due + DAYSECS);

        $report = core_migration::migrate_quiz($quiz->id, true);

        $this->assertSame([core_migration::SET, core_migration::SET], $this->outcomes($report));
        if (core_migration::core_has_duedate()) {
            $this->assertEquals(0, $DB->get_field('quiz', 'duedate', ['id' => $quiz->id]));
        }
        $this->assertFalse($DB->record_exists('quiz_overrides', ['quiz' => $quiz->id]));
    }

    /**
     * Writing is refused before 5.3.
     */
    public function test_execute_needs_core_duedate(): void {
        if (core_migration::core_has_duedate()) {
            $this->markTestSkipped('Only before Moodle 5.3.');
        }
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_legacy_quiz($course, time() + WEEKSECS);

        $this->expectException(\coding_exception::class);
        core_migration::migrate_quiz($quiz->id, false);
    }

    /**
     * Every student keeps the due date they had, and the calendar follows.
     */
    public function test_migrate_keeps_each_students_date(): void {
        global $DB;

        $this->require_core_duedate();
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $plain = $gen->create_and_enrol($course, 'student');
        $extended = $gen->create_and_enrol($course, 'student');
        $ingroups = $gen->create_and_enrol($course, 'student');
        $early = $gen->create_group(['courseid' => $course->id]);
        $late = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $early->id, 'userid' => $ingroups->id]);
        $gen->create_group_member(['groupid' => $late->id, 'userid' => $ingroups->id]);
        $gen->create_group_member(['groupid' => $late->id, 'userid' => $extended->id]);

        $due = time() + WEEKSECS;
        $quiz = $this->create_legacy_quiz($course, $due);
        $this->add_legacy_override($quiz->id, 'userid', $extended->id, $due + DAYSECS);
        $this->add_legacy_override($quiz->id, 'groupid', $early->id, $due + 2 * DAYSECS);
        $this->add_legacy_override($quiz->id, 'groupid', $late->id, $due + 4 * DAYSECS);

        // What the plugin gave each student.
        $before = [];
        foreach ([$plain, $extended, $ingroups] as $user) {
            $before[$user->id] = override_manager::get_effective_duedate($quiz->id, $user->id);
        }

        $report = core_migration::migrate_quiz($quiz->id, false);
        $this->assertSame(array_fill(0, 4, core_migration::SET), $this->outcomes($report));

        $this->assertEquals($due, $DB->get_field('quiz', 'duedate', ['id' => $quiz->id]));
        foreach ($before as $userid => $expected) {
            $effective = quiz_update_effective_access($DB->get_record('quiz', ['id' => $quiz->id]), $userid);
            $this->assertEquals($expected, $effective->duedate, "User {$userid}");
        }

        // Core wrote the calendar: the extension is the student's own event.
        $userevent = $DB->get_record('event', ['modulename' => 'quiz', 'instance' => $quiz->id,
            'eventtype' => 'due', 'userid' => $extended->id, 'courseid' => 0]);
        $this->assertEquals($due + DAYSECS, $userevent->timestart);

        // A second run finds everything in place.
        $again = core_migration::migrate_quiz($quiz->id, false);
        $this->assertSame(array_fill(0, 4, core_migration::SAME), $this->outcomes($again));
        $this->assertEquals(3, $DB->count_records('quiz_overrides', ['quiz' => $quiz->id]));
    }

    /**
     * Existing core overrides gain the due date; dates already set in core are kept.
     */
    public function test_migrate_merges_and_keeps_core_values(): void {
        global $DB;

        $this->require_core_duedate();
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $timelimit = $gen->create_and_enrol($course, 'student');
        $already = $gen->create_and_enrol($course, 'student');
        $gone = $gen->create_and_enrol($course, 'student');

        $due = time() + WEEKSECS;
        $quiz = $this->create_legacy_quiz($course, $due, ['timeclose' => $due + 2 * DAYSECS]);
        $DB->set_field('quiz', 'duedate', $due + HOURSECS, ['id' => $quiz->id]);

        $DB->insert_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $timelimit->id, 'timelimit' => 600]);
        $DB->insert_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $already->id, 'duedate' => $due + 5]);
        $this->add_legacy_override($quiz->id, 'userid', $timelimit->id, $due + 3 * DAYSECS);
        $this->add_legacy_override($quiz->id, 'userid', $already->id, $due + DAYSECS);
        $this->add_legacy_override($quiz->id, 'userid', $gone->id, $due + DAYSECS);
        delete_user($gone);

        $report = core_migration::migrate_quiz($quiz->id, false);
        $this->assertSame([
            core_migration::CONFLICT, // Class date set in core after the upgrade.
            core_migration::SET, core_migration::WARN, // Merged; after the close date.
            core_migration::CONFLICT, // Core extension kept.
            core_migration::SKIP, // Deleted user.
        ], $this->outcomes($report));

        $this->assertEquals($due + HOURSECS, $DB->get_field('quiz', 'duedate', ['id' => $quiz->id]));
        $merged = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $timelimit->id]);
        $this->assertEquals(600, $merged->timelimit);
        $this->assertEquals($due + 3 * DAYSECS, $merged->duedate);
        $this->assertEquals($due + 5,
            $DB->get_field('quiz_overrides', 'duedate', ['quiz' => $quiz->id, 'userid' => $already->id]));
        $this->assertFalse($DB->record_exists('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $gone->id]));
    }

    /**
     * A quiz the plugin penalised gets Unified Grader's late penalty switch; one turned off stays off.
     */
    public function test_migrate_switches_unified_grader_penalties(): void {
        global $DB;

        $this->require_core_duedate();
        if (!class_exists('\local_unifiedgrader\penalty\activity_settings')) {
            $this->markTestSkipped('Needs local_unifiedgrader.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $penalised = $this->create_legacy_quiz($course, time() + WEEKSECS);
        $DB->set_field('quizaccess_duedate_instances', 'penaltyenabled', 1, ['quizid' => $penalised->id]);
        $turnedoff = $this->create_legacy_quiz($course, time() + WEEKSECS);
        $DB->set_field('quizaccess_duedate_instances', 'penaltyenabled', 1, ['quizid' => $turnedoff->id]);
        \local_unifiedgrader\penalty\activity_settings::set_enabled((int) $turnedoff->cmid, false);
        $unpenalised = $this->create_legacy_quiz($course, time() + WEEKSECS);

        $this->assertSame([core_migration::SET, core_migration::SET],
            $this->outcomes(core_migration::migrate_quiz($penalised->id, true)));
        $this->assertFalse($DB->record_exists('local_unifiedgrader_penset', ['cmid' => $penalised->cmid]));

        core_migration::migrate_quiz($penalised->id, false);
        $this->assertEquals(1, $DB->get_field('local_unifiedgrader_penset', 'enabled', ['cmid' => $penalised->cmid]));
        $this->assertSame([core_migration::SAME, core_migration::SAME],
            $this->outcomes(core_migration::migrate_quiz($penalised->id, false)));

        $this->assertSame([core_migration::SET, core_migration::CONFLICT],
            $this->outcomes(core_migration::migrate_quiz($turnedoff->id, false)));
        $this->assertEquals(0, $DB->get_field('local_unifiedgrader_penset', 'enabled', ['cmid' => $turnedoff->cmid]));

        $this->assertSame([core_migration::SET], $this->outcomes(core_migration::migrate_quiz($unpenalised->id, false)));
        $this->assertFalse($DB->record_exists('local_unifiedgrader_penset', ['cmid' => $unpenalised->cmid]));
    }

    /**
     * On 5.3 the rule loads nothing, so core's due date is not hidden by the legacy one.
     */
    public function test_rule_steps_aside(): void {
        global $DB;

        $this->require_core_duedate();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_legacy_quiz($course, time() + WEEKSECS);
        $DB->set_field('quiz', 'duedate', 1900000000, ['id' => $quiz->id]);

        $this->assertSame(['', '', []], \quizaccess_duedate::get_settings_sql($quiz->id));
        $settings = \mod_quiz\quiz_settings::create($quiz->id);
        $this->assertEquals(1900000000, $settings->get_quiz()->duedate);
        $this->assertNull(\quizaccess_duedate::make($settings, time(), false));
    }

    /**
     * Rules are kept only where the site's rules would change a penalty the quiz still allows.
     */
    public function test_keep_penalty(): void {
        global $DB;

        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();

        // The production site rules: 5% per day, up to 40% at 8 days.
        $systemid = \context_system::instance()->id;
        for ($day = 1; $day <= 8; $day++) {
            $DB->insert_record('gradepenalty_duedate_rule', ['contextid' => $systemid, 'sortorder' => $day,
                'overdueby' => $day * DAYSECS, 'penalty' => $day * 5]);
        }

        $due = time() + DAYSECS;
        $legacy = function (array $quizfields, ?float $cap) use ($course, $due, $DB): \stdClass {
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id] + $quizfields);
            $DB->insert_record('quizaccess_duedate_instances', ['quizid' => $quiz->id, 'duedate' => $due,
                'penaltyenabled' => 1, 'penalty' => 5, 'penaltycapenabled' => $cap ? 1 : 0, 'penaltycap' => $cap]);
            return $quiz;
        };
        $rules = fn(\stdClass $quiz): int => $DB->count_records('gradepenalty_duedate_rule',
            ['contextid' => \context_module::instance($quiz->cmid)->id]);

        // A 7-day cut-off: at most 35% either way.
        $weekly = $legacy(['timeclose' => $due + 7 * DAYSECS], null);
        $this->assertSame([core_migration::SAME], $this->outcomes(core_migration::keep_penalty($weekly->id, false)));
        $this->assertSame(0, $rules($weekly));

        // No cut-off and a 50% cap: kept.
        $hebrew = $legacy([], 50);
        $this->assertSame([core_migration::SET], $this->outcomes(core_migration::keep_penalty($hebrew->id, false)));
        $this->assertSame(10, $rules($hebrew));
        $cm = get_fast_modinfo($course)->get_cm($hebrew->cmid);
        foreach ([3 => 15, 9 => 45, 30 => 50] as $days => $expected) {
            $this->assertEquals($expected, \gradepenalty_duedate\penalty_calculator::get_penalty_from_rules(
                $cm, $due + $days * DAYSECS, $due), "{$days} days late");
        }
        $this->assertSame([core_migration::SAME], $this->outcomes(core_migration::keep_penalty($hebrew->id, false)));

        // An override that keeps the quiz open 12 days past the due date: kept, uncapped.
        $extended = $legacy(['timeclose' => $due + 7 * DAYSECS], null);
        $student = $gen->create_and_enrol($course, 'student');
        $DB->insert_record('quiz_overrides', ['quiz' => $extended->id, 'userid' => $student->id,
            'timeclose' => $due + 12 * DAYSECS]);
        $this->assertSame([core_migration::SET], $this->outcomes(core_migration::keep_penalty($extended->id, true)));
        $this->assertSame(0, $rules($extended), 'A dry run writes nothing');

        // Closed: no one can submit any more.
        $closed = $legacy(['timeclose' => time() - DAYSECS], null);
        $this->assertSame([core_migration::SKIP], $this->outcomes(core_migration::keep_penalty($closed->id, false)));

        // Rules someone already set on the quiz are kept.
        $own = $legacy([], 50);
        $DB->insert_record('gradepenalty_duedate_rule', ['contextid' => \context_module::instance($own->cmid)->id,
            'sortorder' => 0, 'overdueby' => DAYSECS, 'penalty' => 10]);
        $this->assertSame([core_migration::CONFLICT], $this->outcomes(core_migration::keep_penalty($own->id, false)));
        $this->assertSame(1, $rules($own));
    }

    /**
     * Only quizzes with legacy data are found, in the course asked for.
     */
    public function test_get_quiz_ids(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $one = $gen->create_course();
        $two = $gen->create_course();
        $a = $this->create_legacy_quiz($one, time());
        $b = $this->create_legacy_quiz($two, time());
        $gen->create_module('quiz', ['course' => $one->id]);

        $this->assertSame([(int) $a->id, (int) $b->id], core_migration::get_quiz_ids());
        $this->assertSame([(int) $a->id], core_migration::get_quiz_ids($one->id));
    }
}
