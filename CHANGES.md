# Changelog

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
