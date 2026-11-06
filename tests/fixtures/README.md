# Fixture courses

Each directory is a complete course used by the tests and by the compatibility runner.
`fixture.json` describes it:

| Field | Meaning |
|---|---|
| `description` | What the course is for |
| `entry` | Main entry file (`calendar.php` for v4 courses) |
| `secret` | `$SECRET`, used to compute v4 file keys |
| `password` | Instructor password, used to log in |
| `types` | Component directories (and schedule types) to request events for |
| `markers` | Content markers: `{"pattern": "regex"}` and/or `{"list": [...]}` |
| `pages` / `views` / `searches` | Values for `?load=`, `?show=` and `?search=` requests |
| `extra_requests` | Extra queries per entry file (sent after the generated ones) |
| `outside_files`, `links` | Files created outside the course, and symlinks created at install time (`@outside/...`) |
| `virtual_file` | Virtual file path (default `virtual/cal4.virtual`) |
| `known_v4_warnings` | Texts of PHP warnings that v4 prints for this course (v4 bugs); ignored when comparing |

| Fixture | Contents |
|---|---|
| `sample` | The v4 sample course from the `calendar` repository, using its 2018 `calendar.php`. Content is verbatim, apart from `SAMPLE-*` marker lines and a fixed secret (calendar-core 4.5+ refuses the published sample secret). |
| `core-tests` | The course built by calendar-core 4.6's `tests/run_tests.php`, including its variant entry files. |
| `kitchen-sink` | Sets every v4 variable and uses every tag, box type and release-rule form. Every piece of content carries a `KS-*` marker. |
| `access-rules` | Every v4 release-rule form (including impossible dates, sentinels, duplicates and a too-long day count) and every step of the rule precedence, each arranged so a more specific target decides the opposite of a less specific one. Outcomes are fixed until January 2046. |
| `edge-cases` | Schedule and scanner edge cases: chained `$COMBINE` rules and one for a type without material, overrides before the start / on a Sunday / on an invalid date, `04.` among `1.`-`10.`, an empty event directory, a type directory without events, files named `9` and `10`, hidden and symlinked subdirectories, a link outside the course, and virtual files that replace a file, miss an event or miss their file. |

All semesters are in the past (2018-2019), and release rules use either past dates or dates far
in the future (`12 31 2099`, `99 99 9999`, `+9999` days). v4 has no way to fake the clock, so this keeps
the recorded v4 results identical no matter when the tests run. Time-dependent behaviour is
tested against Cal5's injectable clock from milestone 4 on.

After changing a fixture, re-record the expected v4 results (see `tests/compat/README.md`).
`CompatHarnessTest::testExpectedResultsAreUpToDate` fails until you do.
