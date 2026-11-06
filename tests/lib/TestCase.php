<?php

declare(strict_types=1);

namespace Cal5\Tests;

/**
 * Minimal test case: subclasses define public test*() methods.
 * Failed assertions are collected (the test keeps running) and reported by tests/run.php.
 */
abstract class TestCase
{
    /** @var list<string> */
    private array $failures = [];
    private int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function assertions(): int
    {
        return $this->assertions;
    }

    public function resetResults(): void
    {
        $this->failures = [];
        $this->assertions = 0;
    }

    protected function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->failures[] = $message;
        }
    }

    protected function assertFalse(bool $condition, string $message = 'expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertTrue($expected === $actual, ($message !== '' ? $message . ': ' : '')
            . 'expected ' . self::show($expected) . ', got ' . self::show($actual));
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message !== '' ? $message : 'missing: ' . self::show($needle));
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(!str_contains($haystack, $needle), $message !== '' ? $message : 'unexpected: ' . self::show($needle));
    }

    /** A page or download must not contain PHP warnings, notices, deprecations or errors. */
    protected function assertNoPhpErrors(Response $response, string $message = ''): void
    {
        $errors = $response->phpErrors();
        $this->assertTrue($errors === [], ($message !== '' ? $message . ': ' : '') . 'PHP errors: ' . implode(' | ', $errors));
    }

    private static function show(mixed $value): string
    {
        $text = var_export($value, true);
        return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
    }
}
