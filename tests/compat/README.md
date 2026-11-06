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

In milestone 1, Cal5 only shows an "installed" page, so `check --impl=cal5` fails by design.
CI runs it with `--report` to print progress. Later milestones make more fixtures pass.
`check --impl=cal5` becomes a required CI step by milestone 9.
