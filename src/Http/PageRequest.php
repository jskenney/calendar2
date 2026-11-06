<?php

declare(strict_types=1);

namespace Cal5\Http;

/**
 * The event/type/key of a request, validated as calendar-core 4.5 does: event a
 * plain positive number (no "02", "2.0", "2 "), type a configured type, key 40
 * hex characters.  If any of them is invalid, all three are ignored (v4 falls
 * back to the home page).
 */
final class PageRequest
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?int $event,
        public readonly ?string $key,
    ) {
    }

    /** @param list<string> $types the configured types (component directories) */
    public static function from(Request $request, array $types): self
    {
        $type = $request->param('type');
        $event = $request->param('event');
        $key = $request->param('key');
        $bad = ($request->has('type') === false && self::sent($request, 'type'))
            || ($request->has('event') === false && self::sent($request, 'event'))
            || ($request->has('key') === false && self::sent($request, 'key'))
            || ($type !== null && !in_array($type, $types, true))
            || ($event !== null && !preg_match('/^[1-9][0-9]{0,5}$/', $event))
            || ($key !== null && !preg_match('/^[0-9a-f]{40}$/', $key));
        if ($bad) {
            return new self(null, null, null);
        }
        return new self($type, $event === null ? null : (int) $event, $key);
    }

    /** A request for an event chosen by Cal5 (the start page's "today's lecture"). */
    public static function forEvent(string $type, int $event): self
    {
        return new self($type, $event, null);
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    /** Was a parameter sent at all (also as an array, which counts as invalid)? */
    private static function sent(Request $request, string $name): bool
    {
        return $request->sent($name);
    }

    public function isEvent(): bool
    {
        return $this->type !== null && $this->event !== null;
    }
}
