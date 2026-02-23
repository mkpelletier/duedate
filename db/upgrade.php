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
 * Upgrade script for the quizaccess_duedate plugin.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_quizaccess_duedate_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025091102) {
        // Define table quizaccess_duedate_instances.
        $table = new xmldb_table('quizaccess_duedate_instances');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('penaltyenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('penalty', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('quizid', XMLDB_INDEX_UNIQUE, ['quizid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table quizaccess_duedate_penalties.
        $table = new xmldb_table('quizaccess_duedate_penalties');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('penaltyapplied', XMLDB_TYPE_NUMBER, '12, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('attemptid', XMLDB_INDEX_UNIQUE, ['attemptid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025091102, 'quizaccess', 'duedate');
    }

    if ($oldversion < 2025092001) {
        // Add penaltycapenabled and penaltycap fields to quizaccess_duedate_instances.
        $table = new xmldb_table('quizaccess_duedate_instances');

        $field = new xmldb_field('penaltycapenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'penalty');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('penaltycap', XMLDB_TYPE_NUMBER, '12, 2', null, null, null, null, 'penaltycapenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025092001, 'quizaccess', 'duedate');
    }

    if ($oldversion < 2026020901) {
        // Add quizid field and index to quizaccess_duedate_penalties if missing.
        $table = new xmldb_table('quizaccess_duedate_penalties');

        $field = new xmldb_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('quizid', XMLDB_INDEX_NOTUNIQUE, ['quizid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026020901, 'quizaccess', 'duedate');
    }

    if ($oldversion < 2026020902) {
        // Drop the unused quizaccess_duedate_overrides table.
        $table = new xmldb_table('quizaccess_duedate_overrides');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_plugin_savepoint(true, 2026020902, 'quizaccess', 'duedate');
    }

    if ($oldversion < 2026021902) {
        // Create the quizaccess_duedate_overrides table for per-user and per-group due date overrides.
        $table = new xmldb_table('quizaccess_duedate_overrides');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);

        $table->add_index('quizid_userid', XMLDB_INDEX_UNIQUE, ['quizid', 'userid']);
        $table->add_index('quizid_groupid', XMLDB_INDEX_UNIQUE, ['quizid', 'groupid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026021902, 'quizaccess', 'duedate');
    }

    return true;
}