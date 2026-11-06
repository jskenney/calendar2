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

Legacy libraries for course pages that need them (jQuery, DataTables, Chart.js, Bootstrap
CSS) arrive in milestone 8 and are only loaded when a course turns them on.
