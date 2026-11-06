# Architecture

## Constraints

- **PHP 8.3** (8.4 is tested as an early warning). Extensions: `dom`, `mbstring`, `session`.
  The `calendar` extension is no longer needed; date handling uses `DateTimeImmutable`.
- **Deployment stays the same:** a directory inside a course directory on an Apache (or
  nginx) PHP server, with no Composer, no build step, no database and no writable directory
  except optional logs.
- **MathJax is required.** It is vendored and served from the repository, fonts included,
  so pages never load anything from a CDN. Cal5 uses MathJax 4 (calendar-core has 3.2.2);
  see `public/vendor/README.md` for every library's version.
- **Front-end:**
  - Vendored libraries: MathJax, highlight.js, and Ace (only on pages with `<ace>`).
  - Cal5's own code: one CSS file and one small vanilla JS file.
  - No Bootstrap, no jQuery.
  - Legacy libraries are opt-in for courses that need them (compatibility.md).

## Directory layout

```
calendar2/
├── calendar_main.php        v4 entry shim (translates the v4 globals, then runs the app)
├── index.php                new-course entry point
├── bin/
│   └── cal5                 CLI: translate, check, hash-password, audit, compare
├── src/                     PHP classes, namespace Cal5\ (autoloader in src/autoload.php)
│   ├── App.php              request lifecycle
│   ├── Config/              Config, IniReader, LegacyTranslator, Validator
│   ├── Content/             ContentScanner, VirtualFiles, Boxes, FileKey
│   ├── Schedule/            Schedule, Event, MeetingTime
│   ├── Access/              AccessRuleParser, Rule, When, AccessPolicy, Clock
│   ├── Auth/                InstructorAuth, Session, Csrf, RateLimiter, LockMode
│   ├── Render/              TagPipeline, PageContext, Markdown (converter), HeadingIndex,
│   │                        CalphpRunner, LegacyScripts, Tags/ (one class per Cal4 tag)
│   ├── Http/                Request, PageRequest (type/event/key), Router/Route, Download, AccessLog
│   ├── View/                EventPage, SitePage, CalendarModel/View, SearchView, StatusView,
│   │                        DebugView, Layout (head + header), Icons, CompatCss, InstalledPage (set-up)
│   ├── Audit/               CourseAudit (bin/cal5 audit)
│   ├── Cli/                 bin/cal5 commands
│   └── Support/             Html escaping, paths, natural sort, …
├── public/                  served to browsers as calendar/public/...
│   ├── css/cal5.css         theme (light/dark, responsive, print)
│   ├── css/compat.css       Bootstrap/Skeleton classes and glyphicons for legacy course pages
│   ├── js/cal5.js           menus, login hashing, highlight.js start (no dependencies, < 10 KB)
│   └── vendor/              mathjax/ (+ its fonts), highlight/, ace/, legacy/ (jquery, datatables, chartjs)
├── pages/                   Cal5's own ?load= pages: error, instructor (the guide)
├── images/web-icon.png      kept at the v4 path
├── docs/                    design documents, instructor guide, migration guide
└── tests/
    ├── run.php              test runner (unit + HTTP), no dependencies
    ├── unit/                one file per class
    ├── http/                end-to-end tests against fixture courses
    ├── compat/              v4-vs-Cal5 comparison runner and expected results
    └── fixtures/            sample, calendar-core test course, kitchen-sink course
```

The PHP code lives in `src/`, outside `public/`. The milestone 1 install docs include a
deny-all `.htaccess` for `src/`, `bin/`, `tests/` and `docs/`. Like v4, Cal5 assumes the whole
repository sits under the course's web directory.

## Request lifecycle

```
calendar.php (v4)  ─┐
index.php (new)    ─┴─> Cal5\App::run()
   1. Config        calendar.ini.php ? IniReader : LegacyTranslator(v4 globals)
   2. Request       validate type/event/key/... (calendar-core 4.5 rules)
   3. Session/Auth  instructor? lock mode? (cookies compatible with v4)
   4. Content       ContentScanner -> events, files, keys (cached per request)
   5. Schedule      events -> dates (+ meeting times)
   6. Access        AccessPolicy filters what a student may see (clock injected)
   7. Router        v4 URL -> handler: Page | Download | Load | View | Search | Status
   8. Render        Renderer by extension -> tag pipeline -> Layout (navbar, theme)
   9. Response      headers (CSP, nosniff, cookies), body or streamed file
```

Unlike v4, nothing runs at include time with global variables. Each step is a class with
explicit inputs, so it can be unit tested. The v4 shim is the only place that reads globals.

## Content and schedule (milestone 3)

`Cal5\Course::load()` reads a course in two steps.

**`ContentScanner`** finds the events and files, following v4 exactly:
- **Event directories** of every type named in the schedule, in natural order (`1.`, `2.`,
  `10.`). Each event is named after the first `.`.
- **Files** below each event directory are sorted. Any path with a hidden part (`.x`) is
  skipped. Symlinks are resolved and must stay in the course or `files.allowed_paths`.
- **Virtual files** are added after the real ones, replacing a real file of the same name in
  place.
- **Boxes** are filled by `display.box_pick`. v4 configs use `first-by-name`: the first file, in
  that order, that belongs to the box.
- **Keys** use v4's formulas (`Cal5\Content\FileKey`).

**`Schedule`** walks every date of the semester, so a semester can cross into the next year.
`[days]` replaces the weekly plan for its date. v4's numbering rules apply:
- a scheduled day advances its type's counter only if that type has event directories;
- the day shows the event with that number if it exists;
- dated days always show;
- `[combine]` rules fire on "type N" and advance the added types' counters even when those types
  have no material.

`tests/compat check-structure` compares all of this with v4 for every fixture course.

## Rendering (milestone 6)

**`View\EventPage`** answers `?type=T&event=N[&key=K]` and picks the file exactly as v4 does:

| Request | File |
|---|---|
| key of a file of this event that the viewer may see | that file |
| key that names no file anywhere in the course | the event's title file (v4's fallback) |
| key of another event's file, or of a hidden file | nothing (an empty page) |
| no key | the title file; for students only while it is visible |
| any PHP script, for a student | nothing (calendar-core 4.6) |

What happens next depends on the file's extension (`Content\FileKind`):

- `.html` → the tag pipeline, inside the layout. `.htm` → the same, without the layout (v4
  `nocss`).
- `.md` → `Render\Markdown` (the v4 converter, unchanged output), in
  `<div class="cal4-markdown">`, then the tag pipeline.
- `.txt` → escaped text in `<pre class="cal5-text">` (wrapped, monospace) inside the layout.
  v4 sent it as `text/plain` (an intended difference).
- Anything else → **`Http\Download`**: v4's content types and inline/attachment choices
  (`.pdf` inline, `.ppt`/`.pptx` attachments, ...), streamed in 64 KB chunks with
  `Content-Length`, `Accept-Ranges` and single `Range` requests (206/416), and `HEAD`. Audio and
  video get their real types and play inline; `.jpg` is `image/jpeg`.

`?print` keeps the theme without the navbar, `?nocss` sends the content only, and `?navbaronly`
shows the navbar without the content.

**`Render\TagPipeline`** runs the Cal4 tags in v4's order, one class per tag in `Render\Tags\`:

```
(link rewriting -> replace -> inject -> codeinject) x up to 5 passes
-> postmeeting -> inst -> host -> lock -> student -> tabs to 2 spaces -> ace -> url
-> headings (section menu) -> calphp (runs the scripts)
```

Each tag sees a `PageContext`: the configuration, whether the viewer is the instructor, the
request and session, the event's files the viewer may see (v4's `$other`), the `<replace>`
values (the course's, then v4's built-in `event`, `zevent`, `type`, `ctype`, `course`,
`coursename`, `coursenamefull`, `title`, `access_file`, `class_file`), the event's date and
meeting time, and the time now. The regular expressions are v4's, so content written for v4
gives the same result. Changes to `<ace>`: it no longer needs jQuery, its attribute values
are limited to safe characters before they are written into the script, and Ace's background
syntax checkers (workers) are off, so the boxes show and run code without lint markers.

**`View\Layout`** writes the `<head>` (theme, MathJax on every page, highlight.js, Ace only
when the page has an `<ace>`, the course's own CSS/JS). MathJax 4 is set up to match what v4
courses had: `\\( \\)` for inline math (`$` is left alone) and the TeX extensions of v3's
"full" bundle (`mathtools`, `physics`, `mhchem`, `cancel`, `cases`, ...), with its fonts served
from `public/vendor/`. The navbar has event menus that link
the same events v4 links, the section menu, the show/hide answers link, the instructor's file
list, and the login form. Milestone 7 replaces its look; the links stay the same.

`<calphp>` scripts run as in v4: in their own directory, with the course's v4 variables
(`$COURSE`, ...) and `$INSTRUCTOR`, but without `$ADMIN` and `$SECRET` (v4 removed them too).

**Compared with v4:** `php tests/compat/compat.php check --impl=cal5 --scope=events` sends
every event page and every file of every fixture, as a student and as the instructor, and
compares the content markers, the keyed and event links on the page, and for files the content
type, disposition and SHA-1. All 436 requests match; CI runs it strictly. The intended
differences above are applied by `tests/compat/IntendedDifferences.php`, and nothing else is
excused.

## Security baseline

Everything fixed in calendar-core 4.5 is designed in from the start:

- **Output:** escaping by default in templates.
- **Input:** strict validation of the request.
- **Access:** checked whenever a file is served.
- **Allow-lists:** `?show=` / `?load=`.
- **Files:** symlinks confined to the allowed paths.
- **Sessions:** CSRF tokens; cookie flags.
- **Config:** the sample secret is refused.
- **Logs:** sensitive parameters removed from the access log.

New in Cal5: password hashes, login rate limiting, and the removal of the editor (the largest
risk in v4).

## Performance

- The content scan runs once per request. An optional cache file is keyed on directory
  modification times (Milestone 3; off by default).
- Downloads are streamed (`fpassthru`) with `Content-Length` and `Range`, so large PDFs and
  videos work.
- Tag processing makes a fixed number of passes and stops early when nothing changed.
