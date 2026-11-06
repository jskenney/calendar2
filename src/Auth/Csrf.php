<?php

declare(strict_types=1);

namespace Cal5\Auth;

use Cal5\Http\Request;

/** A per-session token that every form Cal5 renders posts back (field "csrf"). */
final class Csrf
{
    public const KEY = 'cal5-csrf';
    public const FIELD = 'csrf';

    public static function token(SessionStore $session): string
    {
        $token = $session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::KEY, $token);
        }
        return $token;
    }

    public static function valid(SessionStore $session, Request $request): bool
    {
        $token = $session->get(self::KEY);
        $sent = $request->postParam(self::FIELD);
        return is_string($token) && $token !== '' && $sent !== null && hash_equals($token, $sent);
    }
}
