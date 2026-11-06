<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/** When students can see a file: the rule that applies, and its opening and closing moments. */
final class Window
{
    public function __construct(
        /** The rule that decided, or null when no rule applies (visible) */
        public readonly ?Rule $rule,
        private readonly EventContext $context,
        /** False when the event is not on the calendar: students never see it */
        public readonly bool $onCalendar,
    ) {
    }

    public function isOpen(DateTimeImmutable $now): bool
    {
        if (!$this->onCalendar) {
            return false;
        }
        if ($this->rule === null) {
            return true;
        }
        if (!$this->rule->open->reached($this->context, $now)) {
            return false;
        }
        return $this->rule->close === null || !$this->rule->close->reached($this->context, $now);
    }

    public function opens(): ?DateTimeImmutable
    {
        return $this->rule?->open->at($this->context);
    }

    public function closes(): ?DateTimeImmutable
    {
        return $this->rule?->close?->at($this->context);
    }

    /** For the instructor: "opens Mon 14 Sep 2026 13:30 · locks Fri 18 Sep 2026 23:59". */
    public function describe(): string
    {
        if (!$this->onCalendar) {
            return 'not on the calendar (students never see it)';
        }
        if ($this->rule === null) {
            return 'always visible (no rule)';
        }
        if ($this->rule->open instanceof NeverWhen) {
            return 'never visible';
        }
        $opens = $this->opens();
        $text = $this->rule->open instanceof AlwaysWhen ? 'visible' : ($opens === null ? 'never opens' : 'opens ' . self::format($opens));
        if ($this->rule->close !== null) {
            $closes = $this->closes();
            $text .= $closes === null ? ' · never locks' : ' · locks ' . self::format($closes);
            if ($opens !== null && $closes !== null && $closes <= $opens) {
                $text .= ' (locks before it opens: never visible)';
            }
        }
        return $text;
    }

    public static function format(DateTimeImmutable $time): string
    {
        return $time->format($time->format('H:i') === '00:00' ? 'D j M Y' : 'D j M Y H:i');
    }
}
