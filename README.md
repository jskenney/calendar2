# Calendar 2 (Cal5)

A modern rebuild of [calendar-core](https://github.com/jskenney/calendar-core): a PHP
system that builds a course website and calendar from directories of class material
(`class/`, `lab/`, `exam/`, ...).

Cal5 is designed as a **drop-in replacement** for Calendar v4: existing courses keep
their `calendar.php`, access files and content, and work unchanged.

This repository is being built one milestone at a time. See `MILESTONES.md` (on the
`milestones` branch until it is merged) for the plan.

## Status

Milestone 8 (legacy content): Cal5 reads the configuration (`calendar.ini.php`, or a translated
v4 `calendar.php`), finds every event and file, builds the calendar, decides what students
may see exactly as v4 does, logs the instructor in with v4's password and login flow, and
serves event pages and their files (`.html`, `.md` and `.txt` pages with every Cal4 tag,
MathJax and highlight.js; `.pdf`, `.ppt`, `.pptx` and other files as downloads). It has a
new look: light and dark themes, a phone layout, menus that work without JavaScript, and
**month, list and agenda calendars** that show the same events and links as v4, with today
marked and release times for the instructor (`docs/design/ui.md`). Course pages written for
v4's Bootstrap, Skeleton, glyphicons, jQuery, DataTables or Chart.js keep working
(`docs/design/legacy-content.md`), and `bin/cal5 audit` reports what a course uses. Every event page, file
and calendar view of the test courses matches v4. It adds release times to the minute,
re-lock ranges, and meeting-relative rules (`docs/design/access-rules.md`). The home page,
search and the instructor's status page arrive in milestone 9; until then those URLs show a
summary page.
For a course, run these from its directory:

```bash
php calendar/bin/cal5 check        # validate and summarise the configuration
php calendar/bin/cal5 events       # every event: date, directory, and the file in each box
php calendar/bin/cal5 access       # what students can see now (or --at "2026-09-14 13:30")
php calendar/bin/cal5 translate    # write calendar.ini.php from a v4 calendar.php
php calendar/bin/cal5 audit        # what the course's pages use from v4's front end
php calendar/bin/cal5 hash-password
```

## Development

```bash
php tests/run.php                                        # tests (PHP 8.3, no dependencies)
tests/compat/fetch-v4.sh                                 # fetch v4 for the compatibility runner
php tests/compat/compat.php check --impl=cal5 --scope=events     # event pages and files: must match v4
php tests/compat/compat.php check --impl=cal5 --scope=calendars  # calendar views: must match v4
node tests/browser/check.js [--screenshots=DIR]          # pages in Chromium (Playwright): no errors, no Bootstrap/jQuery
php tests/compat/compat.php check --impl=cal5 --report   # Cal5 progress against v4 (all pages)
```

See `CONTRIBUTING.md` for the workflow and coding standards.
