<?php

declare(strict_types=1);

namespace Cal5\Auth;

use Cal5\Http\Request;

/**
 * Codes for <lock code="a,b"> sections (v4): ?lock=CODE is remembered in the
 * session (the same "lock" key as v4) and opens every section listing it.
 */
final class LockCodes
{
    public const KEY = 'lock';

    public static function remember(Request $request, SessionStore $session): void
    {
        $code = $request->param('lock');
        if ($code !== null) {
            $session->set(self::KEY, $code);
        }
    }

    /** Does the remembered code open a section with code="a, b, c"? */
    public static function opens(SessionStore $session, string $codes): bool
    {
        $remembered = $session->get(self::KEY);
        if (!is_string($remembered) || $remembered === '') {
            return false;
        }
        foreach (explode(',', $codes) as $code) {
            $code = trim($code);
            if ($code !== '' && hash_equals($code, $remembered)) {
                return true;
            }
        }
        return false;
    }
}
