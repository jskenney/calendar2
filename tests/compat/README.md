# Compatibility runner

The runner sends the **same requests** to v4 (calendar-core, at the commit in `V4_COMMIT`) and
to Cal5, then compares **what users see**. Layout and styling are ignored on purpose.

## Requests

`Requests.php` generates the requests from each fixture's files (not from either
implementation), for two users: a student, and the instructor (logged in with v4's password
form). They cover:

- the start page, `?load=` pages, `?show=` views, and searches;
- every event of every type, plus one past the last;
- every file of every event, by its v4 key (`sha1(secret . type . dir . file)`), including
  hidden files, install-time symlinks and virtual files;
- the fixture's `extra_requests`: lock codes, `<url>` values, invalid parameters, variant
  entry files.

## Observations

`Observer.php` reduces each response to:

| Pages | Downloads |
|---|---|
| status code | status code |
| visible content markers | content type |
| keyed file links (`key`, `type`, `event` only) | inline or attachment |
| event links (`type`, `event`) | SHA-1 of the body |
| PHP errors printed in the page | |

## Course structure (milestone 3)

Pages are not the only thing compared. `Structure.php` reads what v4 **computes** for a course
by running it once from the command line as the instructor (`v4-probe.php`): every event's
directory, name and calendar date, the file that fills each box, each file's box and key, and
the events on every calendar day. Cal5's `Course` is reduced to the same form.
`expected/<fixture>.structure.json` holds v4's result and is recorded together with the
requests.

```bash
php tests/compat/compat.php check-structure --impl=v4 --v4=.v4   # v4 still matches its recording
php tests/compat/compat.php check-structure --impl=cal5          # Cal5 matches v4 (required in CI)
```

PHP errors are compared one way only: the implementation under test must print none. Warnings
v4 itself prints for a fixture (v4 bugs) are listed in its `fixture.json` as
`known_v4_warnings`, and file paths are removed from recorded errors.

## Commands

```bash
tests/compat/fetch-v4.sh                                  # v4 -> .v4/
php tests/compat/compat.php record --v4=.v4 --label="calendar-core <sha>"
php tests/compat/compat.php check --impl=v4 --v4=.v4      # must always pass
php tests/compat/compat.php check --impl=cal5 [--report]  # Cal5 vs the recorded v4 results
php tests/compat/compat.php check --impl=cal5 --fixture=sample
```

`expected/<fixture>.json` holds the recorded v4 results. Re-record them only when a fixture
changes, or when `V4_COMMIT` is moved to a newer calendar-core, and review the diff in the PR.

## Status by milestone

Cal5 does not serve pages yet (milestone 6), so `check --impl=cal5` (pages) fails by design.
CI runs it with `--report` to print progress, and it becomes a required step by milestone 9.
`check-structure --impl=cal5` has been required since milestone 3.
