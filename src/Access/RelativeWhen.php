<?php

declare(strict_types=1);

namespace Cal5\Access;

use DateTimeImmutable;

/**
 * A time relative to the event: [start|end] offsets... [HH:MM]
 *
 *  - no anchor: midnight at the start of the event's date (v4: "+7", "-monday")
 *  - start / end: the meeting's start / end time that day (midnight without a meeting time)
 *  - offsets, left to right: +N / +Nd (days), +Nw (weeks) move calendar days and keep the clock
 *    time across daylight-saving changes; +Nh / +Nm are exact durations; +monday is the next
 *    Monday after, -monday the Monday on or before (v4)
 *  - a final HH:MM sets the time of day
 */
final class RelativeWhen implements When
{
    /**
     * @param list<array{sign: int, amount: int, unit: string}> $offsets unit: d, w, h, m, or a weekday (mon..sun)
     */
    public function __construct(
        public readonly string $anchor,
        public readonly array $offsets,
        public readonly ?string $time,
        private readonly string $text,
    ) {
    }

    public function reached(EventContext $context, DateTimeImmutable $now): bool
    {
        $at = $this->at($context);
        return $at !== null && $now >= $at;
    }

    public function at(EventContext $context): ?DateTimeImmutable
    {
        $t = $context->midnight();
        if ($t === null) {
            return null;
        }
        if ($this->anchor !== '' && $context->meeting !== null) {
            $clock = $this->anchor === 'start' ? $context->meeting->start : $context->meeting->end;
            [$h, $m] = array_map('intval', explode(':', $clock));
            $t = $t->setTime($h, $m);
        }
        foreach ($this->offsets as $offset) {
            $t = self::apply($t, $offset);
        }
        if ($this->time !== null) {
            [$h, $m] = array_map('intval', explode(':', $this->time));
            $t = $t->setTime($h, $m);
        }
        return $t;
    }

    /** @param array{sign: int, amount: int, unit: string} $offset */
    private static function apply(DateTimeImmutable $t, array $offset): DateTimeImmutable
    {
        $sign = $offset['sign'] < 0 ? '-' : '+';
        return match ($offset['unit']) {
            'd' => $t->modify($sign . $offset['amount'] . ' days'),
            'w' => $t->modify($sign . ($offset['amount'] * 7) . ' days'),
            'h' => $t->setTimestamp($t->getTimestamp() + $offset['sign'] * $offset['amount'] * 3600),
            'm' => $t->setTimestamp($t->getTimestamp() + $offset['sign'] * $offset['amount'] * 60),
            default => self::weekday($t, $offset['sign'], $offset['unit']),
        };
    }

    private static function weekday(DateTimeImmutable $t, int $sign, string $day): DateTimeImmutable
    {
        $h = (int) $t->format('G');
        $m = (int) $t->format('i');
        if ($sign > 0) {
            $moved = $t->modify('next ' . $day);
        } elseif (strtolower($t->format('D')) === $day) {
            return $t; // already that weekday (v4)
        } else {
            $moved = $t->modify('last ' . $day);
        }
        return $moved->setTime($h, $m);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
