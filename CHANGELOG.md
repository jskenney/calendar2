# Changelog

## 5.0.0

The first release of Cal5, a rebuild of calendar-core (Calendar v4) that is a drop-in
replacement for it: a v4 course switches by changing where its `calendar` link points
(`docs/migration.md`), and switches back the same way.

### Compatible with v4

- Reads v4's `calendar.php` unchanged (translated on every request), and its access and
  virtual files in the same formats, with the same file keys, sessions and login (an
  instructor stays logged in across the switch, in both directions).
- Every v4 URL form, every Cal4 tag, v4's file selection, release rules and their
  precedence, calendar numbering and `[combine]` rules. The compatibility suite compares
  602 requests of six test courses with v4 and CI requires all of them to match.
- Course pages written for v4's front end: `compat.css` covers the Bootstrap 3 and Skeleton
  classes they use and draws glyphicons; jQuery, DataTables and Chart.js load on the pages
  whose scripts use them.
- Intended differences, all listed in `docs/design/compatibility.md`: the new look, `.txt`
  files shown as pages, audio and video playing in the browser, `image/jpeg`, no editor,
  newer MathJax / highlight.js / Ace, and a few v4 bugs not carried over.

### New

- **Release rules** to the minute, relative to class meetings (`start-15m`), with automatic
  re-locking (`until`, `for`), in the course's time zone (`docs/release-rules.md`).
- **Interface:** light and dark themes, phone layout, print styles, keyboard and screen
  reader support; month, list and agenda calendars with today and release badges; menus with
  dates; search and keywords.
- **For the instructor:** a status page per event (every file, whether students see it now,
  when it opens or locks, and the rule that decided), "view as a student", release badges on
  the calendar, configuration warnings, and an instructor guide.
- **`calendar.ini.php`:** a plain, commented configuration with meeting times, the start page,
  the calendar's boxes and password hashes (`bin/cal5 translate` writes it from
  `calendar.php`).
- **Security:** password hashes, login rate limiting, session fixation protection, CSRF tokens,
  constant-time checks, release rules enforced wherever a file is served, and PHP source never
  sent to students.
- **Command line** (`bin/cal5`): `check`, `events`, `access`, `audit`, `compare`, `translate`,
  `hash-password`, `init`; and `installer.sh` for new courses.
- Files are streamed with `Range` support (large files, video seeking).

### Removed

- The built-in file editor (`?edit`, `?editor`): edit course files with your usual tools.
- Bootstrap, Skeleton and jQuery from Cal5's own pages.

### Requirements

PHP 8.3 or later (`dom`, `mbstring`); the `calendar` extension is no longer needed.
