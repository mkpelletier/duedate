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
 * Admin pages for quizaccess_duedate: only the migration to core quiz due dates.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Site administration > Plugins > Activity modules > Quiz > Due date migration.
$ADMIN->add('modsettingsquizcat', new admin_externalpage('quizaccess_duedate_migrate',
    get_string('migratepage', 'quizaccess_duedate'),
    new moodle_url('/mod/quiz/accessrule/duedate/migrate.php'),
    'moodle/site:config', $module->is_enabled() === false));

$settings = null;
