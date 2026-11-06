<?php

declare(strict_types=1);

namespace Cal5\Config;

/**
 * A course's complete, validated configuration.  Built from calendar.ini.php
 * (IniConfigLoader) or from a v4 calendar.php (LegacyTranslator); the rest of
 * Cal5 only sees this object.
 */
final class Config
{
    /**
     * @param list<Box> $boxes
     * @param list<MenuItem> $menus
     * @param list<MenuItem>|null $instructorMenus null = same as $menus
     * @param array<string, string> $replace
     */
    public function __construct(
        public readonly CourseSettings $course,
        public readonly SemesterSettings $semester,
        public readonly ScheduleSettings $schedule,
        public readonly array $boxes,
        public readonly array $menus,
        public readonly ?array $instructorMenus,
        public readonly array $replace,
        public readonly FileSettings $files,
        public readonly SecuritySettings $security,
        public readonly DisplaySettings $display,
        public readonly StyleSettings $style,
        public readonly FeatureSettings $features,
    ) {
    }

    public function box(string $name): ?Box
    {
        foreach ($this->boxes as $box) {
            if ($box->name === $name) {
                return $box;
            }
        }
        return null;
    }

    /**
     * Canonical array form: used to compare configurations (translate round trip),
     * by "bin/cal5 check --json", and in tests.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $plans = static fn (array $plans): array => array_map(static fn (DayPlan $p): array => $p->toArray(), $plans);
        $menus = static fn (?array $items): ?array => $items === null ? null : array_map(
            static fn (MenuItem $m): array => ['type' => $m->type, 'label' => $m->label, 'icon' => $m->icon],
            $items,
        );
        return [
            'course' => [
                'number' => $this->course->number,
                'name' => $this->course->name,
                'full_name' => $this->course->fullName,
                'title' => $this->course->title,
                'timezone' => $this->course->timezone,
            ],
            'semester' => [
                'start' => $this->semester->start,
                'end' => $this->semester->end,
                'weekends' => $this->semester->weekends,
                'show_from' => $this->semester->showFrom,
                'show_until' => $this->semester->showUntil,
            ],
            'schedule' => $plans($this->schedule->weekly),
            'days' => $plans($this->schedule->days),
            'combine' => array_map(static fn (CombineRule $r): array => [
                'type' => $r->type, 'number' => $r->number, 'add' => $r->add,
            ], $this->schedule->combine),
            'boxes' => array_map(static fn (Box $b): array => [
                'name' => $b->name,
                'candidates' => array_map('strval', $b->candidates),
            ], $this->boxes),
            'menus' => $menus($this->menus),
            'instructor_menus' => $menus($this->instructorMenus),
            'replace' => $this->replace,
            'files' => [
                'access' => $this->files->access,
                'virtual' => $this->files->virtual,
                'load_pages' => $this->files->loadPages,
                'allowed_paths' => $this->files->allowedPaths,
            ],
            'security' => [
                // never the secret or password themselves
                'password_hash' => $this->security->passwordHash !== null ? '(set)' : null,
                'password' => $this->security->password !== null ? '(set)' : null,
                'secret_sha1' => sha1($this->security->secret),
                'session_seconds' => $this->security->sessionSeconds,
                'lock_always' => $this->security->lockAlways,
                'lock_file' => $this->security->lockFile,
            ],
            'display' => [
                'start_page' => $this->display->startPage,
                'calendar_view' => $this->display->calendarView,
                'markdown_menu_levels' => $this->display->markdownMenuLevels,
                'heading_warnings' => $this->display->headingWarnings,
                'box_pick' => $this->display->boxPick,
                'calendar_boxes' => $this->display->calendarBoxes,
            ],
            'style' => [
                'css' => $this->style->css,
                'css_by_type' => $this->style->cssByType,
                'css_calendar' => $this->style->cssCalendar,
                'js' => $this->style->js,
                'js_by_type' => $this->style->jsByType,
            ],
            'features' => [
                'postmeeting' => $this->features->postmeeting,
                'access_log' => $this->features->accessLog,
                'legacy_css' => $this->features->legacyCss,
                'legacy_js' => $this->features->legacyJs,
                'debug' => $this->features->debug,
            ],
        ];
    }
}
