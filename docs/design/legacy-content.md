# Legacy course content (milestone 8)

v4 loaded Bootstrap 3, Skeleton, jQuery, DataTables, Chart.js and Font Awesome on every page,
so course pages could use any of them. Cal5's own pages use none of them. This milestone keeps
course pages written for v4 working without bringing the old stack back.

## Styles: `public/css/compat.css` (`features.legacy_css`)

Small re-implementations of the Bootstrap 3 and Skeleton classes course content commonly uses,
in Cal5's colours, in light and dark mode:

| Group | Classes |
|---|---|
| Grids | Bootstrap `row` + `col-xs/sm/md/lg-N` and `col-*-offset-N` (same breakpoints); Skeleton `row` + `one`…`twelve columns`, `one-third`/`two-thirds`/`one-half column`, `offset-by-*`; `container`, `clearfix` |
| Tables | `table`, `table-striped`, `-bordered`, `-hover`, `-condensed`, `-responsive`; row colours `active`, `success`, `info`, `warning`, `danger`; Skeleton `u-full-width` |
| Buttons | `btn` + `btn-default/primary/success/info/warning/danger/link`, `btn-lg/sm/xs/block`, `btn-group`; Skeleton `button`, `button-primary` |
| Boxes | `alert-*`, `panel` (+ heading/title/body/footer, contextual colours), `well` (+ `-sm`/`-lg`), `jumbotron` |
| Text and labels | `label-*`, `badge`, `lead`, `small`, `text-left/center/right/justify/nowrap`, `text-muted/primary/success/info/warning/danger`, `bg-*`, `page-header`, `kbd` |
| Helpers | `pull-left/right`, `center-block`, `hidden`, `show`, `invisible`, `sr-only`, `list-unstyled`, `list-inline`, `img-responsive/rounded/circle/thumbnail`, `caret`, Skeleton `u-pull-left/right`, `u-max-full-width`, `u-cf` |
| Components | `list-group(-item)`, `progress(-bar-*)`, `form-group`, `form-control`, `input-group`, `help-block`, `nav`, `nav-tabs`, `nav-pills`, `breadcrumb`, `pagination`, `media` |
| Glyphicons | `glyphicon glyphicon-NAME`, drawn with Cal5's icons as CSS masks (about 180 names, `Icons::GLYPHICONS`) |

- Every rule is scoped with `:where(.cal5-page)`: it only applies to the page content, never to
  Cal5's header, and a course's own stylesheet wins over it.
- Cal5's own content styles (headings, tables, code) have zero specificity, so these classes
  and course stylesheets win over them; tables with a class of their own are left to them.
- Bootstrap's `collapse` content is shown (its JavaScript is not loaded, and hidden content
  would otherwise be unreachable).
- The column grid and the glyphicon masks are generated (`php bin/cal5 build-compat-css`);
  a unit test checks the file is up to date.

## Scripts: `features.legacy_js`

| Value | Effect |
|---|---|
| *(empty)* | Nothing (the default for new `calendar.ini.php` courses) |
| `auto` | Each library on the pages whose scripts use it (the setting for translated v4 configs) |
| `jquery`, `datatables`, `chartjs` | Those libraries on every page (combine with `auto` if needed) |

`auto` reads the page's own JavaScript (inline `<script>` blocks, `on…=""` handlers,
`javascript:` links) and the course's script files (`[style] js`, v4 `$CUSTOMJS`):

- `$(…)`, `$.something`, `jQuery` → jQuery
- `.DataTable(…)`, `.dataTable(…)` → DataTables (and jQuery)
- `new Chart(…)`, `Chart.defaults` … → Chart.js

The libraries are loaded in `<head>` before the course's scripts, so inline scripts can use
them as in v4. Versions: jQuery 3.7.1, Chart.js 2.9.4 (the newest of the major versions v4
used), the DataTables 1.13.2 bundle as calendar-core shipped it (`public/vendor/README.md`).

Not provided: Bootstrap's own JavaScript (`data-toggle`, `.modal()`, `.tooltip()`, …) and Font
Awesome. The audit lists pages that use them.

## `bin/cal5 audit`

```
php calendar/bin/cal5 audit [--json] [--course DIR]
```

Scans every event page (`.html`, `.htm`, `.md`), the course's root pages and its own CSS/JS,
and reports:

- Bootstrap and Skeleton classes and glyphicons, and whether `compat.css` covers each one;
- jQuery, DataTables and Chart.js use, by file;
- what Cal5 does not provide: Bootstrap's JavaScript, Font Awesome, links to v4's files
  (`calendar/bootstrap/…`, `calendar/jquery/…`; `calendar/images/web-icon.png` is kept);
- for information: `<calphp>`, `<ace>`, `.htm` pages, scripts or styles from other hosts;
- the `[features]` lines to use, and the course's current setting.

It exits with 1 when something needs attention, so it can be run over every course before
switching. The milestone's acceptance: the audit of each course reports nothing unhandled.

## Tests

- `tests/fixtures/legacy-content`: a v4 course whose pages use Bootstrap, Skeleton,
  glyphicons, jQuery, DataTables and Chart.js, plus Bootstrap's JavaScript, Font Awesome and a
  link to v4's files. Recorded with v4 like the other fixtures; Cal5 matches it.
- `tests/browser/check.js` opens its pages in Chromium: the libraries are loaded exactly on
  the pages that use them, the scripts work (the jQuery text changes, DataTables wraps the
  table, the chart is drawn), and every other page of every course loads none of them.
- `tests/unit/LegacyContentTest.php` (detection, generated CSS, the class list) and
  `tests/http/LegacyPagesTest.php` (what each page loads, new courses, the audit).
