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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;

/**
 * Steps for quizaccess_duedate.
 *
 * @package    quizaccess_duedate
 * @category   test
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_quizaccess_duedate extends behat_base {
    /**
     * Store legacy due dates and penalties, as the plugin did before Moodle 5.3.
     *
     * Columns: quiz (name), duedate (strtotime text, e.g. "+2 days"), penalty, cap (empty for none).
     *
     * @Given /^the following quizaccess_duedate legacy settings exist:$/
     * @param TableNode $data
     */
    public function the_following_legacy_settings_exist(TableNode $data): void {
        global $DB;

        foreach ($data->getHash() as $row) {
            $quizid = $DB->get_field('quiz', 'id', ['name' => $row['quiz']], MUST_EXIST);
            $cap = trim($row['cap'] ?? '');
            $DB->insert_record('quizaccess_duedate_instances', [
                'quizid' => $quizid,
                'duedate' => strtotime($row['duedate']),
                'penaltyenabled' => 1,
                'penalty' => (float) $row['penalty'],
                'penaltycapenabled' => $cap === '' ? 0 : 1,
                'penaltycap' => $cap === '' ? null : (float) $cap,
            ]);
        }
    }

    /**
     * Skip the scenario on sites without core quiz due dates (before Moodle 5.3).
     *
     * @Given /^the site has core quiz due dates$/
     */
    public function the_site_has_core_quiz_due_dates(): void {
        if (!\quizaccess_duedate\local\core_migration::core_has_duedate()) {
            throw new \Moodle\BehatExtension\Exception\SkippedException('Needs Moodle 5.3 or later.');
        }
    }

    /**
     * Type a course's ID, found by its short name, into a field.
     *
     * @When /^I set the field "(?P<field>(?:[^"]|\\")*)" to the id of course "(?P<shortname>(?:[^"]|\\")*)"$/
     * @param string $field
     * @param string $shortname
     */
    public function i_set_the_field_to_the_id_of_course(string $field, string $shortname): void {
        global $DB;
        $id = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $this->execute('behat_forms::i_set_the_field_to', [$field, $id]);
    }
}
