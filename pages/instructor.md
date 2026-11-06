# Instructor guide

Only you see this page. Cal5 builds the course site from the directories of class material
(`class/`, `lab/`, ...) and the course configuration.

## Where things are

| What | Where |
|---|---|
| Configuration | `calendar.ini.php` (or a v4 `calendar.php`) |
| Release rules | <replace value="access_file"> |
| Virtual files | <replace value="class_file"> |
| Home, policy and resources pages | `home.html`, `policy.html`, `resources.html` (or `.md`) in the course directory |

## Seeing what students see

- The **day numbers** in the month view, and **Files** on an event page, show every file of
  an event: its box, whether students can see it now, and when it opens or locks again.
- **View as a student** on that page shows the event exactly as students see it now.
- The calendar marks material that is not visible yet with **opens …**, and material that is
  closed or never shown with **hidden**.

## Release rules

Each line of <replace value="access_file"> is a target and when it opens:

```
class/title                 start-15m             # 15 minutes before each class meeting
class/homework-solution     +7 17:00              # a week after class at 5 PM
class/quiz.html             start until end       # only during the class meeting
exam_2/notes                2026-11-06 08:00 until 2026-11-06 09:50
class_4/slides              never
```

Every v4 form still works (`+2`, `-monday`, `9 14`, `1 1 1999`, `99 99 9999`).

## Tags in course pages

| Tag | Effect |
|---|---|
| `<inst>...</inst>` | Only you see it |
| `<student>...</student>` | Hidden until **Show answers** |
| `<postmeeting>...</postmeeting>` | Shown to students after the meeting |
| `<lock code="x">...</lock>` | Shown after the code is entered |
| `<inject src="file">`, `<codeinject src="file">` | Insert another file of the event |
| `<replace value="name">` | A value from `[replace]` (or `course`, `title`, `event`, ...) |
| `<url value="name" default="text">` | A value from the address, remembered |
| `<host server="name">...</host>` | Only on that server |
| `<ace ...>...</ace>` | A code box |
| `<calphp src="file.php">` | Runs a PHP file of the event |

## Commands

Run these in the course directory:

```
php calendar/bin/cal5 check        # check the configuration
php calendar/bin/cal5 events       # every event: date, directory, boxes
php calendar/bin/cal5 access       # what students can see now (--at "2026-09-14 13:30")
php calendar/bin/cal5 audit        # what the pages use from v4's Bootstrap and jQuery
php calendar/bin/cal5 translate    # write calendar.ini.php from calendar.php
php calendar/bin/cal5 hash-password
```
