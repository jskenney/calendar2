<?php

declare(strict_types=1);

namespace Cal5\Auth;

use Cal5\Config\Config;

/** security.lock (v4 $LOCK): the whole site is locked for students, always or while a file exists. */
final class LockMode
{
    public static function locked(Config $config, string $courseDir): bool
    {
        $security = $config->security;
        if ($security->lockAlways) {
            return true;
        }
        if ($security->lockFile === null) {
            return false;
        }
        $file = str_starts_with($security->lockFile, '/') ? $security->lockFile : $courseDir . '/' . $security->lockFile;
        return file_exists($file);
    }
}
