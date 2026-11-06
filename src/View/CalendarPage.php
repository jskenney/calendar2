<?php

declare(strict_types=1);

namespace Cal5\View;

use Cal5\Access\AccessPolicy;
use Cal5\Auth\AuthResult;
use Cal5\Course;
use Cal5\Http\Request;

/** ?show=calendar_display | calendar_display_alt | calendar_display_dateless (v4 names). */
final class CalendarPage
{
    public function __construct(
        private readonly Course $course,
        private readonly AccessPolicy $policy,
        private readonly AuthResult $auth,
        private readonly Request $request,
        private readonly string $csrf,
        private readonly string $entry,
        private readonly string $assetBase,
        private readonly bool $locked = false,
    ) {
    }

    /** The view a ?show= value asks for, or null when it is not a calendar view. */
    public static function viewFor(?string $show): ?string
    {
        return $show !== null ? (CalendarView::VIEWS[$show] ?? null) : null;
    }

    public function render(string $view): string
    {
        $model = new CalendarModel($this->course, $this->policy, $this->auth->instructor);
        $content = (new CalendarView($model, $this->entry))->render($view);
        $warnings = [];
        if ($this->auth->instructor) {
            foreach ($this->policy->problems() as $problem) {
                $warnings[] = (string) $problem;
            }
        }
        $layout = new Layout(
            config: $this->course->config,
            course: $this->course,
            policy: $this->policy,
            auth: $this->auth,
            csrf: $this->csrf,
            entry: $this->entry,
            assetBase: $this->assetBase,
            locked: $this->locked,
            warnings: $warnings,
            page: Layout::PAGE_CALENDAR,
            title: 'Calendar',
        );
        return $layout->render($content, $this->request->has('print') ? Layout::PRINT : Layout::FULL);
    }
}
