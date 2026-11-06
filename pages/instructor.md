# Instructor guide

Only you see this page. Cal5 builds the course site from the directories of class material
and the course configuration: there is nothing to publish. Add a file and it is on the site;
the release rules decide when students see it.

## How a course is laid out

| What | Where |
|---|---|
| Configuration | `calendar.ini.php`, or a v4 `calendar.php` |
| Release rules | <replace value="access_file"> |
| Virtual files | <replace value="class_file"> |
| Home, policy and resources pages | `home.html`, `policy.html`, `resources.html` (or `.md`) in the course directory |
| Material | one directory per event type (`class/`, `lab/`, `exam/`, ...), one directory per event inside it |

Event directories are numbered and named: `class/01.Introduction`, `class/02.Vectors`,
`lab/01.Setup`. The number orders them (1, 2, ... 10, 11), the name after the first `.` is
the title in menus. The schedule puts them on the calendar in order: the first `class` day
of the semester gets `class/01...`, the next one `class/02...`, and so on. A day in `[days]`
replaces the weekly schedule for that date (holidays, exams, a Monday schedule on a Tuesday).

## Boxes

Each event's files fill **boxes**, shown on the calendar in order: the title (the event's
page), slides, homework, solutions, ... The configuration lists, for each box, the file
names that can fill it, for example:

```
title             = index.html, index.md, slides.pdf
homework          = homework.html, homework.md, homework.pdf
homework-solution = answers.html, answers.md
reading           = inline:reading.txt        ; the text is shown in the calendar itself
```

Rendering follows the extension: `.html`, `.md` and `.txt` are pages (`.htm` pages are shown
without the site's header), `.pdf` opens in the browser, `.ppt`, `.pptx` and anything else
download. Files that are not in a box are still part of the event: link to them from a page
(`<a href="data.zip">`) and Cal5 makes the link work.

## Seeing what students see

- **The day numbers** in the month view, **Files** on an event page, and *Release status of
  these files* open the event's **status page**: every file, its box, whether students can
  see it now, and when it opens or locks again (with the rule and line that decided).
- **View as a student** on that page shows the event exactly as a student sees it now.
- The calendar marks material that is not visible yet with **opens …**, and material that
  is closed or never shown with **hidden**.
- From the command line, `php calendar/bin/cal5 access --at "2026-09-14 13:30"` lists what
  students will see at any moment.

## Release rules

Each line of <replace value="access_file"> is a target and when it opens:

```
class/title                 start-15m             # 15 minutes before each class meeting
class/homework-solution     +7 17:00              # a week after class at 5 PM
class/quiz.html             start until end       # only during the class meeting
exam_2/notes                2026-11-06 08:00 until 2026-11-06 09:50
lab                         -monday 08:00 until +14 23:59
*/instructor                never
```

Targets go from specific to general: `class_3/answers.html`, `answers.html`,
`class_3/homework`, `class/homework`, `class_3`, `class/*`, `*/homework`, `class`, `*`; the
first one found decides. Times: `always`, `never`, `+N`/`-N` days, `+monday`, a date
`2026-11-06 08:00`, or `start`/`end` of the meeting, with offsets (`+2h`, `-15m`, `+1w`) and
a time of day (`+7 17:00`). `until` locks material again. Every v4 form still works (`9 14`,
`1 1 1999`, `99 99 9999`). The full reference is `docs/release-rules.md`.

## Tags in course pages

| Tag | Effect |
|---|---|
| `<inst>...</inst>` | Only you see it |
| `<student>...</student>` | Hidden until students press **Show answers** (not a security control) |
| `<postmeeting>...</postmeeting>` | Shown to students once the meeting is over |
| `<lock code="x">...</lock>` | Shown after the code is entered (remembered for the visit) |
| `<inject src="file">`, `<codeinject src="file">` | Insert another file of the event (Markdown is converted; `codeinject` shows code) |
| `<replace value="name">` | A value from `[replace]`, or `course`, `coursename`, `title`, `event`, `type`, ... |
| `<url value="name" default="text">` | A value from the address (`?name=...`), remembered |
| `<host server="name">...</host>` | Only when the site is served by that server |
| `<showlink src="file">` | The address of a file of the event |
| `<ace name="x" mode="python" height="80px">...</ace>` | A code box |
| `<calphp src="file.php">` | Runs a PHP file of the event and shows its output |

Headings with an `id` (`<h2 id="part-1">`, or `##` headings in Markdown) make the
**Sections** menu. Math between `\(` and `\)` (inline) or `\[` and `\]` (display) is drawn
by MathJax; `<pre><code>` blocks are highlighted.

## Virtual files

<replace value="class_file"> puts a file into an event without copying it. Each line is the
name in the event, the real file, the type and the event number:

```
answers.html    shared/answers03.html    class    3
```

## Pages for the whole course

`?load=home`, `?load=policy` and `?load=resources` show `home.html`, `policy.html` and
`resources.html` (or `.md`, or `.pdf`). Other pages must be listed (`[files] load_pages`,
v4 `$LOAD_PAGES`). Search looks through the event pages students can see; a page defines a
keyword with `<a name="keyword"></a>`.

## Locking the site

`[security] lock = yes` (v4 `$LOCK = true`) shows students only a lock page (your
`lock.html`, if you write one); `lock = some-file` locks the site while that file exists.
You still see everything.

## Commands

Run these in the course directory:

```
php calendar/bin/cal5 check        # check the configuration
php calendar/bin/cal5 events       # every event: date, directory, boxes
php calendar/bin/cal5 access       # what students can see now (--at "2026-09-14 13:30")
php calendar/bin/cal5 audit        # what the pages use from v4's Bootstrap and jQuery
php calendar/bin/cal5 compare      # a v4 course: the same pages under v4 and Cal5?
php calendar/bin/cal5 translate    # write calendar.ini.php from calendar.php
php calendar/bin/cal5 hash-password
```
