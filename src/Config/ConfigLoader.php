<?php

declare(strict_types=1);

namespace Cal5\Config;

/** Loads a course's configuration from wherever it is (docs/design/configuration.md). */
final class ConfigLoader
{
    public const INI_FILE = 'calendar.ini.php';

    /** @param array<string, mixed> $legacyGlobals globals left by a v4 calendar.php (empty for new courses) */
    public static function forCourse(string $courseDir, array $legacyGlobals = []): LoadResult
    {
        $ini = $courseDir . '/' . self::INI_FILE;
        $legacy = LegacyVariables::extract($legacyGlobals);

        if (is_file($ini)) {
            $result = IniConfigLoader::fromFile($ini);
            if (LegacyVariables::looksLikeV4($legacy)) {
                $warnings = $result->warnings;
                $warnings[] = new Problem('calendar.ini.php is used; the settings in calendar.php are ignored', self::INI_FILE);
                return new LoadResult($result->source, $result->config, $result->errors, $warnings, $result->draft);
            }
            return $result;
        }
        if (LegacyVariables::looksLikeV4($legacy)) {
            return LegacyTranslator::translate($legacyGlobals);
        }
        return new LoadResult(ConfigSource::None, null, [], []);
    }
}
