# Calendar 5 (Cal5)

Cal5 builds a course website and calendar from directories of class material (`class/`,
`lab/`, `exam/`, ...). Add a file to an event's directory and it is on the site; release
rules decide, to the minute, when students can see it.

Cal5 is the successor of [calendar-core](https://github.com/jskenney/calendar-core) (Calendar
v4) and a **drop-in replacement** for it: a v4 course keeps its `calendar.php`, its access and
virtual files and all of its content, and works unchanged. Every request of every test course
gives what v4 gives (the compatibility suite compares 602 of them), and `bin/cal5 compare`
checks your own courses page by page before you switch.

What is new: a modern look (light and dark, phones, print), month, list and agenda calendars,
release times to the minute with automatic re-locking and rules relative to class meetings,
an instructor status page and "view as a student", password hashes and login rate limiting,
search, and a plain `calendar.ini.php` configuration. Course pages written for v4's Bootstrap,
Skeleton, jQuery, DataTables or Chart.js keep working.

## Requirements

PHP 8.3 or later with `dom` and `mbstring`, on Apache or nginx. No database, no Composer, no
build step.

## A new course

```bash
mkdir SI460 && cd SI460
wget https://raw.githubusercontent.com/jskenney/calendar2/main/installer.sh
bash installer.sh
```

The installer gets Cal5 (`calendar-core/`, linked as `calendar`), asks for the course, the
semester, the class days and the instructor password, and writes `calendar.ini.php` with a
random secret and a hashed password, starter pages, a first class and example release rules.

## Moving a v4 course

```bash
git clone https://github.com/jskenney/calendar2.git /tmp/cal5
cd SI460
php /tmp/cal5/bin/cal5 compare               # the course under v4 and under Cal5, page by page
mv calendar-core calendar-core-v4            # keep v4 for rolling back
git clone https://github.com/jskenney/calendar2.git calendar-core
sudo systemctl reload php8.3-fpm
```

The full guide, including submodule courses, rolling back and `calendar.ini.php`:
[docs/migration.md](docs/migration.md).

## Day to day

Run these in the course directory:

```bash
php calendar/bin/cal5 check        # check the configuration
php calendar/bin/cal5 events       # every event: date, directory, and the file in each box
php calendar/bin/cal5 access       # what students can see now (or --at "2026-09-14 13:30")
php calendar/bin/cal5 audit        # what the pages use from v4's Bootstrap and jQuery
php calendar/bin/cal5 compare      # a v4 course: the same pages under v4 and Cal5?
php calendar/bin/cal5 translate    # write calendar.ini.php from a v4 calendar.php
php calendar/bin/cal5 hash-password
```

On the site, the instructor guide is at `?load=instructor` (log in first).

## Documentation

| | |
|---|---|
| [docs/migration.md](docs/migration.md) | Moving a v4 course to Cal5, and back |
| [docs/release-rules.md](docs/release-rules.md) | When students see each file |
| [docs/design/configuration.md](docs/design/configuration.md) | Every setting of `calendar.ini.php`, and how v4 settings translate |
| [docs/design/compatibility.md](docs/design/compatibility.md) | What a v4 course relies on, and the intended differences |
| [docs/design/routing.md](docs/design/routing.md) | Every URL form |
| [docs/design/ui.md](docs/design/ui.md), [legacy-content.md](docs/design/legacy-content.md) | The interface, and course pages written for v4's front end |
| [docs/design/architecture.md](docs/design/architecture.md), [access-rules.md](docs/design/access-rules.md) | How Cal5 is built |
| [CHANGELOG.md](CHANGELOG.md) | Releases |

## Development

```bash
php tests/run.php                                        # unit and end-to-end tests
tests/compat/fetch-v4.sh                                 # v4 at the pinned commit, for the comparisons
php tests/compat/compat.php check --impl=cal5            # every request of every fixture course: must match v4
node tests/browser/check.js [--screenshots=DIR]          # pages in Chromium: no errors, no Bootstrap, no other hosts
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Cal5 was built in ten milestones
([MILESTONES.md](MILESTONES.md)), each with its own pull request.

## Licence

See [LICENSE](LICENSE). Bundled libraries and their licences: `public/vendor/README.md` and
`docs/licenses/`.
