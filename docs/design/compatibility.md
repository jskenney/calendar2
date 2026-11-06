# Drop-in compatibility contract

A v4 course (the `calendar` shell plus `calendar-core`) must work with Cal5 by changing **only**
where its `calendar` link points. This document lists everything a course relies on and what
Cal5 does with it. It is the checklist for the compatibility suite (Milestones 1-10).

Baseline: calendar-core `master` at version 4.6 (security fixes and Markdown support
included). Behaviour that calendar-core deliberately changed in 4.5 (allow-lists, unmapped
events hidden from students, and so on) is kept as is.

## How a course switches

```bash
cd SI460
mv calendar-core calendar-core-v4            # keep v4 for rollback
git clone https://github.com/jskenney/calendar2.git calendar-core
```

The `calendar -> calendar-core/` symlink and `calendar.php` stay untouched. Rolling back is
the reverse rename. Courses that use the submodule change its URL instead. The Milestone 10
migration guide covers both.

## Files and entry points

| v4 | Cal5 |
|---|---|
| `calendar.php` sets globals, then `require_once('calendar/calendar_main.php')` | **Kept.** `calendar_main.php` is a shim that captures the globals, translates them (configuration.md) and runs Cal5. |
| `index.php -> calendar.php` symlink | Kept (nothing to do). |
| `virtual/cal4.access`, `virtual/cal4.virtual` | Read unchanged (access-rules.md). |
| `home.html`, `policy.html`, `resources.html` and other root pages | Served unchanged with `?load=`. |
| `calendar/...` asset URLs (`calendar/images/web-icon.png`, `calendar/css/...`) hard-coded in course pages | Cal5 keeps `images/web-icon.png`. The milestone 8 audit tool reports any other `calendar/...` paths a course uses, so they can be kept or redirected. |
| `.htaccess` files in component directories | Unchanged (Apache). Cal5's docs add nginx examples. |
| `clean-permissions.sh` | Unchanged. |

## URLs and parameters

Every v4 link must keep working: links in course pages, bookmarks, LMS links, and links
students have saved.

| Parameter | v4 meaning | Cal5 |
|---|---|---|
| `type`, `event` | Show an event's title file | Same validation as calendar-core 4.5 (positive integer, configured type) |
| `key` | Serve a file of the event (with `type`/`event`) | Same keys: `sha1(secret . type . dir . file)` |
| `load` | Root page `.html`/`.pdf` (and `.md`), allow-listed | Same |
| `show` | `calendar_display`, `calendar_display_alt`, `calendar_display_dateless`, `calendar_search`, `calendar_keyword`, `calendar_file_selector` | Same names, mapped to Cal5 views. `calendar_file_editor` shows a "the editor has been removed" note |
| `search`, `keyword` | Search views | Same |
| `answers` | Reveal `<student>` content | Same |
| `print` | Print layout | Same (print stylesheet) |
| `nocss` | Page without navbar/CSS (`.htm` files set it automatically) | Same |
| `navbaronly` | Instructor file list | Same (status page) |
| `password` | Login (plaintext or `sha256(password+nonce)`) | Same, plus hashed passwords (Milestone 5) |
| `lock` | Code for `<lock code="">` sections | Same |
| `debug` | Debug dump (needs `$DEBUG_MODE`) | Same gate |
| `edit`, `editor`, `preview`, `csrf` | Built-in editor | **Removed.** `edit` is ignored; the page shows normally |

Cookies and sessions: the cookie names (`cal4-<COURSE>`, `cal4-<COURSE>-nonce`) and the
session keys stay the same, so an instructor's login survives the switch.

## Content tags

All tags behave as in v4. Output is escaped exactly as calendar-core 4.5 does it.

| Tag | Behaviour kept |
|---|---|
| `<inst>…</inst>` | Instructor only |
| `<student>…</student>` | Hidden until `?answers` (not a security control) |
| `<inject src="">`, `<codeinject src="">` | Insert another file of the event (`.md` is rendered); `codeinject` escapes it |
| `<replace value="">` | Values from `[replace]` / `$PAGE_MODIFY` plus the built-in values |
| `<showlink src="">` | Keyed URL of a file |
| `<url value="" default="">` | Request value, remembered in the session |
| `<postmeeting>…</postmeeting>` | After the meeting (access-rules.md) |
| `<host server="">…</host>` | Only on the named servers |
| `<lock code="">…</lock>` | Password-protected section |
| `<ace …>…</ace>` | Ace editor box (Ace loaded only on pages that use it; the jQuery dependency is removed) |
| `<calphp src="">` | Runs a PHP file of the event (instructor-authored content, as in v4) |
| `<a href="file">`, `<img src="file">`, `<source src="file">` | Rewritten to keyed URLs |
| Heading `id`s / `<a name>` | Section menu in the navbar |

## Look and front-end libraries

Cal5 drops Bootstrap 3, Skeleton and jQuery from its own pages. Course pages may still rely
on them, for example `<table class="table">`, `<div class="row"><div class="six columns">`,
glyphicons, or inline `$(...)` scripts. The plan for those pages:

- **`compat.css`** (Milestone 8) covers the Bootstrap/Skeleton classes course content
  commonly uses. It is on automatically for v4 configs (`features.legacy_css = auto`).
- **`features.legacy_js`** loads jQuery / DataTables / Chart.js for courses whose pages need
  them. It is off by default.
- **`bin/cal5 audit`** reports exactly which of these a course uses, before it switches.

## Intentional differences

| Area | Difference | Reason |
|---|---|---|
| Built-in editor | Removed | Not used; it was also the largest security risk (calendar-core C-4) |
| Layout and styles | New theme | A goal of the rebuild |
| Bootstrap / jQuery | Not loaded by default | A goal of the rebuild; see compat options above |
| Year-crossing semesters | Supported | v4 could not show them |
| Release times | Minute precision and ranges available | New feature; v4 rules unchanged |
| Downloads | Streamed with `Range` support | Large files and video seeking work |

Any other difference found by the compatibility suite or by `bin/cal5 compare` is treated as a
bug, unless it is added to this table and agreed in a PR.

## Decisions pending

| Found in | v4 behaviour | Decide in |
|---|---|---|
| Milestone 1 recording (kitchen-sink) | A `.php` file in an event directory (for example a `<calphp>` script) can be downloaded **as source** by anyone who has its key: `provide_file()` sends it as `application/octet-stream`. That can expose code or credentials in instructor scripts. | Milestone 6: probably refuse to serve `.php` source to students, and add the result to the intentional differences |
