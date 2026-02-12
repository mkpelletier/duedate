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
$string['penaltycaprequirespenalty'] = 'Penalty cap cannot be enabled without a penalty.';
$string['privacy:metadata'] = 'The Due date quiz access rule plugin does not store any personal data.';