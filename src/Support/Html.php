<?php

declare(strict_types=1);

namespace Cal5\Support;

final class Html
{
    /** Escape a value for HTML text or a quoted attribute. */
    public static function escape(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            $value = '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
