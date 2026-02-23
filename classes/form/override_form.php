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
 * Form for adding/editing due date overrides.
 *
 * @package    quizaccess_duedate
 * @copyright  2025 xAI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_duedate\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class override_form extends \moodleform {

    /** @var \cm_info The course module. */
    protected $cm;

    /** @var \stdClass The quiz object. */
    protected $quiz;

    /** @var \context_module The module context. */
    protected $context;

    /** @var bool Whether this is a group override. */
    protected $groupmode;

    /** @var int The override ID (0 for new). */
    protected $overrideid;

    /** @var \stdClass|null The existing override record. */
    protected $override;

    /**
     * Constructor.
     *
     * @param \moodle_url $submiturl The form action URL.
     * @param \cm_info $cm The course module.
     * @param \stdClass $quiz The quiz object.
     * @param \context_module $context The module context.
     * @param bool $groupmode Whether this is a group override.
     * @param \stdClass|null $override The existing override record (null for new).
     */
    public function __construct(\moodle_url $submiturl, \cm_info $cm,
            \stdClass $quiz, \context_module $context,
            bool $groupmode, ?\stdClass $override) {
        $this->cm = $cm;
        $this->quiz = $quiz;
        $this->context = $context;
        $this->groupmode = $groupmode;
        $this->override = $override;
        $this->overrideid = $override->id ?? 0;

        parent::__construct($submiturl);
    }

    /**
     * Define the form fields.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('header', 'override',
            get_string('duedateextension', 'quizaccess_duedate'));

        if ($this->groupmode) {
            if (!empty($this->override->groupid)) {
                // Editing existing: show frozen group name.
                $groupchoices = [
                    $this->override->groupid => groups_get_group_name($this->override->groupid),
                ];
                $mform->addElement('select', 'groupid', get_string('group'), $groupchoices);
                $mform->freeze('groupid');
            } else {
                // New: show available groups (excluding those with existing overrides).
                $groups = groups_get_all_groups($this->cm->course);
                $groupchoices = [];
                foreach ($groups as $group) {
                    if (!\quizaccess_duedate\override_manager::group_override_exists(
                            $this->quiz->id, $group->id)) {
                        $groupchoices[$group->id] = format_string($group->name);
                    }
                }
                if (empty($groupchoices)) {
                    $groupchoices[0] = get_string('nogroupsavailable', 'quizaccess_duedate');
                }
                $mform->addElement('select', 'groupid', get_string('group'), $groupchoices);
                $mform->addRule('groupid', get_string('required'), 'required');
            }
        } else {
            if (!empty($this->override->userid)) {
                // Editing existing: show frozen user name.
                $user = $DB->get_record('user', ['id' => $this->override->userid]);
                $userchoices = [$this->override->userid => fullname($user)];
                $mform->addElement('select', 'userid', get_string('user'), $userchoices);
                $mform->freeze('userid');
            } else {
                // New: show enrolled users (excluding those with existing overrides).
                $enrolledusers = get_enrolled_users($this->context, 'mod/quiz:attempt');
                $userchoices = [];
                foreach ($enrolledusers as $user) {
                    if (!\quizaccess_duedate\override_manager::user_override_exists(
                            $this->quiz->id, $user->id)) {
                        $userchoices[$user->id] = fullname($user);
                    }
                }
                if (empty($userchoices)) {
                    $userchoices[0] = get_string('nousersavailable', 'quizaccess_duedate');
                }
                $mform->addElement('searchableselector', 'userid', get_string('user'), $userchoices);
                $mform->addRule('userid', get_string('required'), 'required');
            }
        }

        // Due date selector.
        $mform->addElement('date_time_selector', 'duedate',
            get_string('duedate', 'quizaccess_duedate'));
        $mform->addRule('duedate', get_string('required'), 'required');

        // Default to quiz-level duedate.
        $settings = $DB->get_record('quizaccess_duedate_instances', ['quizid' => $this->quiz->id]);
        if ($settings && !$this->overrideid) {
            $mform->setDefault('duedate', $settings->duedate);
        }

        // Submit buttons.
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('save'));
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonbar', '', [' '], false);
        $mform->closeHeaderBefore('buttonbar');
    }

    /**
     * Validate the form data.
     *
     * @param array $data The form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['duedate'])) {
            $errors['duedate'] = get_string('required');
        }

        // Validate no duplicate override.
        if ($this->groupmode && !empty($data['groupid'])) {
            if (\quizaccess_duedate\override_manager::group_override_exists(
                    $this->quiz->id, $data['groupid'], $this->overrideid)) {
                $errors['groupid'] = get_string('extensionalreadyexists', 'quizaccess_duedate');
            }
        } else if (!$this->groupmode && !empty($data['userid'])) {
            if (\quizaccess_duedate\override_manager::user_override_exists(
                    $this->quiz->id, $data['userid'], $this->overrideid)) {
                $errors['userid'] = get_string('extensionalreadyexists', 'quizaccess_duedate');
            }
        }

        return $errors;
    }
}
