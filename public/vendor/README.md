# Vendored browser libraries

Served to browsers from `calendar/public/vendor/`. Copied unchanged from calendar-core
(`5dd9018e`). Nothing here is required at build time.

| Directory | Library | Version | Licence | Source |
|---|---|---|---|---|
| `mathjax/` | MathJax (required) | 3.2.2 | Apache-2.0 (`mathjax/NOTICE`) | https://github.com/mathjax/MathJax |
| `highlight/` | highlight.js (`highlight.min.js`, `styles/color-brewer.min.css`) | 11.7.0 | BSD-3-Clause (`highlight/LICENSE`) | https://github.com/highlightjs/highlight.js |
| `ace/` | Ace editor (`src-min-noconflict` build), loaded only on pages with `<ace>` | 1.4.7 | BSD-3-Clause (`ace/LICENSE`) | https://github.com/ajaxorg/ace-builds |

Legacy libraries for course pages that need them (jQuery, DataTables, Chart.js, Bootstrap
CSS) arrive in milestone 8 and are only loaded when a course turns them on.
