<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\App;
use Cal5\Config\ConfigSource;
use Cal5\Config\LegacyVariables;
use Cal5\Support\Html;
use Cal5\Tests\TestCase;
use Cal5\Version;

final class SkeletonTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testAutoloaderAndVersion(): void
    {
        $this->assertTrue(class_exists(Version::class), 'Cal5\Version autoloads');
        $this->assertTrue((bool) preg_match('/^5\.\d+\.\d+(-[a-z0-9.]+)?$/', Version::VERSION), 'version looks like 5.x.y');
    }

    public function testHtmlEscape(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&amp;&apos;', Html::escape('<a href="x">&\''));
        $this->assertSame('42', Html::escape(42));
        $this->assertSame('', Html::escape(null));
        $this->assertSame('', Html::escape(['array']));
        $this->assertSame("\u{FFFD}", Html::escape("\xFF"), 'invalid UTF-8 is replaced, not dropped');
    }

    public function testLegacyVariableList(): void
    {
        $this->assertSame(43, count(LegacyVariables::NAMES), 'every v4 variable (calendar-core 4.6) is listed');
        $this->assertSame(count(LegacyVariables::NAMES), count(array_unique(LegacyVariables::NAMES)), 'no duplicates');

        $globals = ['COURSE' => 'SI123', 'DOW' => [], 'YEAR' => 2018, 'MONTH_START' => 8, 'MONTH_END' => 12,
                    '_GET' => [], 'cal5GlobalVariables' => [], 'UNRELATED' => 1];
        $found = LegacyVariables::extract($globals);
        $this->assertSame(['COURSE', 'DOW', 'MONTH_END', 'MONTH_START', 'YEAR'], self::sortedKeys($found));
        $this->assertTrue(LegacyVariables::looksLikeV4($found));
        $this->assertFalse(LegacyVariables::looksLikeV4(['COURSE' => 'x']), 'a lone $COURSE is not a v4 config');
    }

    public function testConfigDetection(): void
    {
        $dir = sys_get_temp_dir() . '/cal5-detect-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $v4 = ['COURSE' => 'X', 'DOW' => [], 'YEAR' => 2018, 'MONTH_START' => 8, 'MONTH_END' => 12];
        $this->assertSame(ConfigSource::None, App::detect($dir, []));
        $this->assertSame(ConfigSource::Legacy, App::detect($dir, $v4));
        touch($dir . '/' . App::INI_FILE);
        $this->assertSame(ConfigSource::Ini, App::detect($dir, $v4), 'calendar.ini.php wins over v4 variables');
        unlink($dir . '/' . App::INI_FILE);
        rmdir($dir);
    }

    public function testPrivateDirectoriesAreDeniedOnApache(): void
    {
        foreach (['src', 'bin', 'tests', 'docs'] as $dir) {
            $file = self::ROOT . "/$dir/.htaccess";
            $this->assertTrue(is_file($file), "$dir/.htaccess exists");
            $this->assertContains('Require all denied', (string) @file_get_contents($file), "$dir/.htaccess denies access");
        }
    }

    public function testCliVersionAndHelp(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/bin/cal5') . ' version', $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame('Cal5 ' . Version::VERSION, $out[0] ?? '');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::ROOT . '/bin/cal5') . ' nonsense 2>&1', $out2, $code2);
        $this->assertSame(2, $code2, 'unknown commands exit with 2');
    }

    public function testAllPhpFilesLint(): void
    {
        $files = [self::ROOT . '/calendar_main.php', self::ROOT . '/index.php', self::ROOT . '/bin/cal5'];
        foreach (['src', 'tests/lib', 'tests/compat', 'tests/unit', 'tests/http'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, 'php -l ' . basename($file));
        }
    }

    /** @param array<string, mixed> $array @return list<string> */
    private static function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);
        return $keys;
    }
}
