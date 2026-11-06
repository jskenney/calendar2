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
the events on every calendar day. Since milestone 4 it also records, for every file, whether a
student may see it (v4's own `get_file_security()`, called as v4 calls it when serving).
Cal5's `Course` and `AccessPolicy` are reduced to the same form.
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
php tests/compat/compat.php check --impl=cal5 --scope=events   # event pages and files only
php tests/compat/compat.php check --impl=cal5 --fixture=sample
```

`expected/<fixture>.json` holds the recorded v4 results. Re-record them only when a fixture
changes, or when `V4_COMMIT` is moved to a newer calendar-core, and review the diff in the PR.

## Status by milestone

- `check-structure --impl=cal5` has been required since milestone 3.
- `check --impl=cal5 --scope=events` has been required since milestone 6: every event page
  and file (`?type=T&event=N[&key=K]` with a valid type and number) must match v4. The
  fixture's `error_entries` (entries whose configuration v4 refuses) are left out.
- The other pages (home and load pages, calendar views, search, the file selector) arrive in
  milestones 7-9. CI runs the full check with `--report` to print progress, and it becomes
  required by milestone 9.

Text downloads (`text/*`) also record which markers they contain, so that `.txt` files, which
Cal5 shows as pages, can be compared by their text. `IntendedDifferences.php` holds the
differences listed in `docs/design/compatibility.md` and is the only place they are excused.
