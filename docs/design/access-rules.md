# Access (release) rules

The access file (`files.access`, v4 `$ACCESS_FILE`, usually `virtual/cal4.access`) decides
when students can see each file. Cal5 reads the **same file in the same format**. Every v4
line keeps its exact meaning, and the syntax is extended with:

- release at a specific **minute**, in the course time zone;
- **ranges** that lock material again automatically (`... until ...`);
- times relative to the **meeting time** of the event (`start`, `end`).

Instructors always see everything. Their view shows each file's window ("opens Mon 14 Sep
13:30 · locks Fri 18 Sep 23:59") and its current state.

## v4 behaviour (kept exactly)

### Line formats

| Line | Meaning |
|---|---|
| `target M D` | Opens on month `M`, day `D` of the course year (`$YEAR`). |
| `target M D Y` | Opens on that date. `1 1 1999` = always visible; `99 99 9999` = never visible. |
| `target +N` / `target -N` | Opens `N` days after / before the event's calendar date. `+0` = on the day. |
| `target +monday` / `target -monday` | Opens on the next Monday after the event date / on the Monday on or before it. Full names or 3-letter abbreviations. |

Dates open at **00:00** (v4 compared whole days). Blank lines and `#` comments are ignored
(calendar-core 4.5). Lines are tab or whitespace separated. Use tabs for paths containing
spaces.

### Targets and precedence

For a file `F` (its path inside the event directory) in event `N` of type `T`, with box
(category) `C`, Cal5 checks these targets **in this order**, and the first one present in the
file decides:

1. `T_N/F` (e.g. `class_3/answers.html`)
2. `F` (any event)
3. `T_N/C` (e.g. `class_3/homework`)
4. `T/C` (e.g. `class/homework-solution`)
5. `T_N` (the whole event)
6. `T/*`
7. `T_N/all`
8. `T_N/*`
9. `*/C`
10. `T` (the whole type)
11. `*`

If the same target appears twice, the later line wins (v4 behaviour). If no target matches,
the file is visible. Events that are not on the calendar are never shown to students
(calendar-core 4.5 behaviour). Rules are checked whenever a file is served, not only when the
calendar is drawn.

## Extended syntax (Cal5)

```
line   := target  open  [ "until" close | "for" duration ]
open   := when
close  := when
when   := "always" | "never"
        | M D [Y]                          ; v4 form: integers
        | YYYY-MM-DD [time]                ; absolute date, optional time
        | anchor [offset ...] [time]       ; relative to the event
anchor := (nothing: the event's date at 00:00) | "start" | "end"
offset := ("+" | "-") ( N | N"d" | N"w" | N"h" | N"m" | weekday )
time   := HH:MM (24-hour)
duration := N"d" | N"w" | N"h" | N"m" (combinations allowed: 1d12h)
```

- **`start` / `end`** are the meeting's start and end times on the event's date, from
  `[schedule]` / `[days]` (for example `mon = class 08:00-08:50`). Without meeting times
  (every v4 config) they mean 00:00 on the event date.
- **Offsets** are applied left to right. `N` with no unit means days (v4). `d` and `w` move
  calendar days, so `+1d` keeps the clock time across a daylight-saving change. `h` and `m`
  are exact durations.
- **A trailing `HH:MM`** sets the time of day after the offsets: `+7 17:00` = 17:00 seven
  days after the event.
- **`until`** must be later than the open time. A relative `until` is measured from the
  event, not from the open time. `for` is measured from the open time.
- **Visible** means `open <= now < close`. After `close` the file is locked again: students no
  longer see it, and the instructor sees it marked as closed.
- **Time zone:** `course.timezone`, or the server's time zone for v4 configs (v4 behaviour).
- **Errors:** a bad line is ignored, as in calendar-core 4.5, and listed on the
  instructor-only banner with its line number.

### Recipes

```
# Slides open 15 minutes before each class meeting
class/title                 start-15m

# Homework solutions: one week after class at 5 PM, for the rest of the semester
class/homework-solution     +7 17:00

# A quiz only visible during the class meeting
class/quiz.html             start until end

# Exam notes: only during the exam (absolute times)
exam_2/notes                2026-11-06 08:00 until 2026-11-06 09:50

# Lab handout: the Monday before at 08:00, locked again two weeks after the lab
lab                         -monday 08:00 until +14 23:59

# Practice exam: open for 48 hours starting Friday at noon
exam_1/practice.pdf         2026-10-02 12:00 for 48h

# Always / never (v4 sentinel dates still work too)
class/notes                 always
class/instructor            never
```

## `<postmeeting>`

Content inside `<postmeeting>...</postmeeting>` is shown to students once the event's meeting
is over:

- `features.postmeeting = end` (the default for new configs): at the meeting's end time.
- `features.postmeeting = 13:00`: at that time on the event's date. v4's `$REVEALHOUR = 13`
  translates to this.

## Implementation notes (Milestone 4)

- `AccessRuleParser` produces `Rule { target, open: When, close: ?When, line }`.
  `AccessPolicy::window(File, Event)` returns `[open, close]` as `DateTimeImmutable`, and
  `isVisible(File, Event, now)` decides.
- The clock is injected (`Clock` interface), so tests can check minute boundaries
  deterministically.
- The compatibility suite runs every v4 rule form against both v4 and Cal5 at fixed dates.
  Unit tests cover the new forms: minute boundaries, the end of a range, DST changes
  (America/New_York in March and November), a year boundary, and `start`/`end` without
  meeting times.
