<?php

declare(strict_types=1);

namespace Cal5\Config;

/**
 * Reads Cal5's INI-style configuration files (docs/design/configuration.md).
 *
 * PHP's parse_ini_file() is not used: it rejects whole files over reserved
 * words such as "on" or "none" used as keys, and treats several characters
 * specially.  This reader is deliberately forgiving:
 *
 *  - [section] headers and "key = value" lines; keys are any text before "="
 *  - ";" starts a comment at the start of a line, at the start of a value, or
 *    after a space; "#" does too, except at the very start of a value (#fff, C#)
 *  - values may be quoted with "..." (escapes \" \\ \n \t) or '...' (literal)
 *  - keys and section names are case-insensitive; repeated keys are kept
 *  - a UTF-8 byte order mark and Windows line endings are accepted
 *
 * Problems are collected with line numbers instead of stopping at the first one.
 */
final class IniReader
{
    /** @var list<Problem> */
    private array $errors = [];

    public static function readFile(string $path): IniReadResult
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            return new IniReadResult(new IniDocument(basename($path), []), [new Problem('cannot be read', basename($path))]);
        }
        return (new self())->parse($text, basename($path));
    }

    public function parse(string $text, string $file = 'calendar.ini.php'): IniReadResult
    {
        $this->errors = [];
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $sections = [];
        $sectionLines = [];
        $current = '';

        foreach ($lines as $index => $raw) {
            $lineNumber = $index + 1;
            $line = trim($raw);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }

            if ($line[0] === '[') {
                if (!preg_match('/^\[\s*([^\[\]]+?)\s*\]\s*(?:[;#].*)?$/', $line, $m)) {
                    $this->error('section header should look like [name]', $file, $lineNumber);
                    continue;
                }
                $current = strtolower($m[1]);
                if (isset($sectionLines[$current])) {
                    // A repeated section continues the earlier one
                    continue;
                }
                $sectionLines[$current] = $lineNumber;
                $sections[$current] ??= [];
                continue;
            }

            $equals = strpos($line, '=');
            if ($equals === false) {
                $this->error('expected "key = value"', $file, $lineNumber);
                continue;
            }
            $key = trim(substr($line, 0, $equals));
            if ($key === '') {
                $this->error('missing key before "="', $file, $lineNumber);
                continue;
            }
            $value = $this->value(substr($line, $equals + 1), $file, $lineNumber);
            if ($value === null) {
                continue;
            }
            $sections[$current][] = new IniEntry($key, $value, $lineNumber);
        }

        return new IniReadResult(new IniDocument($file, $sections, $sectionLines), $this->errors);
    }

    /** Parse the text after "=": quotes, escapes and trailing comments. */
    private function value(string $text, string $file, int $line): ?string
    {
        $text = ltrim($text);
        if ($text === '') {
            return '';
        }

        $quote = $text[0];
        if ($quote === '"' || $quote === "'") {
            $value = '';
            $length = strlen($text);
            for ($i = 1; $i < $length; $i++) {
                $char = $text[$i];
                if ($quote === '"' && $char === '\\' && $i + 1 < $length) {
                    $next = $text[++$i];
                    $value .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        '"', '\\' => $next,
                        default => '\\' . $next,
                    };
                    continue;
                }
                if ($char === $quote) {
                    $rest = trim(substr($text, $i + 1));
                    if ($rest !== '' && $rest[0] !== ';' && $rest[0] !== '#') {
                        $this->error('unexpected text after the closing quote', $file, $line);
                        return null;
                    }
                    return $value;
                }
                $value .= $char;
            }
            $this->error('missing closing quote', $file, $line);
            return null;
        }

        // Unquoted: ";" at the start is a comment; otherwise " ;" or " #" starts one
        if ($text[0] === ';') {
            return '';
        }
        if (preg_match('/\s[;#]/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, 0, $m[0][1]);
        }
        return trim($text);
    }

    private function error(string $message, string $file, int $line): void
    {
        $this->errors[] = new Problem($message, $file, $line);
    }
}
