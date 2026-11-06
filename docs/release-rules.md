# Release rules

The access file (`virtual/cal4.access` in most courses; `[files] access` in
`calendar.ini.php`, `$ACCESS_FILE` in `calendar.php`) decides when students can see each
file. You always see everything. Each line names a **target** and **when it opens**:

```
class/homework-solution     +7 17:00
```

Blank lines and lines starting with `#` are ignored. Separate the two parts with spaces, or
with a tab when the target contains spaces.

## Targets

For a file `F` of event `N` of type `T` (for example `answers.html` of class 3), in box `C`
(for example `homework-solution`), the first of these targets found in the file decides:

| Target | Example | Means |
|---|---|---|
| `T_N/F` | `class_3/answers.html` | That file of that event |
| `F` | `answers.html` | That file name, in any event |
| `T_N/C` | `class_3/homework` | That box of that event |
| `T/C` | `class/homework-solution` | That box of every event of the type |
| `T_N` | `class_3` | Everything in that event |
| `T/*` | `class/*` | Every file of the type |
| `T_N/all`, `T_N/*` | `class_3/all` | Everything in that event |
| `*/C` | `*/instructor` | That box, everywhere |
| `T` | `lab` | Everything of the type |
| `*` | `*` | Everything |

A file no rule names is visible. If the same target appears twice, the later line wins.
Events that are not on the calendar are never shown to students.

## When

| Write | Opens | Example |
|---|---|---|
| `always` / `never` | always / never | `class_4/slides never` |
| `+N` / `-N` | N days after / before the event's date, at 00:00 | `+7`, `-2` |
| `+monday` / `-monday` | the next Monday after / the Monday on or before the event's date | `lab +wednesday` |
| `M D` | on that month and day of the course year | `9 14` |
| `M D Y` | on that date (`1 1 1999` always, `99 99 9999` never) | `10 2 2026` |
| `YYYY-MM-DD HH:MM` | at that moment (the time is optional) | `2026-11-06 08:00` |
| `start` / `end` | when the event's meeting starts / ends (from `[schedule]` / `[days]`, e.g. `mon = class 08:00-08:50`); without meeting times, 00:00 on its date | `start-15m` |

**Offsets** can follow `start`, `end` or a day offset, and are applied left to right:
`+N` or `+Nd` (days), `+Nw` (weeks), `+Nh` (hours), `+Nm` (minutes), or a weekday.
**A time of day** at the end sets the clock time after the offsets: `+7 17:00` is 5 PM seven
days after the event. Day and week offsets keep the clock time across daylight-saving
changes; hours and minutes are exact.

## Locking material again

Add `until` and a second time (measured from the event, like the first), or `for` and a
duration (measured from the opening):

```
class/quiz.html             start until end            # only during the class meeting
exam_2/notes                2026-11-06 08:00 until 2026-11-06 09:50
lab                         -monday 08:00 until +14 23:59
exam_1/practice.pdf         2026-10-02 12:00 for 48h
```

Visible means *opened and not yet locked*. After it locks, students no longer see it and you
see it marked "hidden".

## Recipes

```
# Slides 15 minutes before each class meeting
class/title                 start-15m

# Homework solutions: a week after class at 5 PM, for two weeks
class/homework-solution     +7 17:00 until +21 23:59

# Lab solutions the day after the lab ends
lab/lab-solution            end+1d

# The final exam's notes only during the exam
final-exam/notes            2026-12-15 08:00 until 2026-12-15 11:00

# Never show the instructor notes
*/instructor                never
```

Times are in the course's time zone (`[course] timezone`, otherwise the server's).

## Checking

```bash
php calendar/bin/cal5 access                          # every file: visible or not now, its window and rule
php calendar/bin/cal5 access --at "2026-09-14 13:30"  # as students will see it then
```

On the site, each event's **status page** (the day numbers in the month view) shows the
same, and **View as a student** shows the event as students see it now. A line Cal5 can not
read is ignored and listed, with its line number, in a note only you see.
