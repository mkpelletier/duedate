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
 * Language strings for quizaccess_duedate.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Due date access rule';
$string['duedatepenaltysettings'] = 'Late submission penalty settings';
$string['duedate'] = 'Due date';
$string['activitydate:due'] = 'Due:';
$string['isdue'] = 'is due';
$string['duedate_help'] = 'The soft deadline for the quiz. Submissions after this date will incur a penalty per day late if enabled.';
$string['quizduedate'] = 'Due date for quiz {$a}';
$string['enablepenalty'] = 'Enable late penalty';
$string['penaltyperday'] = 'Late penalty per day (%)';
$string['penaltyperday_help'] = 'The percentage penalty to apply per day late for submissions after the due date (0-100). The total penalty is capped at the specified maximum penalty or 100% if no cap is set.';
$string['enablepenaltycap'] = 'Enable penalty cap';
$string['penaltycap'] = 'Maximum penalty (%)';
$string['penaltycap_help'] = 'The maximum total penalty percentage that can be applied for late submissions (0-100). If not enabled, the penalty is capped at 100%.';
$string['duedateinfo'] = 'This quiz is due on {$a}.';
$string['latepenaltyinfo'] = 'Submissions after the due date will be penalized by {$a->penalty}% per day, up to a maximum of 100%.';
$string['latepenaltyinfo_withcap'] = 'Submissions after the due date will be penalized by {$a->penalty}% per day, up to a maximum of {$a->cap}%.';
$string['latepenaltyapplied'] = 'Late penalty of {$a}% applied.';
$string['duedatefor'] = 'Due: {$a}';
$string['duedateafteropen'] = 'Due date must be after open date.';
$string['duedatebeforeclose'] = 'Due date must be before close date.';
$string['invalidpenalty'] = 'Penalty must be between 0 and 100.';
$string['invalidpenaltycap'] = 'Penalty cap must be between 0 and 100.';
$string['penaltyrequiresduedate'] = 'Penalty cannot be enabled without a due date.';
$string['task_migrate_to_core'] = 'Move quiz due dates into core (Moodle 5.3)';
$string['penaltycaprequirespenalty'] = 'Penalty cap cannot be enabled without a penalty.';
// Extension management.
$string['duedateextensions'] = 'Due date extensions';
$string['duedateextension'] = 'Due date extension';
$string['extensionduedate'] = 'Extension due date';
$string['originalduedate'] = 'Original due date';
$string['adduserextension'] = 'Add user extension';
$string['addgroupextension'] = 'Add group extension';
$string['editduedateextension'] = 'Edit due date extension';
$string['deleteextension'] = 'Delete extension';
$string['userextensions'] = 'User extensions';
$string['groupextensions'] = 'Group extensions';
$string['extensiondeleteuserconfirm'] = 'Are you sure you want to delete the due date extension for user {$a}?';
$string['extensiondeletegroupconfirm'] = 'Are you sure you want to delete the due date extension for group {$a}?';
$string['extensionalreadyexists'] = 'An extension already exists for this user/group.';
$string['noextensions'] = 'No due date extensions have been set.';
$string['manageduedateextensions'] = 'Manage extensions';
$string['noduedateconfigured'] = 'No due date has been configured for this quiz. Set a due date in the quiz settings first.';
$string['nogroupsavailable'] = 'No groups available';
$string['nousersavailable'] = 'No users available';
$string['unknownuser'] = 'Unknown user';
$string['unknowngroup'] = 'Unknown group';

// Migration to core quiz due dates (Moodle 5.3).
$string['migratepage'] = 'Due date migration';
$string['migrate_intro'] = 'Moodle 5.3 has quiz due dates in core. This page moves this plugin\'s due dates and extensions into them, keeps late penalties that the site rules would change, and shows when the plugin can be uninstalled. Previews write nothing. Every action can be run again safely.';
$string['migrate_status'] = 'Status';
$string['migrate_status_incore'] = 'Core quiz due dates (Moodle 5.3)';
$string['migrate_status_done'] = 'Due dates moved into core';
$string['migrate_status_pending'] = 'Still to migrate';
$string['migrate_status_ugdone'] = 'Unified Grader penalty switches task';
$string['migrate_status_switches'] = 'Penalty switches still to save';
$string['migrate_status_ready'] = 'Safe to uninstall';
$string['migrate_status_noug'] = 'Unified Grader not installed';
$string['migrate_dates'] = '1. Due dates and extensions';
$string['migrate_dates_help'] = 'Copies each quiz\'s due date and each student or group extension into core, and switches Unified Grader\'s late penalties on for every quiz this plugin penalised. A value already set in core or Unified Grader is kept and reported as a conflict. Needs Moodle 5.3; on earlier versions the preview is a pre-upgrade inventory.';
$string['migrate_penalties'] = '2. Late penalties to keep';
$string['migrate_penalties_help'] = 'For the courses listed, writes quiz-level late penalty rules that keep a quiz\'s old rate and cap wherever the site rules would penalise a submission the quiz still allows differently. Never writes course rules, so assignments and forums are unaffected. List running courses only, not master copies.';
$string['migrate_courseids'] = 'Course IDs, separated by commas';
$string['migrate_preview'] = 'Preview';
$string['migrate_download'] = 'Download preview (CSV)';
$string['migrate_run'] = 'Migrate due dates';
$string['migrate_run_confirm'] = 'Copy all legacy quiz due dates and extensions into core now?';
$string['migrate_writerules'] = 'Write penalty rules';
$string['migrate_writerules_confirm'] = 'Write quiz-level late penalty rules for the listed courses now?';
$string['migrate_needs53'] = 'Available after the upgrade to Moodle 5.3.';
$string['migrate_needcourses'] = 'Enter at least one course ID.';
$string['migrate_results_preview'] = 'Preview: nothing was written';
$string['migrate_results_done'] = 'Done';
$string['migrate_totals'] = '{$a->quizzes} quizzes. Set {$a->set}, same {$a->same}, skip {$a->skip}, conflict {$a->conflict}, warn {$a->warn}.';
$string['migrate_outcomes'] = 'set = written (or would be); same = already in place; skip = nothing to do; conflict = a different value already exists in core and is kept; warn = done, but a teacher should check the dates.';
$string['migrate_col_course'] = 'Course';
$string['migrate_col_quiz'] = 'Quiz';
$string['migrate_col_outcome'] = 'Outcome';
$string['migrate_col_details'] = 'Details';
$string['migrate_showall'] = 'Show unchanged quizzes too';

// Capabilities.
$string['duedate:manageoverrides'] = 'Manage due date extensions';
$string['duedate:viewoverrides'] = 'View due date extensions';

// Privacy.
$string['privacy:metadata'] = 'The Due date quiz access rule plugin stores per-user due date extension data.';
$string['privacy:metadata:quizaccess_duedate_overrides'] = 'Stores per-user due date extensions for quizzes.';
$string['privacy:metadata:quizaccess_duedate_overrides:userid'] = 'The ID of the user the extension applies to.';
$string['privacy:metadata:quizaccess_duedate_overrides:duedate'] = 'The extended due date for the user.';