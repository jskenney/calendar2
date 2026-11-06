# Routing: every v4 URL form (milestone 9)

`Cal5\Http\Router` decides what a request shows, in v4's order. `App` then hands it to
`View\EventPage` (an event page or file) or `View\SitePage` (everything else).

| # | Request | Shows |
|---|---|---|
| 1 | The configuration was read but can not run (the published sample secret, a failed check), or the access file exists but can not be read | The **error page**, in the usual layout with the course's menus. No content is served. The instructor sees the problems; `bin/cal5 check` lists them |
| 2 | A locked site (`[security] lock`, v4 `$LOCK`), for students | The **lock page**: the course's `lock.html`/`.md`, or Cal5's message. No menus |
| 3 | `type`, `event`, `key` | Validated as calendar-core 4.5 does (configured type, positive number without leading zeros, 40 hex characters). If any is invalid, all three are dropped |
| 4 | `navbaronly` with an event | The instructor's **status page** for the event |
| 5 | An event (`type` + `event`, optional `key`) without `show`/`load` | The **event page** or file (`docs/design/architecture.md`, "Rendering") |
| 6 | Nothing at all (no `type`, `event`, `key`, `load` or `show`) | The **start page**: today's lecture when `display.start_page = today` (v4 `$DEFAULT_TODAYS_LECTURE`) and today has a title page the viewer may see, otherwise `load=home` |
| 7 | Anything else | The `show` view first, then the `load` page (v4's order) |

Without any configuration, or when the library directory itself is requested, the set-up
page (`View\InstalledPage`) is shown instead.

## `?load=NAME`

- `NAME` must be letters, digits, `-` or `_`, and on the list: `files.load_pages` (v4
  `home`, `policy`, `resources` plus `$LOAD_PAGES`), `error` and `lock`, and `instructor` for
  the instructor.
- Looked up in v4's order: the course's `NAME.pdf` (sent as a download), Cal5's own page for
  `error` and `instructor` (`pages/`), then the course's `NAME.html`, then `NAME.md`.
- The page goes through the tag pipeline like an event page (`<replace>`, `<inst>`,
  `<student>`, `<lock>`, `<url>`, ...). Pages that are not allowed, or do not exist, are empty.

## `?show=VIEW`

| `show` | View |
|---|---|
| `calendar_display`, `calendar_display_alt`, `calendar_display_dateless` | Month, list and agenda views (`docs/design/ui.md`) |
| `calendar_search&search=TEXT` | Event days whose title page (`.html`/`.md`) contains TEXT, not case sensitive; students only search pages they may see |
| `calendar_keyword&keyword=NAME` | Event days whose title page has `<a name="NAME"></a>`. Without a keyword, Cal5 lists the keywords the pages define |
| `calendar_file_selector` | The instructor's status page (with an event) |
| `calendar_file_editor` | The instructor sees "the file editor has been removed" |
| anything else | Nothing |

## Status page and "view as a student"

The status page (`?navbaronly=1&type=T&event=N`, the month view's day numbers, and "Release
status of these files" in the Files menu) lists every file of the event with its box, how it
is shown, whether students can see it now, and its release window with the rule and line
that decided it. **View as a student** adds `as=student`: the instructor sees the page as a
student would now, with a banner leading back. Students' `as=student` is ignored.

## Debug view and access log

- `?debug`, for the instructor, when `features.debug` is on (v4 `$DEBUG_MODE`): the request
  parameters (passwords, lock codes and tokens removed), the routing decision, the
  configuration's state, the session's keys (not their values), and the event's files with
  their release windows. It makes no links.
- `features.access_log` (v4 `$ACCESS_LOG`): one line per request, `Ymd-H:i:s ADDRESS QUERY`
  (`home` without a query), written only if the file exists. `password`, `lock`, `editor`
  and `csrf` are written as `REMOVED`; posted values are never logged. The instructor is
  warned when the log is inside the web root.
