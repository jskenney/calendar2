<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Checks that apply to every configuration, however it was written. */
final class ConfigChecks
{
    /** Advice shown by bin/cal5 check, not on every page (v4 courses can only store the password this way). */
    public const PLAIN_TEXT_ADVICE = 'the instructor password is stored as plain text; consider password_hash '
        . '(php calendar/bin/cal5 hash-password)';

    /**
     * @return array{errors: list<Problem>, warnings: list<Problem>}
     */
    public static function check(Config $config, string $file): array
    {
        $errors = [];
        $warnings = [];
        $security = $config->security;

        $secret = $security->secret;
        if ($secret === '') {
            $errors[] = new Problem('a secret is required ([security] secret = 64 random characters; '
                . 'generate one with: php -r \'echo bin2hex(random_bytes(32));\')', $file);
        } elseif ($secret === Defaults::SAMPLE_SECRET) {
            $errors[] = new Problem('the secret is still the published sample value; set a long random value '
                . '(php -r \'echo bin2hex(random_bytes(32));\')', $file);
        } elseif (strlen($secret) < 32 || preg_match('/impossible to guess -?\d{0,5}, you dont need/', $secret)) {
            $warnings[] = new Problem('the secret is weak (short, or the old installer default); replace it with a long random value', $file);
        }

        if ($security->passwordHash !== null && password_get_info($security->passwordHash)['algo'] === null) {
            $errors[] = new Problem('password_hash is not a password hash (create one with: php calendar/bin/cal5 hash-password)', $file);
        }
        if (!$security->loginEnabled()) {
            $warnings[] = new Problem('no instructor password is set, so the instructor login is disabled', $file);
        } elseif ($security->passwordHash === null) {
            $warnings[] = new Problem(self::PLAIN_TEXT_ADVICE, $file);
        }

        if ($config->semester->end < $config->semester->start) {
            $errors[] = new Problem("the semester ends ({$config->semester->end}) before it starts ({$config->semester->start})", $file);
        }
        foreach (array_keys($config->schedule->days) as $date) {
            if ($date < $config->semester->start || $date > $config->semester->end) {
                $warnings[] = new Problem("the day $date is outside the semester and is ignored", $file);
            }
        }
        if ($config->schedule->weekly === []) {
            $warnings[] = new Problem('the weekly schedule is empty, so only dated days appear on the calendar', $file);
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
