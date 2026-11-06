# Vendored browser libraries

Served to browsers from `calendar/public/vendor/`. Nothing here is required at build time, and
pages load nothing from a CDN. Each directory holds the browser files of the npm package named
below, unchanged, plus its licence.

| Directory | Library | Version | Licence | Source |
|---|---|---|---|---|
| `mathjax/` | MathJax (required): `tex-chtml.js` and the components it loads (`input/`, `output/`, `a11y/`, `ui/`, `sre/`, ...) | 4.1.3 | Apache-2.0 (`mathjax/LICENSE`) | npm `mathjax` |
| `mathjax-newcm-font/` | MathJax's default font, CHTML files only (`chtml.js`, `chtml/`) | 4.1.3 | Apache-2.0 | npm `@mathjax/mathjax-newcm-font` |
| `mathjax-mhchem-font-extension/` | Font extension for `\ce` (mhchem), CHTML files only | 4.1.3 | Apache-2.0 | npm `@mathjax/mathjax-mhchem-font-extension` |
| `highlight/` | highlight.js (`highlight.min.js`, `styles/color-brewer.min.css`, `styles/github-dark.min.css`) | 11.12.0 | BSD-3-Clause (`highlight/LICENSE`) | npm `@highlightjs/cdn-assets` |
| `ace/` | Ace (`src-min-noconflict`: `ace.js`, every `mode-*` and `theme-*`), loaded only on pages with `<ace>` | 1.45.2 | BSD-3-Clause (`ace/LICENSE`) | npm `ace-builds` |

Left out on purpose: MathJax's SVG output and other combined files, Ace's extensions,
keybindings, snippets and workers (Cal5 turns the workers off).

To update a library, replace its directory with the same files from the new npm release,
update this table, and check a page with math, code and an `<ace>` box in a browser: no
console errors, no failed requests, and no requests to other hosts. `tests/http/EventPageTest.php`
checks that every file the page head names exists.

### Legacy libraries (`legacy/`, milestone 8)

For course pages written for v4. Loaded only by `features.legacy_js` (`auto`: on the pages
whose scripts use them). Bootstrap is not included: `public/css/compat.css` covers its classes.

| Directory | Library | Version | Licence | Source |
|---|---|---|---|---|
| `legacy/jquery/` | jQuery (`jquery.min.js`) | 3.7.1 (v4: 3.6.3) | MIT (`jquery/LICENSE.txt`) | npm `jquery` |
| `legacy/chartjs/` | Chart.js (`Chart.bundle.min.js`, includes Moment) | 2.9.4 (v4: 2.9.3) | MIT (`chartjs/LICENSE.md`) | npm `chart.js` |
| `legacy/datatables/` | DataTables bundle (`datatables.min.js/.css`) with Bootstrap 3 styling and its extensions (Buttons, Responsive, ...) | 1.13.2, unchanged from calendar-core | MIT (`datatables/LICENSE.md`) | calendar-core `datatables.net/` |

jQuery and Chart.js are the newest releases of the major versions v4 used, so course scripts
keep working (Chart.js 2.9.4 also fixes a security issue). The DataTables bundle is kept as
calendar-core shipped it, because course tables may use its extensions and that bundle can
only be rebuilt with DataTables' online builder.
