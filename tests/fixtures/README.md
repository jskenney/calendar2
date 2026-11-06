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

| Fixture | Contents |
|---|---|
| `sample` | The v4 sample course from the `calendar` repository, using its 2018 `calendar.php`. Content is verbatim, apart from `SAMPLE-*` marker lines and a fixed secret (calendar-core 4.5+ refuses the published sample secret). |
| `core-tests` | The course built by calendar-core 4.6's `tests/run_tests.php`, including its variant entry files. |
| `kitchen-sink` | Sets every v4 variable and uses every tag, box type and release-rule form. Every piece of content carries a `KS-*` marker. |

All semesters are in the past (2018), and release rules use either past dates or dates that
never arrive (`12 31 2099`, `99 99 9999`). v4 has no way to fake the clock, so this keeps
the recorded v4 results identical no matter when the tests run. Time-dependent behaviour is
tested against Cal5's injectable clock from milestone 4 on.

After changing a fixture, re-record the expected v4 results (see `tests/compat/README.md`).
`CompatHarnessTest::testExpectedResultsAreUpToDate` fails until you do.
