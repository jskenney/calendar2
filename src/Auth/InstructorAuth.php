<?php

declare(strict_types=1);

namespace Cal5\Auth;

use Cal5\Access\Clock;
use Cal5\Config\Config;
use Cal5\Http\Request;

/**
 * Instructor login, compatible with v4 (calendar-core 4.6):
 *
 *  - the same cookies (cal4-<COURSE>, cal4-<COURSE>-nonce) and session keys, and for
 *    plain-text passwords the same token, sha1(secret . password . course . nonce . session id),
 *    so an instructor logged in under v4 stays logged in after the switch
 *  - "password" may be the password itself, or sha256(password . nonce) as sent by v4's
 *    login form (with the nonce of the cookie or of the session)
 *  - any other "password" while logged in logs out (v4's logout button)
 *  - while logged out, every request gets a new nonce
 *
 * Added: the session id changes at login (no session fixation), comparisons take constant
 * time, a login ends after security.session_hours on the server side too, failed logins are
 * rate limited, and configurations with password_hash use an HMAC token tied to the hash
 * (changing the password logs everyone out).  password_hash can not support v4's
 * challenge-response (the server no longer knows the password), so those courses log in by
 * posting the password itself (over HTTPS).
 */
final class InstructorAuth
{
    public const LOGIN_AT = 'cal5-login-at';

    private readonly string $tokenName;
    private readonly string $nonceName;

    public function __construct(
        private readonly Config $config,
        private readonly SessionStore $session,
        private readonly Request $request,
        private readonly Clock $clock,
        private readonly ?RateLimiter $limiter = null,
    ) {
        $this->tokenName = 'cal4-' . $config->course->number;
        $this->nonceName = 'cal4-' . $config->course->number . '-nonce';
    }

    public function handle(): AuthResult
    {
        if (!$this->config->security->loginEnabled()) {
            return new AuthResult(false, [], null, null); // v4: an empty $ADMIN disables the login
        }
        $now = $this->clock->now()->getTimestamp();
        $cookies = [];
        $event = null;
        $cookieToken = $this->request->cookie($this->tokenName);

        $password = $this->request->param('password');
        if ($password !== null) {
            $wasIn = $this->verify($cookieToken, $now);
            if ($this->matches($password)) {
                if (!$wasIn && $this->limiter?->blocked($this->request->clientIp(), $now)) {
                    $event = AuthResult::BLOCKED;
                } else {
                    [$cookieToken, $cookies] = $this->login($now);
                    $this->limiter?->clear($this->request->clientIp());
                    $event = AuthResult::LOGGED_IN;
                }
            } elseif ($wasIn || $this->session->get($this->tokenName) !== null || $cookieToken !== null) {
                $this->logout();
                if ($cookieToken !== null) {
                    $cookies[] = new Cookie($this->tokenName, '', $now - 3600);
                    $cookieToken = null;
                }
                $event = AuthResult::LOGGED_OUT;
            } else {
                $this->limiter?->fail($this->request->clientIp(), $now);
                $event = $this->limiter?->blocked($this->request->clientIp(), $now) ? AuthResult::BLOCKED : AuthResult::FAILED;
            }
        }

        $instructor = $this->verify($cookieToken, $now);
        $nonce = null;
        if (!$instructor) {
            // v4: a new nonce for every request while logged out
            $nonce = bin2hex(random_bytes(32));
            $this->session->set($this->nonceName, $nonce);
            $cookies[] = new Cookie($this->nonceName, $nonce, $now + $this->config->security->sessionSeconds);
        }
        return new AuthResult($instructor, $cookies, $event, $nonce);
    }

    /** Does the "password" parameter prove the password? */
    private function matches(string $given): bool
    {
        $security = $this->config->security;
        if ($given === '') {
            return false;
        }
        if ($security->passwordHash !== null) {
            return password_verify($given, $security->passwordHash);
        }
        $password = (string) $security->password;
        if (hash_equals($password, $given)) {
            return true;
        }
        // v4's login form sends sha256(password . nonce), with the nonce of the cookie or the session
        foreach ([$this->request->cookie($this->nonceName), $this->stringFromSession($this->nonceName)] as $nonce) {
            if ($nonce !== null && hash_equals(hash('sha256', $password . $nonce), strtolower($given))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log in: new session id, one nonce for session and cookie, tokens for both.
     *
     * @return array{0: string, 1: list<Cookie>} the cookie token and the cookies to send
     */
    private function login(int $now): array
    {
        $nonce = $this->stringFromSession($this->nonceName) ?? $this->request->cookie($this->nonceName) ?? bin2hex(random_bytes(32));
        $sessionId = $this->session->regenerate();
        $token = $this->token($nonce, $sessionId);
        $this->session->set($this->nonceName, $nonce);
        $this->session->set($this->tokenName, $token);
        $this->session->set(self::LOGIN_AT, $now);
        $expires = $now + $this->config->security->sessionSeconds;
        return [$token, [new Cookie($this->nonceName, $nonce, $expires), new Cookie($this->tokenName, $token, $expires)]];
    }

    private function logout(): void
    {
        $this->session->remove($this->tokenName);
        $this->session->remove(self::LOGIN_AT);
    }

    /** Is the visitor logged in (by the session, or by the cookie as v4 allows)? */
    private function verify(?string $cookieToken, int $now): bool
    {
        $loginAt = $this->session->get(self::LOGIN_AT);
        if (is_int($loginAt) && $now - $loginAt > $this->config->security->sessionSeconds) {
            $this->logout();
            return false;
        }
        $sessionId = $this->session->id();
        $valid = false;
        $cookieNonce = $this->request->cookie($this->nonceName);
        if ($cookieToken !== null && $cookieNonce !== null && hash_equals($this->token($cookieNonce, $sessionId), $cookieToken)) {
            $valid = true;
        }
        $sessionToken = $this->stringFromSession($this->tokenName);
        $sessionNonce = $this->stringFromSession($this->nonceName);
        if (!$valid && $sessionToken !== null && $sessionNonce !== null
            && hash_equals($this->token($sessionNonce, $sessionId), $sessionToken)) {
            $valid = true;
        }
        if ($valid && !is_int($loginAt)) {
            $this->session->set(self::LOGIN_AT, $now); // logged in under v4: start counting now
        }
        return $valid;
    }

    private function token(string $nonce, string $sessionId): string
    {
        $security = $this->config->security;
        $course = $this->config->course->number;
        if ($security->passwordHash !== null) {
            return hash_hmac('sha256', $course . "\n" . $nonce . "\n" . $sessionId, $security->secret . "\n" . $security->passwordHash);
        }
        // v4's token, so v4 logins carry over
        return sha1($security->secret . $security->password . $course . $nonce . $sessionId);
    }

    private function stringFromSession(string $key): ?string
    {
        $value = $this->session->get($key);
        return is_string($value) ? $value : null;
    }
}
