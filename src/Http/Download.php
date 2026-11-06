<?php

declare(strict_types=1);

namespace Cal5\Http;

/**
 * Sends a file to the browser (docs/design/architecture.md, "Rendering").
 *
 * Content types and inline/attachment follow v4's provide_file() so links
 * behave as before, with two corrections: image/jpeg (v4 said image/jpg) and
 * proper inline types for audio and video (v4 sent them as attachments).
 * Files are streamed with Content-Length and single-range support, so large
 * files and video seeking work.
 */
final class Download
{
    /** extension => [content type, inline] (v4's table, plus audio/video) */
    private const TYPES = [
        'bz2' => ['application/x-bzip2', false],
        'css' => ['text/css', false],
        'gz' => ['application/x-gzip', false],
        'gzip' => ['application/x-gzip', false],
        'java' => ['text/x-java-source', true],
        'tgz' => ['application/x-compressed', false],
        'pdf' => ['application/pdf', true],
        'zip' => ['application/zip', false],
        'doc' => ['application/msword', false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'xls' => ['application/vnd.ms-excel', false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'ppt' => ['application/vnd.ms-powerpoint', false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
        'svg' => ['image/svg+xml', true],
        'gif' => ['image/gif', true],
        'png' => ['image/png', true],
        'jpe' => ['image/jpeg', true],
        'jpeg' => ['image/jpeg', true],
        'jpg' => ['image/jpeg', true],
        'sql' => ['text/plain', true],
        'txt' => ['text/plain', true],
        'htm' => ['text/html', true],
        'html' => ['text/html', true],
        'htmls' => ['text/html', true],
        // added in Cal5: audio and video play in the page
        'mp4' => ['video/mp4', true],
        'm4v' => ['video/mp4', true],
        'webm' => ['video/webm', true],
        'ogv' => ['video/ogg', true],
        'mp3' => ['audio/mpeg', true],
        'm4a' => ['audio/mp4', true],
        'ogg' => ['audio/ogg', true],
        'wav' => ['audio/wav', true],
    ];

    /** @return array{0: string, 1: bool} content type and whether it is shown inline */
    public static function typeOf(string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'txt'; // v4: no extension means text
        }
        return self::TYPES[$extension] ?? ['application/octet-stream', false];
    }

    /**
     * Send $path, named $name, honouring a Range header.  Returns the status sent.
     *
     * @param string|null $range the Range header, if any
     */
    public static function send(string $path, string $name, ?string $range, bool $headOnly = false): int
    {
        $size = @filesize($path);
        if ($size === false || !is_readable($path)) {
            http_response_code(404);
            return 404;
        }
        [$type, $inline] = self::typeOf($name);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($range !== null && $size > 0) {
            $parsed = self::range($range, $size);
            if ($parsed === null) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return 416;
            }
            [$start, $end] = $parsed;
            $status = 206;
        }

        http_response_code($status);
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($inline ? '' : 'attachment; ') . 'filename="' . str_replace(['"', "\r", "\n"], '', basename($name)) . '"');
        header('Content-Length: ' . ($size === 0 ? 0 : $end - $start + 1));
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, must-revalidate');
        if ($status === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        if ($headOnly || $size === 0) {
            return $status;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $status;
        }
        fseek($handle, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min(1 << 16, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($handle);
        return $status;
    }

    /**
     * One range of "bytes=a-b", "bytes=a-" or "bytes=-n"; null when it can not be satisfied.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function range(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            $length = (int) $m[2];
            if ($length === 0) {
                return null;
            }
            return [max(0, $size - $length), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return null;
        }
        return [$start, $end];
    }
}
