# Migrating quizaccess_duedate to core quiz due dates (Moodle 5.3)

Moodle 5.3 gives the quiz its own due date (`quiz.duedate`) and per-student and per-group due
dates (`quiz_overrides.duedate`), MDL-82521. Version 2.1 of this plugin is its final release:
it moves its data into those fields, steps aside on 5.3, and is then uninstalled.

Everything below is done from **Site administration > Plugins > Activity modules > Quiz > Due
date migration**. The only thing the hosting provider runs is the Moodle upgrade itself.
(`cli/migrate_to_core.php` does the same from a terminal, for whoever has one.)

## What moves where

| quizaccess_duedate                              | Moodle 5.3                                         | Moved by       |
|-------------------------------------------------|----------------------------------------------------|----------------|
| `quizaccess_duedate_instances.duedate`          | `quiz.duedate`                                     | this plugin, step 1 |
| `quizaccess_duedate_overrides` (user)           | `quiz_overrides.duedate`, the student's row        | this plugin, step 1 |
| `quizaccess_duedate_overrides` (group)          | `quiz_overrides.duedate`, the group's row          | this plugin, step 1 |
| Due date calendar events                        | Rebuilt by core (`quiz_update_events()`)           | this plugin, step 1 |
| `penaltyenabled` (on/off per quiz)              | Unified Grader's "Apply late penalties" switch (`local_unifiedgrader_penset`) | this plugin, step 1 |
| `penalty` (% per day), `penaltycap`             | Site penalty rules; quiz-level rules where they differ | this plugin, step 2 |
| `quizaccess_duedate_penalties`                  | Not moved (nothing but backup/restore writes it; empty on 5.0.7) | — |
| Penalised grades already in the gradebook       | Stay as they are (Unified Grader leaves them pinned) | —            |

Core resolves a student's due date the way the plugin did — the student's own date, then the
latest of their groups' dates, then the quiz's date — so every student keeps the date they had.
When a student or group already has a core override (for a time limit, say), the due date is
added to it; otherwise a row is created with only the due date set. The legacy tables are
copied from, never changed.

**Late penalties are Unified Grader's on 5.3**, for assignments, forums and quizzes alike; core's
own penalty feature only covers assignments and is not used. Unified Grader takes two things
per activity from two places:

- **whether** to penalise — its own "Apply late penalties" switch. Until a quiz has one saved,
  Unified Grader reads this plugin's table, so after the uninstall it would default to off.
  Step 1 saves the switch, on, for every quiz this plugin penalised (and leaves one a teacher
  turned off as it is);
- **how much** — the tiers on core's Grade penalties pages (site, course or activity), which
  Unified Grader reads for every activity type. Step 2 adds quiz-level tiers where the site's
  would differ.

## What exists where

- **Quiz due dates** (step 1) are new in **5.3**. On 5.0.7 there is nothing to move them into:
  the page shows a preview (a pre-upgrade inventory) and no migrate button.
- **Late penalty rules** (step 2) are core's `gradepenalty_duedate` rules, a **5.0** feature,
  already in use on production (the site rules: 5% per day up to 40%). On 5.0.7, quizzes ignore
  them; Unified Grader starts applying them to quizzes on 5.3. Step 2 is still best run in the
  upgrade window, since it only looks at quizzes that can be submitted on the day it runs.

## The plugin on each version

- **Moodle 5.0.7:** unchanged from v2.0 — due dates, extensions, calendar and penalties work as
  before.
- **Moodle 5.3:** the plugin steps aside. It adds nothing to the quiz form, loads nothing (its
  `duedate` column would hide core's), saves nothing, writes no calendar events, applies no
  penalties, and its extension pages redirect to core's overrides page.

**Between the upgrade and step 1**, core's due date is empty (0) on every quiz while the dates
still sit in the plugin's tables. Students see no due date, Unified Grader treats quizzes as
never late, and a teacher who saves a quiz's settings rebuilds its calendar without a due date.
Keep that gap inside the maintenance window. If it is missed, the `migrate_to_core` scheduled
task runs step 1 on the first cron run (it runs every 15 minutes, once); a date a teacher set in
core in the meantime is kept and reported as a conflict.

## Outcomes on the page

| Outcome    | Meaning                                                                        |
|------------|--------------------------------------------------------------------------------|
| `set`      | Written (or would be, in a preview).                                           |
| `same`     | Already in place.                                                              |
| `skip`     | Nothing to do: no legacy due date, deleted user or group, quiz closed, missing quiz. |
| `conflict` | A different value already exists in core, and is kept.                         |
| `warn`     | Done, but a teacher should look: due date not after the open date or after the close date (core will refuse to save the quiz settings until it is changed), or an extension past the close date (the student cannot use it). |

Every action can be run again: what is already in place comes back as `same`. Previews hide
quizzes where nothing changes; "Show unchanged quizzes too" lists them all, and the CSV download
has every quiz.

## Production runbook

### Prerequisites

- **v2.1 must be installed before the upgrade.** On 5.3 an older version hides core's due date
  and puts a second "Due date" field on the quiz form.
- **The plugin must be in the 5.3 code** at `public/mod/quiz/accessrule/duedate` (5.3 serves
  plugins from `public/`). If it is missing, Moodle reports it as missing from disk and nothing
  can migrate its data. Tell the host.
- Use **web maintenance mode** (Site administration > Server > Maintenance mode), not the CLI
  one: admins can still use the site under web maintenance mode, which the steps need. Ask the
  host to keep **cron off** until step 2 is done.

### Before the upgrade (on 5.0.7)

1. Install v2.1 (Site administration > Plugins > Install plugins, or via the host). Nothing
   visible changes.
2. On the migration page, **Download preview (CSV)** and keep it as the baseline. Fix anything
   reported as `warn` that matters.
3. Re-run the late-window query (below) to list the running courses that need step 2.
4. Rehearse on a copy of production upgraded to 5.3, if the host can provide one.

### Upgrade window

1. Web maintenance mode on; the host stops cron, upgrades to 5.3 (with the plugin in
   `public/mod/quiz/accessrule/duedate`), and tells you when it is done.
2. Migration page, section 1: **Preview** — expect the baseline's quizzes as `set`. Then
   **Migrate due dates**.
3. Section 2: enter the running courses (production check of 14 Oct 2026: `1028,856`),
   **Preview**, then **Write penalty rules**.
4. Status should read "Still to migrate: 0", "Penalty switches still to save: 0" and "Safe to
   uninstall: Yes".
5. Spot-check a quiz with an extension: the quiz page shows "Due:" once (from core), Quiz >
   Overrides lists the extension, the student's calendar shows their own date, and the quiz's
   settings show Unified Grader's "Apply late penalties" ticked.
6. The host starts cron; maintenance mode off.

### Deprecation and removal

1. Leave v2.1 installed for two terms, so restores of pre-5.3 course backups are still migrated
   (after uninstalling, such a backup restores with no quiz due dates).
2. Before uninstalling, open the migration page: the status counts dates and penalty switches
   still missing, including quizzes restored since the window. Run section 1 again if needed
   (restores on 5.3 are migrated as they happen, so this should find nothing).
3. Uninstall from Site administration > Plugins > Plugins overview. This drops the three
   `quizaccess_duedate_*` tables; nothing in core depends on them.

## Rollback

Step 1 only adds to core: it sets `quiz.duedate` where it was 0, sets `quiz_overrides.duedate`
where it was null, inserts due-date-only overrides, and rebuilds the quiz calendar events. Step 2
only adds quiz-level penalty rules. Restoring the pre-upgrade database backup undoes both with
everything else.

## Penalty rates

Unified Grader computes late penalties from core's gradepenalty_duedate rules, resolved
activity → course → site. Those are tiers ("late by up to 1 day: 5%"), and the last tier applies
to anything later. The site rules are the policy: 5% per day, the last tier at 8 days (40%).

The legacy rate is also 5% per day everywhere, so the two only differ for work more than 8 days
late, and only on quizzes whose legacy cap is above 40% (or uncapped). Most quizzes close 7 days
after the due date, which keeps them within 35% under either rule; they need nothing.

Step 2 writes quiz-level rules (never course-level, so assignments and forums keep the site
rules) reproducing a quiz's legacy rate and cap, but only when all of these hold:

- the inherited rules would give a different penalty for some lateness the quiz still allows,
  counting its close date, grace period and override close dates (no close date = unlimited);
- someone can still submit it (its last close date has not passed);
- it has no rules of its own yet (existing ones are kept and reported as a conflict).

Courses are named explicitly so `[Subject]` master courses are never touched. The production
check of 14 Oct 2026 found two running courses that need it, both with quizzes that have no
cut-off: BHB2120 2026 Semester 2 (id 1028, 16 quizzes: 15 capped at 50%, one uncapped) and
BHB3121 2026 Semester 2 (id 856, 2 uncapped). Closed courses keep the penalties already in their
gradebooks. Rules are whole percents (core stores no decimals). `tests/core_migration_test.php`
checks the tiers against core's own calculator.

### Late-window query (Ad-hoc database queries)

Lists the quizzes whose penalty would change: legacy cap above 40% or none, and a student can
still submit more than 8 days late. Courses that are still running and are not `[Subject]` masters
go into step 2.

```sql
SELECT w.course,
       CONCAT('%%WWWROOT%%/course/view.php%%Q%%id=', w.courseid) AS course_link_url,
       w.quiz,
       CONCAT('%%WWWROOT%%/mod/quiz/view.php%%Q%%id=', w.cmid) AS quiz_link_url,
       w.course_enddate,
       w.quiz_duedate,
       w.quiz_closedate,
       w.latest_override_closedate,
       w.open_ended_overrides,
       w.grace_minutes,
       CASE WHEN w.maxlate IS NULL THEN 'no limit'
            ELSE ROUND(w.maxlate / 86400, 1) END AS max_days_late,
       w.legacy_cap,
       CASE WHEN w.maxlate IS NULL THEN w.capvalue
            ELSE LEAST(CEIL(w.maxlate / 86400) * w.rate, w.capvalue) END AS worst_legacy_penalty,
       CASE WHEN w.maxlate IS NULL THEN 40
            ELSE LEAST(CEIL(w.maxlate / 86400) * 5, 40) END AS worst_site_penalty
  FROM (
        SELECT c.fullname AS course, c.id AS courseid, c.enddate AS course_enddate,
               q.name AS quiz, cm.id AS cmid,
               i.duedate AS quiz_duedate,
               NULLIF(q.timeclose, 0) AS quiz_closedate,
               ov.latest_close AS latest_override_closedate,
               COALESCE(ov.open_ended, 0) AS open_ended_overrides,
               CASE WHEN q.overduehandling = 'graceperiod' THEN ROUND(q.graceperiod / 60) ELSE 0 END AS grace_minutes,
               i.penalty AS rate,
               CASE WHEN i.penaltycapenabled = 1 AND i.penaltycap > 0 THEN i.penaltycap ELSE 100 END AS capvalue,
               CASE WHEN i.penaltycapenabled = 1 AND i.penaltycap > 0
                    THEN CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.penaltycap)), '%')
                    ELSE 'none' END AS legacy_cap,
               CASE WHEN q.timeclose = 0 OR COALESCE(ov.open_ended, 0) > 0 THEN NULL
                    ELSE GREATEST(q.timeclose + CASE WHEN q.overduehandling = 'graceperiod' THEN q.graceperiod ELSE 0 END,
                                  COALESCE(ov.latest_close, 0)) - i.duedate END AS maxlate
          FROM {quizaccess_duedate_instances} i
          JOIN {quiz} q ON q.id = i.quizid
          JOIN {course} c ON c.id = q.course
          JOIN {modules} m ON m.name = 'quiz'
          JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
          LEFT JOIN (
                SELECT o.quiz,
                       MAX(o.timeclose) AS latest_close,
                       SUM(CASE WHEN o.timeclose = 0 THEN 1 ELSE 0 END) AS open_ended
                  FROM {quiz_overrides} o
                 WHERE o.timeclose IS NOT NULL
                 GROUP BY o.quiz
               ) ov ON ov.quiz = q.id
         WHERE i.penaltyenabled = 1 AND i.duedate > 0
           AND NOT (i.penaltycapenabled = 1 AND i.penaltycap > 0 AND i.penaltycap <= 40)
       ) w
 WHERE w.maxlate IS NULL OR w.maxlate > 8 * 86400
 ORDER BY w.course_enddate DESC, w.course, w.quiz_duedate
```

## Known issues

- **Old backups after uninstall** lose their quiz due dates when restored on 5.3; hence the
  two-term grace period.
- **Unified Grader's own penalty task runs once**, so it misses quizzes restored after it ran.
  It no longer matters: step 1 and every restore save the switch themselves.
- **Close-only extensions.** Many Term 1–3 quizzes had student overrides that moved only the
  close date, so those students were still late from the original due date. Under the site
  rules that tops out at 40%. To give a real extension, move the due date too.
