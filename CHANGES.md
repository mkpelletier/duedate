# Changelog

## v2.1 (2026-10-14) — final release
- Move the due dates and extensions into core on Moodle 5.3, which added `quiz.duedate` and
  `quiz_overrides.duedate` (MDL-82521). Each student keeps the date they had. Dates already set
  in core are kept and reported as conflicts. See MIGRATION.md.
- Runs from the upgrade (when this version is installed on 5.3), from the `migrate_to_core`
  scheduled task (once, after the site is upgraded to 5.3), from restoring a pre-5.3 backup, and
  on demand with `cli/migrate_to_core.php`, which also does dry runs, a penalty report and an
  uninstall readiness check.
- Step aside on Moodle 5.3: the rule adds, loads and saves no settings, since its `duedate` form
  field and settings column collide with core's, writes no calendar events, applies no late
  penalties (local_unifiedgrader owns them), and its extension pages send people to core's.
  Nothing changes on Moodle 5.0.
- Add the **Due date migration** admin page (Site administration > Plugins > Activity modules >
  Quiz), so the migration needs no terminal access: status, preview, CSV download, migrate,
  and keep late penalties for named courses.
- The migration (and every restore of a pre-5.3 backup on 5.3) switches Unified Grader's "Apply
  late penalties" on for each quiz this plugin penalised, so penalties carry on after the
  plugin is uninstalled. A switch already saved is left as it is.
- `cli/migrate_to_core.php --keep-penalties --courseid=…` writes quiz-level gradepenalty_duedate
  rules keeping a quiz's legacy rate and cap where the inherited rules would penalise a still-possible
  late submission differently. Course-level rules are never written.
- `deploy.sh` targets the ServBay sites (5.0.7 and 5.3) instead of MAMP.

## v2.0 (2026-09-23)
- Fix a student's due date extension showing as the due date for the whole class, in the
  Moodle calendar and in the Course Schedule. Extensions were written as course-wide
  calendar events; they are now written as personal events, and group dates carry a
  priority, the way core quiz writes its own overrides. The upgrade rewrites existing events.
- Rebuild a quiz's due-date calendar events after the quiz settings are saved, after an
  extension is added, edited or deleted, and after a restore. Core rewrites or deletes all of
  a quiz's calendar events when the quiz is saved, which could remove them.
- Add `override_manager::refresh_calendar_events()`, which rebuilds all of a quiz's due-date
  events. `update_calendar_event()` now calls it. `delete_calendar_event()` still removes one
  override's event, and now also matches an extension written the old, course-wide way. Both
  are kept because local_unifiedgrader calls them.

## v1.9 (2026-04-13)
- Move due date and penalty info into the activity-dates region alongside "Opens" and "Closes"
- Add AMD module (duedate_display) for client-side injection into activity-dates area
- Remove broken renderer that extended non-existent mod_quiz_renderer class
- Remove unused lib.php callback (quizaccess_duedate_get_extra_coursemodule_info)

## v1.8 (2026-02-26)
- Fix gradebook override locking and add regrade penalty support

## v1.7
- Add due date extension system with grade recalculation

## v1.6
- Fix late penalty calculation and add backup/restore support

## v1.0
- Initial commit
