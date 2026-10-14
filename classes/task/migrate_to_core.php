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

namespace quizaccess_duedate\task;

use quizaccess_duedate\local\core_migration;

/**
 * Moves the legacy due dates and extensions into core once the site is on Moodle 5.3.
 *
 * A scheduled task rather than only an upgrade step, because this plugin's upgrade does not run
 * again when Moodle itself is upgraded to 5.3. It does nothing before 5.3, and nothing after a
 * full migration has run once, whether from here, the upgrade or cli/migrate_to_core.php.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrate_to_core extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_migrate_to_core', 'quizaccess_duedate');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        if (!core_migration::core_has_duedate() || get_config('quizaccess_duedate', core_migration::DONE_FLAG)) {
            return;
        }

        $totals = core_migration::migrate_all(0, false, function (\stdClass $report): void {
            foreach ($report->messages as $message) {
                mtrace("  quiz {$report->quizid} [{$message->outcome}] {$message->text}");
            }
        });
        mtrace("Moved due dates into core for {$totals['quizzes']} quizzes in {$totals['courses']} courses: " .
            "{$totals['set']} written, {$totals['conflict']} conflicts, {$totals['warn']} warnings.");

        set_config(core_migration::DONE_FLAG, time(), 'quizaccess_duedate');
    }
}
