# Architecture

## Constraints

- **PHP 8.3** (8.4 is tested as an early warning). Extensions: `dom`, `mbstring`, `session`.
  The `calendar` extension is no longer needed; date handling uses `DateTimeImmutable`.
- **Deployment stays the same:** a directory inside a course directory on an Apache (or
  nginx) PHP server, with no Composer, no build step, no database and no writable directory
  except optional logs.
- **MathJax is required.** It stays vendored (version 3.2.2, the same as calendar-core),
  served from the repository.
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
│   ├── Render/              HtmlRenderer, MarkdownRenderer, TextRenderer, DownloadResponder,
│   │                        Markdown (converter), Tags/ (one class per Cal4 tag)
│   ├── Http/                Request, Response, Router (all v4 URL forms)
│   ├── View/                Layout, Navbar, MonthView, ListView, SearchView, StatusView, templates/
│   └── Support/             Html escaping, paths, natural sort, …
├── public/                  served to browsers as calendar/public/...
│   ├── css/cal5.css         theme (light/dark, responsive, print)
│   ├── css/compat.css       Bootstrap/Skeleton classes for legacy course pages
│   ├── js/cal5.js           menus, login hashing, small helpers (no dependencies)
│   ├── icons/               SVG sprite
│   └── vendor/              mathjax/, highlight/, ace/, legacy/ (jquery, datatables, chartjs)
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

## Rendering

- The renderer is chosen by **file extension**:
  - `.html` → HTML renderer; `.htm` → HTML renderer without the layout (v4 `nocss`).
  - `.md` → Markdown converter, then the HTML renderer.
  - `.txt` → escaped text in `<pre class="cal5-text">` (wrapped, monospace) inside the layout.
  - `.pdf` → inline download (the browser's PDF viewer).
  - `.ppt` / `.pptx` and any other type → attachment download, with the correct content type.
- **Tag pipeline:** an ordered list of `Tag` processors in v4's order: link rewriting →
  replace → inject / codeinject (these three repeat, up to 5 passes, so injected files are
  processed too) → postmeeting → inst → host → lock → student → ace → url. Each processor is
  a small class with its own tests.
- **Page assets:** MathJax and highlight.js are added to rendered pages. Ace's script tags are
  added only when an `<ace>` tag is found. `compat.css` and legacy JS follow the config.

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
