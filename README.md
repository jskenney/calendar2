# Calendar 2 (Cal5)

A modern rebuild of [calendar-core](https://github.com/jskenney/calendar-core): a PHP
system that builds a course website and calendar from directories of class material
(`class/`, `lab/`, `exam/`, ...).

Cal5 is designed as a **drop-in replacement** for Calendar v4: existing courses keep
their `calendar.php`, access files and content, and work unchanged.

This repository is being built one milestone at a time. See `MILESTONES.md` (on the
`milestones` branch until it is merged) for the plan.

## Status

Milestone 2 (configuration): Cal5 reads `calendar.ini.php` or translates a v4 `calendar.php`,
validates it, and shows a summary page. It does not serve course content yet. For a course,
run these from its directory:

```bash
php calendar/bin/cal5 check        # validate and summarise the configuration
php calendar/bin/cal5 translate    # write calendar.ini.php from a v4 calendar.php
php calendar/bin/cal5 hash-password
```

## Development

```bash
php tests/run.php                                        # tests (PHP 8.3, no dependencies)
tests/compat/fetch-v4.sh                                 # fetch v4 for the compatibility runner
php tests/compat/compat.php check --impl=cal5 --report   # Cal5 progress against v4
```

See `CONTRIBUTING.md` for the workflow and coding standards.
