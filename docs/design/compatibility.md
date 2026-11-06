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
the reverse rename. Courses that use the submodule change its URL instead.
[docs/migration.md](../migration.md) covers both, and how to check a course first
(`bin/cal5 audit`, `bin/cal5 compare`).

**Reload PHP after switching** (`systemctl reload php8.3-fpm`, or reload Apache with
mod_php). PHP's OPcache reuses the resolved path of `calendar/calendar_main.php`
(`opcache.revalidate_path` is off by default), and the realpath cache keeps it for up to two
minutes, so until the reload the old code can keep answering. Logins are not affected:
instructors stay logged in across the switch in both directions (tested in
`tests/http/AuthFlowTest.php`).

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
| `debug` | Debug dump (needs `$DEBUG_MODE`) | Same gate; a readable summary of what Cal5 decided (no passwords, secrets or session values) |
| `edit`, `editor`, `preview` | Built-in editor | **Removed.** `edit` is ignored; the page shows normally |
| `as=student` | – | **New.** The instructor sees the page as a student would now (a banner leads back) |

The routing rules, in v4's order, are in `docs/design/routing.md`. Since milestone 9 the
compatibility suite compares every request of every fixture (602 requests) and CI requires
all of them to match.

Cookies and sessions: the cookie names (`cal4-<COURSE>`, `cal4-<COURSE>-nonce`), the session
keys and, for plain-text passwords, v4's token (`sha1(secret . password . course . nonce . session id)`)
stay the same, so an instructor's login survives the switch in both directions. The login
accepts what v4's does: the plain password, or `sha256(password . nonce)` (v4's login form,
with the nonce of the cookie or the session); any other `password` while logged in logs out.
Added in Cal5:
- a new session id at login, and session strict mode (no session fixation);
- constant-time comparisons;
- a server-side expiry after `session_hours`;
- rate limiting: 10 failed logins from one address within 15 minutes block logins from it for
  15 minutes.

Courses that store a `password_hash` log in by posting the password itself (v4's
challenge-response needs the plain password on the server), and use an HMAC token tied to the
hash, so changing the password logs everyone out.

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
glyphicons, or inline `$(...)` scripts. Milestone 8 keeps those pages working without
bringing the old stack back (`docs/design/legacy-content.md`):

- **`compat.css`** re-implements the Bootstrap 3 and Skeleton classes course content commonly
  uses, and draws glyphicons with Cal5's icons (`features.legacy_css`).
- **`features.legacy_js`** loads jQuery, DataTables and Chart.js. `auto` loads each one only
  on the pages whose scripts use it; listed libraries load on every page.
- **v4 courses (translated configs)** get `legacy_css = yes` and `legacy_js = auto`, so their
  pages keep working unchanged, and pages that do not use the libraries do not load them.
  New `calendar.ini.php` courses get neither unless they ask. The Cal5 theme itself never
  depends on them.
- **`bin/cal5 audit`** reports exactly which of these a course uses, and what Cal5 does not
  provide (Bootstrap's JavaScript, Font Awesome, links to v4's own files), before it
  switches.

## Intentional differences

| Area | Difference | Reason |
|---|---|---|
| Built-in editor | Removed | Not used; it was also the largest security risk (calendar-core C-4) |
| Layout and styles | New theme | A goal of the rebuild |
| Bootstrap / jQuery | Not used by the Cal5 theme; loaded only through the compatibility options (on for translated v4 configs) | A goal of the rebuild; see compat options above |
| Year-crossing semesters | Supported | v4 could not show them |
| Release times | Minute precision and ranges available | New feature; v4 rules unchanged |
| Downloads | Streamed with `Range` support | Large files and video seeking work |
| `.txt` files | Shown as a page (escaped text in the layout); v4 sent `text/plain` | Readable, with the navbar; the text is the same |
| Audio and video | Real content types (`video/mp4`, `audio/mpeg`, ...), shown inline; v4 sent an `application/octet-stream` attachment | They play in the browser |
| `.jpg`/`.jpeg` | `image/jpeg`; v4 sent `image/jpg` | The registered type |
| Error page | The configuration's problem itself is not shown on the page (v4 printed it); the instructor runs `bin/cal5 check` | Students do not see configuration details |
| `?load=instructor` | Cal5's own short guide (v4: its instructor.html) | It describes Cal5 |
| Instructor's month view | Day numbers link only days that have an event; v4 also linked days without material (`?navbaronly=1&type=holiday&event=`, a dead link) | No broken links |
| jQuery, DataTables, Chart.js | Loaded on the pages that use them (`legacy_js = auto`), not on every page; jQuery 3.7.1 (v4: 3.6.3), Chart.js 2.9.4 (v4: 2.9.3), DataTables as in v4 | Smaller pages; same major versions, so course scripts keep working |
| Bootstrap and Skeleton | Not loaded; `compat.css` re-implements the classes course pages use, glyphicons are drawn with Cal5's icons | A goal of the rebuild; `bin/cal5 audit` reports anything not covered |
| Bootstrap's JavaScript, Font Awesome | Not provided (content that Bootstrap's `collapse` hid is shown) | `bin/cal5 audit` lists the pages that use them |
| Browser libraries | MathJax 4.1.3 (v4: 3.2.2), highlight.js 11.12.0 (v4: 11.7.0), Ace 1.45.2 (v4: 1.4.7) | Maintained versions. Math uses MathJax 4's New Computer Modern font, so it looks slightly different; the same TeX extensions are loaded and `\( \)` stays the inline delimiter. `<ace>` boxes have no lint markers (Ace's workers are not shipped) |

Any other difference found by the compatibility suite or by `bin/cal5 compare` is treated as a
bug, unless it is added to this table and agreed in a PR. The suite applies exactly these rules
(`src/Compare/IntendedDifferences.php`).

## Decisions made

| Found in | Decision |
|---|---|
| Milestone 4 (access rules) | v4 checked a virtual file's rules under its virtual name for the calendar, but under its real path when serving it. Cal5 requires both to allow the file. This only matters when a rule names a virtual file, and Cal5 is then the stricter of the two. |
| Milestone 1 recording (kitchen-sink) | v4 let students download the **source** of `.php` files in event directories (for example `<calphp>` scripts). Fixed in calendar-core 4.6.20261106 (jskenney/calendar-core#20): students get nothing, `<calphp>` still runs the script, and the instructor can still download it. Cal5 follows the fixed behaviour, and the recorded v4 results use that commit. |
