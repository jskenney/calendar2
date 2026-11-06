# Calendar 2 (Cal5)

A modern rebuild of [calendar-core](https://github.com/jskenney/calendar-core): a PHP
system that builds a course website and calendar from directories of class material
(`class/`, `lab/`, `exam/`, ...).

Cal5 is designed as a **drop-in replacement** for Calendar v4: existing courses keep
their `calendar.php`, access files and content, and work unchanged.

This repository is being built one milestone at a time. See `MILESTONES.md` (on the
`milestones` branch until it is merged) for the plan.

## Status

Milestone 1 (skeleton and test harness): Cal5 installs and detects a course's configuration
(`calendar.ini.php`, or a v4 `calendar.php`), but does not serve course content yet.

## Development

```bash
php tests/run.php                                        # tests (PHP 8.3, no dependencies)
tests/compat/fetch-v4.sh                                 # fetch v4 for the compatibility runner
php tests/compat/compat.php check --impl=cal5 --report   # Cal5 progress against v4
```

See `CONTRIBUTING.md` for the workflow and coding standards.
