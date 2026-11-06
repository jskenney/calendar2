# User interface (milestone 7)

Cal5's own pages use one stylesheet (`public/css/cal5.css`), one small script
(`public/js/cal5.js`, under 10 KB) and inline SVG icons. No Bootstrap and no jQuery: the
browser check fails if any page requests them. Course pages that rely on Bootstrap/Skeleton
classes or jQuery get the compatibility options of milestone 8.

## Page layout

```
┌ header ─────────────────────────────────────────────────────────────────────┐
│ [icon] SI460 Computer Graphics  Calendar Resources Information [Classes▾]…  │
│                                                   [Search…]  [Log in ▾]     │
├ page bar (event pages) ─────────────────────────────────────────────────────┤
│ CLASS 11  Shading                    [Sections▾] [Files▾] [Show answers] [Print] │
└─────────────────────────────────────────────────────────────────────────────┘
  main: the course content, or a calendar view
  footer: course name (and the Cal5 version, for the instructor)
```

- **Header:** course number and name (home page link), Calendar (opens
  `display.calendar_view`), Resources, Information, the instructor guide (instructor only),
  one menu per event type (`[menus]`, or `[instructor_menus]` when logged in), search, and
  the login (a small form) or log out button. A locked site shows students only the login.
- **Event menus** list every event of the type with its number, name and date. Events whose
  title students may not see yet are shown greyed, without a link, exactly as v4 decides.
- **Page bar** (event pages): the event, its section menu (headings with an `id`, v4 rules),
  the instructor's file list (files hidden from students are marked "hidden", with their
  release window as a tooltip), the show/hide answers link when the page has `<student>`,
  and Print (v4's form: the same page with `print`, in a new tab).
- **Menus are `<details>` elements** and work without JavaScript. `cal5.js` keeps one open
  at a time and closes them on Escape (focus returns to the menu) or a click elsewhere.
- **Login:** for plain-text passwords (every v4 course) `cal5.js` sends
  `sha256(password + nonce)`, as v4's form did, so the password itself is not sent.
  Courses with a `password_hash` post the password (it must be checked on the server).
  The SHA-256 code is in `cal5.js` because `crypto.subtle` needs HTTPS.

## Calendar views

`?show=` keeps v4's names:

| `?show=` | View | Shows |
|---|---|---|
| `calendar_display` | Month | A grid per month (Monday first; weekends when `semester.weekends`). Weeks entirely outside `show_from`/`show_until` are left out |
| `calendar_display_alt` | List | Every day with material, by month, with its date |
| `calendar_display_dateless` | Agenda | The same, without dates ("Available material") |

What each view shows is decided as v4 decides (`View\CalendarModel`):

- every scheduled day with its entries, including what `[combine]` adds; days without
  material show their label (HOLIDAY);
- each entry lists the boxes of `display.calendar_boxes` (v4 `$BOX`), in that order;
- students only see boxes they may see now; the title links to the event page only when it
  is visible; `inline:` boxes paste the file's text as v4 did;
- the instructor sees every box. Day numbers open the event's file list (v4).

New in Cal5:

- **Today** is highlighted, and the month view has a "Today" link.
- **Type colours:** each event type gets one of eight colour pairs, in configuration order.
  The v4 class `calCore-cal-<TYPE>` is kept on the type tag, so course stylesheets that
  coloured it still work.
- **Release badges:** the instructor sees "opens Mon 9 Nov 08:00" on hidden material, and
  "hidden" on material that is closed or never shown (the full window is in the tooltip).
  Everyone sees "until Fri 13 Nov 23:59" on visible material that locks again.
- **Phones:** below 46rem the month grid becomes a list of the days that have something.

`compat.php check --scope=calendars` compares the three views with v4 for every fixture,
as a student and as the instructor (events, links, inline text). One intended difference:
v4 also linked the instructor's day numbers on days without material, to a page that does not
exist; Cal5 does not.

## Theme

- **Tokens:** CSS custom properties on `:root` (`--bg`, `--surface`, `--text`, `--muted`,
  `--accent`, ...), redefined for `prefers-color-scheme: dark`. highlight.js uses
  `color-brewer` in light mode and `github-dark` in dark mode.
- **Fonts:** Raleway when it is installed, otherwise the system UI font.
- **Contrast:** every text colour on every background it is used on, including the eight type
  tints, is at least 4.5:1 in both themes (`tests/unit/ThemeTest.php` checks it).
- **Accessibility:** a "Skip to content" link, visible focus outlines, `aria-current` on the
  current page and event, menus that are keyboard operable, decorative icons hidden from
  screen readers with text labels kept for them, the month grid's dates read in full
  ("Friday 6 November (today)"), and `prefers-reduced-motion`.
- **Print:** header, footer and forms are hidden; links in course pages print their address.
  `?print` gives the same page without the header.

## Icons

Inline SVG from [Lucide](https://lucide.dev) 1.70.0 (ISC, `docs/licenses/lucide.txt`); only
the icons Cal5 uses are included (`src/View/Icons.php`). A menu icon may be a Cal5 icon name
or a v4 glyphicon name, with or without `glyphicon-`. Unknown names show no icon.

Icon names: `calendar`, `list`, `layers`, `target`, `clock`, `lock`, `lock-open`, `file`, `file-text`, `presentation`, `archive`, `play`, `search`, `log-in`, `log-out`, `eye`, `eye-off`, `printer`, `menu`, `chevron-down`, `book-open`, `info`, `briefcase`, `folder`, `folder-open`, `x`, `house`, `user`, `graduation-cap`, `apple`, `flask-conical`, `hourglass`, `pencil`, `book`, `castle`, `flag`, `star`, `square-check`, `wrench`, `settings`, `list-checks`, `grid-3x3`, `circle-help`, `circle-alert`, `zap`, `flame`, `route`, `music`, `film`, `image`, `globe`, `leaf`, `heart`, `megaphone`, `bell`, `award`, `paperclip`, `terminal`, `hard-drive`, `tent`, `crown`, `scale`, `chart-column`, `signal`, `tag`, `bookmark`, `camera`, `message-square`, `mail`, `thumbs-up`, `utensils`, `gift`, `plane`, `tree-pine`, `trees`, `cloud`, `scissors`, `pin`, `map-pin`, `wheat`, `piggy-bank`, `circle-dot`, `notebook-pen`, `lightbulb`, `microscope`, `calculator`, `code`, `dumbbell`, `users`.

v4 glyphicon names map to the closest icon, for example `blackboard` → `presentation`,
`knight` → `flask-conical`, `hourglass` → `hourglass`, `education` → `graduation-cap`,
`apple` → `apple` (the full list is `Icons::GLYPHICONS`).

## Browser check and screenshots

`node tests/browser/check.js` (Playwright and Chromium) serves every fixture course and a demo
course whose semester is placed around today (`tests/browser/DemoCourse.php`), and opens the
calendar views and event pages as a student and as the instructor, at desktop and phone size,
in light and dark mode. It fails when a page requests Bootstrap or jQuery or anything from
another host, gets a failed response, logs a JavaScript error, scrolls sideways, or shows a
PHP warning. CI runs it. `--screenshots=DIR` also saves the review screenshots.
