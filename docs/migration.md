# Moving a course to Cal5

Cal5 is a drop-in replacement for Calendar v4 (calendar-core). A v4 course keeps its
`calendar.php`, its access and virtual files and all of its content; only the code behind
the `calendar` link changes. Rolling back is the same step in reverse.

## Before you switch

Cal5 needs **PHP 8.3** or later with the `dom` and `mbstring` extensions (v4's `calendar`
extension is no longer needed).

Get a copy of Cal5 anywhere (the course is not touched yet), then run these checks from
the course directory:

```bash
git clone https://github.com/jskenney/calendar2.git /tmp/cal5
cd SI460
```

1. **Check the configuration.** Cal5 translates `calendar.php` every time it runs:

   ```bash
   php /tmp/cal5/bin/cal5 check
   ```

   It shows the semester, the schedule and the boxes, and any warnings (for example a weak
   `$SECRET`). Errors would stop the course from showing content, as in v4.

2. **Check what the course's pages use** from v4's Bootstrap and jQuery:

   ```bash
   php /tmp/cal5/bin/cal5 audit
   ```

   Anything under "Needs attention" (Bootstrap's JavaScript, Font Awesome icons, links to
   v4's own files such as `calendar/bootstrap/...`) needs a small change to the page.

3. **Compare the course under v4 and Cal5**, page by page:

   ```bash
   php /tmp/cal5/bin/cal5 compare
   ```

   This serves two copies of the course (the course itself is not changed), one with v4
   (the `calendar/` it uses now) and one with Cal5, follows every page and file link as a
   student and as the instructor, and lists every difference. "No differences" means
   students and you will see the same material, links and files. The differences that are
   intended (the new look, `.txt` files shown as pages, audio and video playing in the
   browser, ...) are listed in `docs/design/compatibility.md` and are not reported.

## Switching

### Courses with a `calendar-core` directory (the usual install)

```bash
cd SI460
mv calendar-core calendar-core-v4            # keep v4 for rolling back
git clone https://github.com/jskenney/calendar2.git calendar-core
```

The `calendar -> calendar-core/` link and `calendar.php` stay as they are.

### Courses that use calendar-core as a git submodule

```bash
cd SI460
git submodule set-url calendar-core https://github.com/jskenney/calendar2.git
git submodule sync calendar-core
cd calendar-core && git fetch origin && git checkout origin/main && cd ..
git add .gitmodules calendar-core && git commit -m "Use Cal5"
```

### Then reload PHP

```bash
sudo systemctl reload php8.3-fpm     # or: sudo systemctl reload apache2 (mod_php)
```

PHP caches where `calendar/calendar_main.php` lives (OPcache and the realpath cache), so
until the reload the old code can keep answering for a few minutes. Instructors stay logged
in across the switch, in both directions.

### Check

Open the course as a student and log in as the instructor. The month view's day numbers
open each event's **status page**: every file, whether students can see it now, and when it
opens or locks again. **View as a student** shows any event as students see it now.

## Rolling back

```bash
cd SI460
mv calendar-core calendar-core-cal5
mv calendar-core-v4 calendar-core
sudo systemctl reload php8.3-fpm
```

Nothing else changed, so v4 works exactly as before (logins made under Cal5 still work).

## Moving to `calendar.ini.php` (optional)

`calendar.php` keeps working. When you are ready, Cal5 can write the same configuration as
`calendar.ini.php`, a plain settings file with comments, which also unlocks Cal5's new
settings (meeting times, password hashes, the start page, the calendar's boxes, ...):

```bash
php calendar/bin/cal5 translate --hash-password
php calendar/bin/cal5 check
```

Once `calendar.ini.php` exists it is used instead of `calendar.php`; delete it to go back.
For a course that only uses `calendar.ini.php`, `index.php` contains:

```php
<?php require __DIR__ . '/calendar/index.php';
```

The settings are described in `docs/design/configuration.md`, and the new release rules
(minutes, re-locking, meeting times) in `docs/release-rules.md`.

## Web server notes

- **Apache:** Cal5 ships `.htaccess` files that deny `src/`, `bin/`, `tests/`, `docs/` and
  `pages/`, and `calendar.ini.php` refuses to be served (its first line). Course directories'
  own `.htaccess` files work as before.
- **nginx:** deny the same directories, and keep course directories private as you did for v4:

  ```nginx
  location ~ ^/SI460/calendar/(src|bin|tests|docs|pages)/ { deny all; }
  location ~ ^/SI460/(class|lab|exam|virtual)/            { deny all; }
  location ~ \.ini\.php$                                   { deny all; }
  ```

- Keep the access log (`$ACCESS_LOG`, `[features] access_log`) outside the web root; Cal5
  warns the instructor when it is not.
