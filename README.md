# quiz_accessrule_duedate
add due dates to moodle quizzes with penalties

**Deprecated.** Moodle 5.3 has quiz due dates in core. Version 2.1 moves this plugin's due dates
and extensions into core and then does nothing on 5.3; late penalties move to local_unifiedgrader.
Uninstall it once `php mod/quiz/accessrule/duedate/cli/migrate_to_core.php --status` says it is
safe. See [MIGRATION.md](MIGRATION.md).
