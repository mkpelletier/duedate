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
 * Event observers for quizaccess_duedate.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\mod_quiz\event\attempt_submitted',
        'callback'    => '\quizaccess_duedate\observer::attempt_submitted',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\core\event\user_graded',
        'callback'    => '\quizaccess_duedate\observer::user_graded',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\attempt_regraded',
        'callback'    => '\quizaccess_duedate\observer::attempt_regraded',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\mod_quiz\event\question_manually_graded',
        'callback'    => '\quizaccess_duedate\observer::question_manually_graded',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\core\event\course_module_created',
        'callback'    => '\quizaccess_duedate\observer::course_module_created',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\core\event\course_module_updated',
        'callback'    => '\quizaccess_duedate\observer::course_module_updated',
        'includefile' => '/mod/quiz/accessrule/duedate/classes/observer.php',
        'internal'    => true,
        'priority'    => 9999,
    ],
];