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

/**
 * Tests for the due-date calendar events written by override_manager.
 *
 * @package    quizaccess_duedate
 * @category   test
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quizaccess_duedate\override_manager
 */
final class override_manager_test extends \advanced_testcase {
    /**
     * Create a quiz with a class due date.
     *
     * @param \stdClass $course
     * @param int $duedate
     * @return \stdClass
     */
    private function create_quiz(\stdClass $course, int $duedate): \stdClass {
        global $DB;

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $DB->insert_record('quizaccess_duedate_instances', [
            'quizid' => $quiz->id,
            'duedate' => $duedate,
            'penaltyenabled' => 0,
            'penaltycapenabled' => 0,
        ]);
        return $quiz;
    }

    /**
     * Fetch the quiz's due-date events, keyed by who they belong to.
     *
     * @param int $quizid
     * @return array
     */
    private function due_events(int $quizid): array {
        global $DB;

        $keyed = [];
        foreach ($DB->get_records('event', ['modulename' => 'quiz', 'instance' => $quizid, 'eventtype' => 'due']) as $event) {
            // Not keyed on userid alone: core stamps the creating user on the class event.
            if ((int) $event->courseid === 0) {
                $key = 'user' . $event->userid;
            } else if ($event->groupid) {
                $key = 'group' . $event->groupid;
            } else {
                $key = 'class';
            }
            $keyed[$key] = $event;
        }
        return $keyed;
    }

    /**
     * An extension is a personal event; group dates are ranked latest first.
     */
    public function test_refresh_writes_personal_and_ranked_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $early = $gen->create_group(['courseid' => $course->id]);
        $late = $gen->create_group(['courseid' => $course->id]);

        $classdate = time() + WEEKSECS;
        $quiz = $this->create_quiz($course, $classdate);

        override_manager::save_override((object) ['quizid' => $quiz->id, 'userid' => $student->id,
            'duedate' => $classdate + (3 * DAYSECS)]);
        override_manager::save_override((object) ['quizid' => $quiz->id, 'groupid' => $early->id,
            'duedate' => $classdate + DAYSECS]);
        override_manager::save_override((object) ['quizid' => $quiz->id, 'groupid' => $late->id,
            'duedate' => $classdate + (2 * DAYSECS)]);

        override_manager::refresh_calendar_events((int) $quiz->id);
        $events = $this->due_events((int) $quiz->id);

        $this->assertCount(4, $events);

        $this->assertEquals($course->id, $events['class']->courseid);
        $this->assertNull($events['class']->priority);
        $this->assertEquals($classdate, $events['class']->timestart);

        $user = $events['user' . $student->id];
        $this->assertEquals(0, $user->courseid, 'An extension must not be a course-wide event');
        $this->assertEquals(CALENDAR_EVENT_USER_OVERRIDE_PRIORITY, $user->priority);
        $this->assertEquals($classdate + (3 * DAYSECS), $user->timestart);

        $this->assertEquals($course->id, $events['group' . $late->id]->courseid);
        $this->assertEquals(1, $events['group' . $late->id]->priority, 'The latest group date must rank first');
        $this->assertEquals(2, $events['group' . $early->id]->priority);
    }

    /**
     * A refresh replaces events written by earlier versions, and a deleted override's event goes.
     */
    public function test_refresh_replaces_stale_events(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');

        $classdate = time() + WEEKSECS;
        $quiz = $this->create_quiz($course, $classdate);
        $id = override_manager::save_override((object) ['quizid' => $quiz->id, 'userid' => $student->id,
            'duedate' => $classdate + DAYSECS]);

        // The course-wide extension event v1.9 wrote.
        $DB->insert_record('event', ['name' => 'Due', 'description' => '', 'courseid' => $course->id, 'groupid' => 0,
            'userid' => $student->id, 'modulename' => 'quiz', 'instance' => $quiz->id, 'eventtype' => 'due',
            'timestart' => $classdate + DAYSECS, 'visible' => 1]);

        override_manager::refresh_calendar_events((int) $quiz->id);
        $events = $this->due_events((int) $quiz->id);
        $this->assertCount(2, $events);
        $this->assertEquals(0, $events['user' . $student->id]->courseid);

        override_manager::delete_override($id);
        override_manager::refresh_calendar_events((int) $quiz->id);
        $this->assertSame(['class'], array_keys($this->due_events((int) $quiz->id)));
    }

    /**
     * The helpers local_unifiedgrader calls, in the order it calls them, write a personal event
     * and remove it again without touching the class event.
     */
    public function test_unifiedgrader_call_sequence(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');

        $classdate = time() + WEEKSECS;
        $quiz = $this->create_quiz($course, $classdate);
        override_manager::refresh_calendar_events((int) $quiz->id);

        // As quiz_adapter::save_duedate_extension().
        $data = (object) ['quizid' => $quiz->id, 'userid' => $student->id, 'groupid' => null,
            'duedate' => $classdate + DAYSECS];
        override_manager::save_override($data);
        override_manager::update_calendar_event($data, $quiz->name, (int) $course->id);

        $events = $this->due_events((int) $quiz->id);
        $this->assertCount(2, $events);
        $this->assertEquals(0, $events['user' . $student->id]->courseid);

        // As quiz_adapter::delete_duedate_extension(): the event goes first, then the record.
        $record = $DB->get_record('quizaccess_duedate_overrides', ['quizid' => $quiz->id, 'userid' => $student->id]);
        override_manager::delete_calendar_event($record);
        override_manager::delete_override((int) $record->id);

        $events = $this->due_events((int) $quiz->id);
        $this->assertSame(['class'], array_keys($events));
        $this->assertEquals($classdate, $events['class']->timestart);
    }
}
