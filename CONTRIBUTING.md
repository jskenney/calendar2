# Contributing to Cal5

## Workflow

Cal5 is built one milestone at a time (see `MILESTONES.md`). Each milestone has its own branch
(`milestone-NN-short-name`) and pull request. A milestone is merged only when CI is green and
its acceptance criteria are met.

## Running the tests

```bash
php tests/run.php            # everything
php tests/run.php unit       # unit tests only
php tests/run.php http       # end-to-end tests (php -S + fixture courses)
php tests/run.php Skeleton   # test files whose name contains "Skeleton"
```

Only the PHP 8.3 CLI is needed (extensions `dom` and `mbstring`). Any PHP warning, notice or
deprecation fails a test, both in test code and in pages served by Cal5.

### Compatibility with v4

```bash
tests/compat/fetch-v4.sh                                # calendar-core at the pinned commit -> .v4/
php tests/compat/compat.php check --impl=v4 --v4=.v4    # v4 still matches the recorded results
php tests/compat/compat.php check --impl=cal5 --report  # how much of v4's behaviour Cal5 matches
php tests/compat/compat.php check --impl=cal5           # strict: lists every difference
```

See `tests/compat/README.md` for how the comparison works and how to re-record results.

### Snapshots

`tests/expected/config/<fixture>.json` holds the translated configuration of each fixture
course. After an intended change to the translation, regenerate the snapshots and review the
diff:

```bash
CAL5_UPDATE_SNAPSHOTS=1 php tests/run.php ConfigCommands
```

## Coding standards

- `declare(strict_types=1);` in every PHP file under `src/` and `tests/`.
- Namespace `Cal5\` maps to `src/` (`src/autoload.php`); no Composer.
- Classes are `final` unless they are designed for extension. Use typed properties,
  `readonly` where possible, and constructor promotion.
- No global variables, except in `calendar_main.php`, which collects a v4 course's settings.
- Escape all output with `Cal5\Support\Html::escape()` (or the template helper that wraps it).
- Read request input only through the request class (from milestone 2 on), never `$_REQUEST`
  directly.
- No new runtime dependencies. Browser libraries are vendored under `public/vendor/`, with
  their version and licence noted.
- 4-space indentation, PSR-12 layout.
- Every behaviour change comes with a test. Anything that affects what users of a v4 course
  see must also be covered by the compatibility suite.
