# Cal5 Milestones

**Status: all ten milestones are done; Cal5 5.0.0 is released (`CHANGELOG.md`).**

Cal5 (this repository, `calendar2`) is a rebuild of Calendar v4 (`calendar` + `calendar-core`).
The work is split into milestones. Each milestone gets its own branch (`milestone-NN-short-name`)
and its own pull request, and is reviewed and merged before the next one starts.

The design behind the plan is in `docs/design/`:

| Document | Contents |
|---|---|
| [architecture.md](docs/design/architecture.md) | Directory layout, request flow, modules, assets |
| [configuration.md](docs/design/configuration.md) | The new `calendar.ini.php` format and how a v4 `calendar.php` is translated into it |
| [access-rules.md](docs/design/access-rules.md) | Release rules with minute precision and re-lock ranges, compatible with `cal4.access` |
| [compatibility.md](docs/design/compatibility.md) | The drop-in contract: everything an existing course relies on |

## Goals

1. **Drop-in replacement.** A v4 course switches by pointing its `calendar` symlink (or
   submodule) at Cal5. Nothing else changes: not `calendar.php`, not the access and virtual
   files, not the content, URLs, bookmarks or file keys.
2. **Easier configuration.** A new `calendar.ini.php` with real dates, sensible defaults and
   clear error messages. A v4 `calendar.php` keeps working through a translation layer, and a
   command-line tool can write the new file from the old one.
3. **Release by date and time.** Material can open at a specific minute and can lock again
   automatically at the end of a range.
4. **Modern look, minimal JavaScript.** No Bootstrap, no jQuery. Plain modern CSS (light and
   dark mode, responsive), and a few small vanilla scripts.
5. **Content types.** Render `.html`, `.md` and `.txt`. Offer `.pdf`, `.ppt` and `.pptx` (and
   other files) as downloads.
6. **Requirements.** PHP 8.3; MathJax stays.
7. **Removed.** The built-in file editor.

## Guiding principles

- **Compatibility is tested, not hoped for.** Milestone 1 sets up a compatibility suite that
  runs the same fixture courses through v4 and Cal5. Every later milestone adds to it, and the
  final milestone runs it against copies of the real courses.
- **Build on calendar-core's fixes.** The security and bug fixes already merged into
  calendar-core are the baseline for behaviour. Its 210-check test suite becomes the start of
  Cal5's compatibility suite.
- **No new runtime dependencies.** No Composer packages or build step are needed to deploy.
  Vendored browser libraries are limited to MathJax, highlight.js, and Ace (loaded only when a
  page uses `<ace>`).
- **Small, reviewable PRs.** Each milestone is usable and fully tested on its own, even
  before the whole system is done.

## Milestone overview

| # | Milestone | Main deliverable | Depends on |
|---|---|---|---|
| 0 | Plan and design | This document and `docs/design/` | – |
| 1 | Skeleton and test harness | Layout, entry shims, CI, fixture courses, compatibility runner | 0 |
| 2 | Configuration | Config model, `calendar.ini.php` parser, v4 translator, `bin/cal5` CLI | 1 |
| 3 | Content scanning and schedule | Directory scanner, virtual files, boxes, calendar dates, v4 file keys | 2 |
| 4 | Access rules | Rule parser and evaluator: minutes, ranges, time zones, v4 precedence | 3 |
| 5 | Authentication and sessions | Instructor login (v4 compatible), password hashes, CSRF, lock mode | 2 |
| 6 | Rendering pipeline | HTML / Markdown / text renderers, downloads, every Cal4 tag | 3, 4, 5 |
| 7 | New user interface | Theme, navbar, month and list calendars, print styles; no Bootstrap or jQuery | 6 |
| 8 | Legacy content compatibility | Compatibility CSS for Bootstrap/Skeleton classes in course pages, opt-in legacy JS, course audit tool | 7 |
| 9 | Remaining features and URL routing | Search, keyword search, `?show=`/`?load=` views, instructor status page, every v4 URL | 6, 7 |
| 10 | Drop-in validation and release | Side-by-side comparison against real courses, documentation, installer, v5.0.0 | all |

Milestones 4 and 5 are independent of each other and can be reviewed in either order.

---

## Milestone 0: Plan and design

**Branch:** `milestones`

- `MILESTONES.md` (this file).
- Design documents for architecture, configuration, access rules and compatibility.

**Acceptance:** you agree with the scope, the order, the configuration format and the access
rule syntax. Changes to the plan are made here before Milestone 1 starts.

---

## Milestone 1: Skeleton and test harness

**Branch:** `milestone-01-skeleton`

- Directory layout (see architecture.md): `src/` (classes, plain PSR-4 style autoloader, no
  Composer needed), `public/` assets, `bin/`, `tests/`, `docs/`.
- **Entry shims for v4 courses:**
  - `calendar_main.php`: what v4's `calendar.php` already `require_once`s.
  - `index.php`: for new courses.
  - At this stage both only bootstrap the app and show a "Cal5 is installed" page.
- **Test runner:** `php tests/run.php`, with no PHPUnit dependency (PHPUnit-compatible later if
  wanted). It has:
  - Unit test support: small assertion helpers.
  - HTTP test support: fixture course built in a temp directory and served by `php -S`, with
    cookie jar, CSRF and form helpers. Fails on any PHP warning, notice or deprecation.
- **Fixture courses** (`tests/fixtures/`):
  - The v4 sample course from the `calendar` repo.
  - The calendar-core test course.
  - A "kitchen sink" course using every v4 variable and tag.
- **Compatibility runner** (`tests/compat/`): runs a list of requests against both v4
  (calendar-core, fetched at a pinned commit) and Cal5, then compares visibility, links, keys
  and status codes. It is checked in now and grows with each milestone. In this milestone it
  only runs against v4, to record the expected results.
- **GitHub Actions:** `php -l`, the tests on PHP 8.3, and a PHP 8.4 job that is allowed to fail
  (an early warning).
- **Coding standards note:** `declare(strict_types=1)`, typed properties, final classes by
  default.

**Acceptance:** CI is green. The expected v4 behaviour is recorded for every fixture request.

---

## Milestone 2: Configuration

**Branch:** `milestone-02-configuration`

- **`Config` value objects:** course, semester, schedule, days, boxes, menus, replace, style,
  security, features. They are immutable and validated.
- **`IniReader`:** reads `calendar.ini.php` (format in configuration.md):
  - Forgiving INI-style syntax, with no reserved words and quotes optional.
  - Errors carry a line number.
  - Unknown keys produce warnings, not errors.
- **`LegacyTranslator`:** turns the v4 globals into the same `Config`. Every v4 variable is
  mapped (the table is in configuration.md), including `$COMBINE`, `$REVEALHOUR`, `$HIDE_*`,
  `$CUSTOMCSS`, `$NAVBAR_DROPDOWNS*` and `$LOCK`.
- **Detection:**
  - `calendar.ini.php` exists → use it.
  - Otherwise, use the v4 globals left by the course's `calendar.php`.
  - A course with neither gets a setup help page.
- **`bin/cal5` CLI:**
  - `translate [path/to/calendar.php]`: writes `calendar.ini.php` from a v4 config, with
    comments.
  - `check`: validates a config and prints the resolved calendar.
  - `hash-password`: prints a password hash for the config.
- **Tests:** every v4 variable; the sample and kitchen-sink configs translate to the expected
  `Config`; a translated INI file loads to an identical `Config` (round trip); bad input
  produces friendly errors.

**Acceptance:** `bin/cal5 translate` turns each fixture `calendar.php` into a `calendar.ini.php`
that loads to an identical `Config`.

---

## Milestone 3: Content scanning and schedule

**Branch:** `milestone-03-content-schedule`

- **`ContentScanner`:**
  - Component directories, natural sort.
  - Event names: everything after the first `.`.
  - Hidden files skipped at every level.
  - Symlinks confined to the course directory or `allowed_paths`.
  - Virtual files (`cal4.virtual` format, comments, tab-separated paths).
- **`Boxes`:** assign files to calendar boxes.
  - Ordered candidate lists (`index.html, index.md, slides.pdf`).
  - Render modes inferred from the extension (render / download / inline / raw).
- **`Schedule`:**
  - Weekly pattern plus dated overrides, including multi-word types (`final exam` →
    `final-exam/`).
  - Combined days.
  - Meeting times.
  - Courses that cross a year boundary (e.g. November to January), which v4 cannot do.
- **File keys:** the same `sha1(secret . type . dir . file)` as v4, so existing key links and
  bookmarks keep working.
- **Tests:** the compatibility runner compares Cal5's computed event dates, names and keys with
  v4 for every fixture.

**Acceptance:** for every fixture course, the dates, event numbers, names, boxes and keys are
identical to v4.

---

## Milestone 4: Access rules

**Branch:** `milestone-04-access-rules`

- **`AccessRuleParser`:** the grammar in access-rules.md. It is a superset of `cal4.access`, so
  every v4 line keeps its meaning. It adds:
  - ISO dates and times: `2026-09-14 13:30`.
  - Relative times: `+2d 08:00`, `+90m`.
  - Named anchors: `start`, `end` (the meeting's start and end).
  - `always`, `never`.
  - `until ...` re-lock ranges.
- **`AccessPolicy`:**
  - Applies v4's rule precedence exactly.
  - Uses the course time zone and minute precision.
  - Takes an injectable clock, so tests can set "now".
  - Default-deny for events not on the calendar.
  - The check happens whenever a file is served, not only on the calendar.
- **`<postmeeting>`:** reveals after the meeting ends (from schedule times), or at
  `$REVEALHOUR` for v4 configs.
- **Instructor view:** each file shows "opens Mon 14 Sep 13:30, locks Fri 18 Sep 23:59".
- **Tests:** every v4 rule form, compared with v4 results. Time and range edge cases
  (minute boundaries, the end of a range, DST changes, year boundary). Fuzzed invalid lines.

**Acceptance:** v4 rule files give identical results to v4. The new forms are covered by tests
at minute resolution.

---

## Milestone 5: Authentication and sessions

**Branch:** `milestone-05-auth`

- **Instructor login:**
  - Accepts v4's `?password=` (plaintext) and the challenge-response (`sha256(password+nonce)`)
    used by v4's login form, so old links, bookmarks and scripts still work.
  - Accepts `password_hash` values from the new config.
- **Session security:** `session_regenerate_id` on login, `hash_equals`, `random_bytes` nonces,
  and HttpOnly / SameSite=Lax / Secure cookies.
- **Brute-force protection:** a simple rate limit on failed logins (file-based, per IP address).
- **Lock mode:** `$LOCK` / `security.lock` (a whole-site lock for students).
- **`<lock code="">` sections:** unlocked by code, codes kept in the session (v4 behaviour).
- **CSRF tokens** for every POST form.
- **Tests:** a v4 login flow against Cal5; session fixation; CSRF; lock modes.

**Acceptance:** an instructor can log in with the course's existing v4 password and login
form flow, unchanged.

---

## Milestone 6: Rendering pipeline

**Branch:** `milestone-06-rendering`

- **`Renderer` interface**, with one renderer per type:
  - `HtmlRenderer`: `.html`; `.htm` keeps v4's "no navbar" behaviour.
  - `MarkdownRenderer`: `.md`, a port of calendar-core's converter plus fixes.
  - `TextRenderer`: `.txt`, escaped and shown in a readable `<pre>` block with line wrapping.
  - `DownloadResponder`: `.pdf` opens inline; `.ppt`/`.pptx` and anything else download as
    attachments. It streams the file (no `file_get_contents`) and supports `Range` and
    `Content-Length`.
- **Tag processors**, each in its own class: `inst`, `student`, `inject`, `codeinject`,
  `replace`, `showlink`, `postmeeting`, `host`, `lock`, `url`, `ace`, `calphp`. Behaviour and
  ordering match v4, and output is escaped in every case.
- **Link and image rewriting** to keyed URLs. `##` headings feed the navbar menu.
- MathJax and highlight.js are included on rendered pages; Ace only when an `<ace>` tag is
  present.
- **Tests:** the output of each tag matches v4 for the fixtures. The Markdown and text
  renderers have their own unit tests. Downloads are checked for content type, disposition and
  ranges.

**Acceptance:** every fixture page shows the same content to students and instructors as v4.
Only the surrounding layout differs.

---

## Milestone 7: New user interface

**Branch:** `milestone-07-ui`

- **One stylesheet** (`public/css/cal5.css`):
  - CSS custom properties, system and Raleway fonts.
  - Light and dark themes (`prefers-color-scheme`).
  - Responsive at phone width.
  - A print stylesheet.
- **Header and navigation:**
  - Course title, calendar, resources and information links.
  - Per-type event menus, using `<details>` dropdowns that work without JavaScript.
  - The page section menu (built from headings).
  - Search box, login and logout.
- **Calendar views:**
  - Month grid: today highlighted, type colours, release badges, and "opens at" times for the
    instructor.
  - List / agenda view: v4's `calendar_display_alt` and `calendar_display_dateless` become
    options of this one view.
- **JavaScript:** one small vanilla file, `public/js/cal5.js` (login hashing, menus, "answers"
  toggle). Under 10 KB.
- **Icons:** inline SVG; v4 glyphicon names in `$NAVBAR_DROPDOWNS` are mapped to the closest
  icon.
- **Accessibility:** keyboard navigation, focus styles, ARIA on the menus, contrast checked in
  both themes.
- **Tests:** screenshots of the fixture courses with Playwright (desktop and phone, light and
  dark) are attached to the PR for review, and an automated check confirms no Bootstrap or
  jQuery files are requested.

**Acceptance:** you approve the look from the screenshots. No page loads Bootstrap or jQuery
by default.

---

## Milestone 8: Legacy content compatibility

**Branch:** `milestone-08-legacy-content`

Course pages written for v4 may use Bootstrap/Skeleton classes or jQuery in their own HTML.
This milestone keeps them working without bringing back the old stack.

- **`public/css/compat.css`:** small re-implementations of the Bootstrap 3 / Skeleton classes
  that course content commonly uses: `table*`, `row` / `columns` / `col-*`, `btn*`, `alert*`,
  `panel*`, `well`, `jumbotron`, `label`, `badge`, `text-*`, `pull-*`, `img-responsive`, and the
  glyphicons used in content (as SVG). Loaded automatically for v4 configs, opt-in for new ones.
- **Opt-in legacy JavaScript** (`[features] legacy_js = jquery, datatables, chartjs`), loaded
  only for courses that need it. Kept as vendored files, but never loaded by default.
- **`bin/cal5 audit <course>`:** scans a course's content for Bootstrap classes, jQuery (`$(`,
  `jQuery`), DataTables / Chart.js, `<calphp>`, `<ace>` and `.htm` files. It prints what needs
  `compat.css` or `legacy_js`, and suggests config lines.
- **Tests:** fixture pages that use each class render acceptably (screenshots); the audit tool
  reports each case.

**Acceptance:** running the audit on each of your courses reports nothing that is unhandled.

---

## Milestone 9: Remaining features and URL routing

**Branch:** `milestone-09-features-routing`

- **`Router`:** maps every v4 URL form to a Cal5 handler:
  - Query parameters: `type`, `event`, `key`, `load`, `show`, `search`, `keyword`, `answers`,
    `print`, `nocss`, `navbaronly`, `password`, `lock`, `debug`.
  - `edit` / `editor` are ignored; the editor is removed.
- **Views:**
  - Search (plain text) and keyword search.
  - `?load=` pages (`.html`/`.md`/`.pdf`, with the allow-list).
  - "Today's lecture" start page.
  - Print view.
- **Instructor status page** (replaces the file selector and editor): every file in an event,
  its box, release window and current state, and a "view as student" toggle.
- **Debug view**, gated by `features.debug`.
- **Access log**, with sensitive parameters removed.
- **Tests:** the compatibility runner covers every v4 URL form.

**Acceptance:** the full compatibility suite passes for every fixture course.

---

## Milestone 10: Drop-in validation and release

**Branch:** `milestone-10-release`

- **Real-course validation:** `bin/cal5 compare <course-dir>` serves a course with v4 and with
  Cal5 side by side, crawls every page and file link as a student and as the instructor, and
  reports differences in visibility, links, status codes and content. It is run on copies of
  your dozen courses and on the other professors' configurations; results go into the PR.
- **Documentation:** README, rewritten instructor guide (served at `?load=instructor`),
  migration guide ("switching a course to Cal5" and "moving to `calendar.ini.php`"), access
  rule reference.
- **Installer:** a new `installer.sh` for new courses. For v4 courses, switching is a symlink
  or submodule change, and the migration guide covers rolling back.
- **Release:** version `5.0.0` and a CHANGELOG. An update to the `calendar` shell repo is
  offered as a separate PR.

**Acceptance:** the comparison is clean, or every difference is explained, for all of your
courses. You switch one course first, then the rest.

---

## Out of scope (for now)

- The in-browser file editor (removed).
- A database. Everything stays file-based, like v4.
- Student accounts or per-student release. Access stays as it is in v4: public, plus the
  instructor login and lock codes.
- Multi-course hosting from one install. Each course still has its own directory.
