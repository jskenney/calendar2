# Configuration

Cal5 reads its settings into one internal `Config` object. That object can be built two ways:

1. **New format:** `calendar.ini.php` in the course directory.
2. **v4 format:** the PHP variables set by an existing `calendar.php`, converted by the
   `LegacyTranslator`.

The rest of Cal5 only ever sees `Config`, so both kinds of course get identical behaviour.

## Which configuration is used

```
course/
  calendar.php       v4 entry point: sets $COURSE, $DOW, ... then require_once('calendar/calendar_main.php')
  calendar.ini.php   new configuration (optional)
  calendar -> calendar2/   (symlink or submodule, as in v4)
```

| Situation | Result |
|---|---|
| `calendar.ini.php` exists | It is used. Any v4 variables are ignored, and the instructor sees a notice if both exist. |
| No `calendar.ini.php`, v4 variables set | The v4 variables are translated. This is the **drop-in path**: nothing to change. |
| Neither | A setup page explains how to create a configuration. |

A new course needs only a two-line entry file, which the installer creates:

```php
<?php require __DIR__ . '/calendar/index.php';
```

The v4 `require_once('calendar/calendar_main.php')` line keeps working too, because Cal5 ships
a `calendar_main.php` shim.

## Why `calendar.ini.php` and not `calendar.ini`

The configuration contains the instructor password (or its hash) and the secret. It lives in
the course directory, which is inside the web root. The `.php` extension plus a first line of

```ini
;<?php http_response_code(404); exit; ?>
```

means a web request for the file runs PHP and returns an empty 404 instead of the file. This
was tested on PHP 8.3. For the INI reader, the line is just a comment.

## Why not PHP's `parse_ini_file()`

Tested on PHP 8.3: `parse_ini_file()` rejects the whole file when a key is one of its reserved
words (`on`, `off`, `yes`, `no`, `true`, `false`, `null`, `none`). It also treats several
punctuation characters in keys and `${...}` in values specially. Instructors naturally write
keys like `on` in `[replace]`. Cal5 therefore uses its own small reader (`IniReader`, Milestone 2):

- `[section]` headers; `key = value` lines.
- Comments start with `;` or `#` on their own line. After a value (outside quotes), `;` or `#`
  following a space starts a comment. A value may itself start with `#` (`#fff`, `C#`), but a
  value that starts with `;` is a comment.
- Quotes around values are optional. Use them to keep leading/trailing spaces or a `;`/`#`.
- Keys are any text up to the first `=`. Keys are case-insensitive. No reserved words.
- Repeated keys in a section are allowed where a list makes sense (for example `css`).
- Booleans accept `yes/no`, `true/false`, `on/off`, `1/0`.
- Lists are comma separated: `title = index.html, index.md`.
- Every error and warning names the file and line number, and is shown on an instructor-only
  banner (never to students).

## The format

### A minimal course

```ini
;<?php http_response_code(404); exit; ?>
[course]
number = SI460
name   = Computer Graphics

[semester]
start = 2026-08-24
end   = 2026-12-18

[schedule]
mon = class
wed = lab
fri = class

[security]
password_hash = $2y$12$...   ; from: php calendar/bin/cal5 hash-password
secret        = 9f3c...      ; written by the installer (64 hex characters)
```

Everything else has defaults. The default boxes show the title, homework, reading, notes and
solutions. Each box accepts `.html`, `.md`, `.txt`, `.pdf` and `.pptx`/`.ppt` names (see
`[boxes]`).

### A complete example

```ini
;<?php http_response_code(404); exit; ?>
; Cal5 course configuration.  Lines starting with ; or # are comments.

[course]
number    = SI460
name      = Computer Graphics
full_name = SI460: Computer Graphics (Fall 2026)   ; available as <replace value="coursenamefull">
title     = SI460 (Fall 2026)                      ; browser tab title
timezone  = America/New_York                       ; default: the server's time zone

[semester]
start    = 2026-08-24
end      = 2026-12-18      ; may be in the next year (e.g. 2027-01-15)
weekends = no
; Only show part of the calendar (v4 $HIDE_WEEKS)
; show_from  = 2026-08-24
; show_until = 2026-12-18

[schedule]
; weekday = type [meeting time]
; The meeting time is optional.  It enables "at the start of class" release rules and
; <postmeeting> content that appears when the meeting ends.
mon = class 08:00-08:50
wed = lab   13:30-15:20
fri = class 08:00-08:50

[days]
; date = what happens that day (replaces the weekly schedule for that date)
2026-09-07 = holiday
2026-09-08 = monday schedule        ; no directory, so it is shown as a label
2026-10-02 = exam 08:00-09:50
2026-10-14 = lab + class            ; two events on one day
2026-11-26 = holiday
2026-12-18 = final exam             ; content in final-exam/

[combine]
; Add events to the Nth event of a type, wherever it falls (v4 $COMBINE)
; class 3 = lab

[boxes]
; Boxes shown in each calendar day, in this order.
; box = candidate files, first one found wins.
; Rendering follows the extension: .html .md .txt are shown as pages, .pdf .ppt .pptx and
; other files are downloads.  "inline:" pastes the first line of a text file into the box.
title             = index.html, index.md, slides.pdf, slides.pptx
instructor        = instructor.html, instructor.md, instructor.pdf, inline:instructor.txt
reading           = reading.html, reading.md, reading.pdf, inline:reading.txt
notes             = notes.html, notes.md, inline:notes.txt
lab-solution      = labanswers.html, labanswers.md, labanswers.pdf
homework          = homework.html, homework.md, homework.pdf, inline:homework.txt
homework-solution = answers.html, answers.md, answers.pdf, inline:answers.txt

[menus]
; Navbar menus.  type = label [| icon]
class = Classes | apple
lab   = Labs    | knight

[instructor_menus]
; Extra menus when logged in (defaults to [menus])
exam  = Exams   | hourglass

[replace]
; Values for <replace value="..."> tags
classyear   = 27
server      = midn.cs.usna.edu
servershort = midn

[files]
access        = virtual/cal4.access     ; release rules (access-rules.md)
virtual       = virtual/cal4.virtual    ; virtual files
load_pages    = home, policy, resources, syllabus   ; pages allowed with ?load=
allowed_paths = /home/me/shared-notes   ; extra places symlinks may point to

[security]
password_hash = $2y$12$...
; password    = plain-text-password   ; accepted, but the instructor sees a warning
secret        = 9f3c...
session_hours = 12
; lock        = yes                   ; or a file name: locked while that file exists

[display]
start_page            = home        ; home | today (today's lecture, v4 $DEFAULT_TODAYS_LECTURE)
calendar_view         = month       ; month | list | agenda
markdown_menu_levels  = 2           ; heading levels listed in the section menu
heading_warnings      = no          ; warn about old <a name> anchors (v4 $IGNORE_A_TAG_WARNING)
box_pick              = listed      ; listed | first-by-name (v4: the file whose name sorts first wins)

[style]
; Extra stylesheets: for every page, per type, and for the calendar
css          = docs/course.css
css.class    = docs/class.css
css.calendar = docs/calendar.css
js.lab       = docs/lab-helpers.js

[features]
postmeeting = end            ; when <postmeeting> appears: end (of the meeting), a time such as 13:00, or off (always shown)
access_log  = /var/log/cal5/si460.log
legacy_css  = no             ; yes: Bootstrap/Skeleton classes and glyphicons in course pages (compat.css)
legacy_js   =                ; auto (on the pages that use them), or jquery, datatables, chartjs (every page)
debug       = no
```

## Section reference

| Section / key | Type | Default | Notes |
|---|---|---|---|
| `course.number` | text | **required** | `<replace value="course">` |
| `course.name` | text | "" | `<replace value="coursename">` |
| `course.full_name` | text | "" | `<replace value="coursenamefull">` |
| `course.title` | text | `number` | Browser tab title |
| `course.timezone` | IANA zone | server zone | Used for all release rules |
| `semester.start` / `end` | date `YYYY-MM-DD` | **required** | `end` may be in a later year |
| `semester.weekends` | bool | no | |
| `semester.show_from` / `show_until` | date | start / end | Crop the visible calendar |
| `schedule.<weekday>` | `type [HH:MM-HH:MM]` | – | Weekday names or 3-letter abbreviations |
| `days.<date>` | `type [time]`, `a + b`, or `holiday` | – | Overrides the weekly schedule for that date |
| `combine.<type> <n>` | list of types | – | Event-number based (v4 semantics) |
| `boxes.<box>` | ordered list | see minimal course | Key order = display order |
| `menus.<type>` | `label [\| icon]` | `class`, `lab` | Icon: a Cal5 icon name or a v4 glyphicon name (`docs/design/ui.md`, "Icons") |
| `instructor_menus.<type>` | same | `[menus]` | |
| `replace.<name>` | text | – | Built-in values (`event`, `zevent`, `type`, `ctype`, `title`, ...) are always available |
| `files.access` / `virtual` | path | none | v4 formats accepted unchanged |
| `files.load_pages` | list | `home, policy, resources` | Plus `error`, `lock`, and the guide for the instructor |
| `files.allowed_paths` | list | course directory | |
| `security.password_hash` | hash | – | `bin/cal5 hash-password` |
| `security.password` | text | – | Plain text (v4 style); warning shown |
| `security.secret` | text ≥ 32 chars | **required** | The sample value from the v4 shell is refused |
| `security.session_hours` | number | 12 | v4 `$LOGON_TIME`; decimals allowed (1.5) |
| `security.lock` | bool or path | no | |
| `display.start_page` | `home` \| `today` | home | |
| `display.calendar_view` | `month` \| `list` \| `agenda` | month | |
| `display.markdown_menu_levels` | list of 1-6 | 2 | |
| `display.heading_warnings` | bool | no | |
| `display.box_pick` | `listed` \| `first-by-name` | listed | How a box chooses among its candidates: the first one listed that exists, or (v4 rule) the existing file whose name sorts first |
| `display.calendar_boxes` | list | every box | Boxes shown on the calendar, in order; other boxes still exist (for example `title` for the event page) |
| `style.css[.<type>\|.calendar]`, `style.js[.<type>]` | path list | – | |
| `features.postmeeting` | `end` \| `HH:MM` \| `off` | end | `off` = content always shown (v4 without `$REVEALHOUR`) |
| `features.access_log` | path | none | |
| `features.legacy_css` | bool | no | |
| `features.legacy_js` | list of `auto`, `jquery`, `datatables`, `chartjs` | none | `auto`: each library on the pages whose scripts use it; listed libraries on every page (`docs/design/legacy-content.md`) |
| `features.debug` | bool | no | |

Box candidates are tried **in the order listed** (`box_pick = listed`). Old courses that relied on v4's rule ("the
file whose name sorts first wins") keep that rule through the translator (see below).

## Translating a v4 `calendar.php`

`LegacyTranslator` reads the globals a v4 `calendar.php` leaves behind, after it has run, and
builds the same `Config`. It never edits the course's files. `bin/cal5 translate` writes the
result as a commented `calendar.ini.php` for courses that want to switch formats.

### Variable mapping

| v4 variable | Cal5 setting | Translation notes |
|---|---|---|
| `$COURSE` | `course.number` | |
| `$COURSENAME` | `course.name` | |
| `$COURSENAMEFULL` | `course.full_name` | |
| `$PAGE_TITLE` | `course.title` | |
| `$YEAR`, `$MONTH_START`, `$DAY_START` | `semester.start` | `YEAR-MONTH_START-DAY_START` |
| `$MONTH_END` | `semester.end` | Last day of `MONTH_END` in `YEAR` (v4 shows whole months) |
| `$WEEKENDS` | `semester.weekends` | |
| `$HIDE_WEEKS`, `$HIDE_BEGINNING_DAY`, `$HIDE_ENDING_DAY` | `semester.show_from` / `show_until` | Only when `$HIDE_WEEKS === true` |
| `$DOW` | `[schedule]` | Types normalized (`'Final Exam'` → `final-exam`); no meeting times |
| `$OVERRIDE` | `[days]` | `month/day` in `$YEAR` → ISO dates; duplicate keys keep v4's "last wins" |
| `$COMBINE` | `[combine]` | Kept event-number based (exact v4 semantics) |
| `$BOX` | `[boxes]` key order | |
| `$HTML`, `$LINK`, `$PDF`, `$PPT`, `$SRC`, `$MD` | `[boxes]` candidates | `$SRC` entries become `inline:`. Without `$MD`, `.md` twins of `$HTML` are added (calendar-core 4.6 behaviour). Sets `display.box_pick = first-by-name`, which reproduces v4's "first file in sorted order wins". Boxes in the category arrays but not in `$BOX` are kept (not shown on the calendar: `display.calendar_boxes`). If a file name appears in two arrays, the later array wins (v4 order: MD, HTML, LINK, PDF, PPT, SRC) |
| `$NAVBAR_DROPDOWNS` | `[menus]` | Glyphicon names → closest Cal5 icon; unknown names → no icon |
| `$NAVBAR_DROPDOWNS_INSTRUCTOR` | `[instructor_menus]` | |
| `$PAGE_MODIFY` | `[replace]` | |
| `$ACCESS_FILE` | `files.access` | Same file, read in place |
| `$CLASS_FILE` | `files.virtual` | Same file, read in place |
| `$LOAD_PAGES` | `files.load_pages` | Added to the defaults |
| `$ALLOWED_PATHS` | `files.allowed_paths` | |
| `$ADMIN` | `security.password` | Plain text, as in v4 (the instructor sees a "consider password_hash" note) |
| `$SECRET` | `security.secret` | Same value, so file keys and links are unchanged. The published sample secret is refused, as in calendar-core 4.5 |
| `$LOGON_TIME` | `security.session_hours` | Seconds ÷ 3600 |
| `$LOCK` | `security.lock` | `true` or a file path |
| `$DEFAULT_TODAYS_LECTURE` | `display.start_page = today` | |
| `$CALENDAR_TOOL` | `display.calendar_view` | `calendar_display` → `month`, `calendar_display_alt` → `list`, `calendar_display_dateless` → `agenda` |
| `$MARKDOWN_MENU_LEVELS` | `display.markdown_menu_levels` | |
| `$IGNORE_A_TAG_WARNING` | `display.heading_warnings = no` | |
| `$CUSTOMCSS` | `style.css.<type>` | |
| `$CUSTOMJS` | `style.js.<type>` | |
| `$CUSTOMCSS_CAL` | `style.css.calendar` | |
| `$REVEALHOUR` | `features.postmeeting = HH:00` | Without `$REVEALHOUR`, `postmeeting = off`: v4 leaves `<postmeeting>` content visible |
| `$ACCESS_LOG` | `features.access_log` | |
| `$DEBUG_MODE` | `features.debug` | |
| *(any v4 config)* | `features.legacy_css = yes`, `features.legacy_js = auto` | v4 loaded Bootstrap, Skeleton, jQuery, DataTables and Chart.js on every page. Translated courses get the compatibility styles, and each library on the pages that use it, so their pages work unchanged. `bin/cal5 audit` shows what a course uses |
| `$NAVBAR`, `$NAVBAR_TITLE`, `$NAVBAR_TITLE_URL` | – | Only used by v4's unrelated `template_navbar.php`; ignored |

Unknown upper-case globals are listed in an instructor-only notice, so a typo or a
professor's custom variable is visible rather than silently ignored.

### Behaviour notes

- **Same secret, same keys.** File keys stay `sha1(secret . type . dir . file)`, so every
  existing key link, bookmark and `<showlink>` keeps working.
- **No meeting times.** v4 configs have no meeting times, so rules that refer to meeting
  times (`start`, `end`) fall back to midnight on the event's date. That is exactly v4's
  behaviour.
- **Round trip.** `translate` followed by loading the written `calendar.ini.php` must give an
  identical `Config`. This is a Milestone 2 test.

## `bin/cal5` commands (Milestone 2)

```
php calendar/bin/cal5 translate [calendar.php] [-o calendar.ini.php]
php calendar/bin/cal5 check                 # validate and print the resolved calendar
php calendar/bin/cal5 hash-password         # prompts for a password, prints password_hash
```
