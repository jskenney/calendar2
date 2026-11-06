<?php

declare(strict_types=1);

namespace Cal5\Http;

/**
 * features.access_log (v4 $ACCESS_LOG): one line per request, "Ymd-H:i:s ADDRESS QUERY",
 * written only when the file already exists (as in v4, so a typo does not create files).
 * Passwords, lock codes and tokens are replaced with REMOVED.  The instructor is warned
 * when the log is inside the web root.
 */
final class AccessLog
{
    private const SECRET = ['password', 'lock', 'editor', 'csrf'];

    public static function line(Request $request, \DateTimeImmutable $now): string
    {
        $query = (string) parse_url($request->server('REQUEST_URI'), PHP_URL_QUERY);
        if ($query === '') {
            $text = 'home';
        } else {
            parse_str($query, $params);
            foreach (self::SECRET as $name) {
                if (array_key_exists($name, $params)) {
                    $params[$name] = 'REMOVED';
                }
            }
            $text = http_build_query($params);
        }
        return $now->format('Ymd-H:i:s') . ' ' . str_replace(["\r", "\n", ' '], '', $request->clientIp()) . ' ' . $text . "\n";
    }

    public static function write(?string $file, string $courseDir, Request $request, \DateTimeImmutable $now): void
    {
        $path = self::path($file, $courseDir);
        if ($path === null || !is_file($path)) {
            return;
        }
        @file_put_contents($path, self::line($request, $now), FILE_APPEND | LOCK_EX);
    }

    public static function path(?string $file, string $courseDir): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }
        return str_starts_with($file, '/') ? $file : $courseDir . '/' . $file;
    }

    /** Is the log where the web server may serve it? */
    public static function insideWebRoot(?string $file, string $courseDir, string $documentRoot): bool
    {
        $path = self::path($file, $courseDir);
        $real = $path === null ? false : realpath($path);
        $root = $documentRoot === '' ? false : realpath($documentRoot);
        return $real !== false && $root !== false && str_starts_with($real, rtrim($root, '/') . '/');
    }
}
