<?php

declare(strict_types=1);

namespace Cal5\Tests\Compat;

use Cal5\Compare\IntendedDifferences;
use Cal5\Tests\Fixture;
use Cal5\Tests\HttpClient;
use Cal5\Tests\Server;

/** Runs every compatibility request for one fixture against one implementation. */
final class Runner
{
    /** @return array<string, array{request: array<string, string>, observation: array<string, mixed>}> keyed by request id */
    public static function run(Fixture $fixture, string $implementationDir): array
    {
        $course = $fixture->install($implementationDir);
        $server = new Server($course);
        $observer = new Observer($fixture->meta['markers'] ?? []);
        $results = [];
        try {
            $clients = [];
            foreach (Requests::ROLES as $role) {
                $clients[$role] = new HttpClient($server);
            }
            $clients['instructor']->login((string) $fixture->meta['entry'], (string) $fixture->meta['password']);

            foreach (Requests::forFixture($fixture) as $request) {
                $path = $request['entry'] . ($request['query'] !== '' ? '?' . $request['query'] : '');
                $response = $clients[$request['role']]->get($path);
                $results[$request['id']] = ['request' => $request, 'observation' => $observer->observe($response)];
            }
        } finally {
            $server->stop();
            Fixture::remove($course);
        }
        return $results;
    }

    /**
     * Differences between expected and actual observations.
     *
     * @param array<string, array<string, mixed>> $expected id => observation
     * @param array<string, array<string, mixed>> $actual id => observation
     * @param list<string> $knownWarnings PHP errors containing these texts are ignored (known v4 bugs)
     * @return list<string>
     */
    public static function compare(array $expected, array $actual, array $knownWarnings = [], bool $intended = true): array
    {
        $differences = [];
        foreach ($expected as $id => $want) {
            if (!isset($actual[$id])) {
                $differences[] = "$id: not requested";
                continue;
            }
            array_push($differences, ...self::compareOne((string) $id, $want, $actual[$id], $knownWarnings, $intended));
        }
        foreach (array_diff(array_keys($actual), array_keys($expected)) as $id) {
            $differences[] = "$id: no expected result recorded (run record)";
        }
        return $differences;
    }

    /**
     * Differences for one request (an empty list means it matches).
     *
     * @param array<string, mixed> $want
     * @param array<string, mixed> $got
     * @param list<string> $knownWarnings
     * @return list<string>
     */
    public static function compareOne(string $id, array $want, array $got, array $knownWarnings = [], bool $intended = true): array
    {
        $differences = [];
        if ($intended) {
            // only for Cal5: v4 is compared with its own recording as it is
            [$want, $got] = IntendedDifferences::apply($want, $got);
        }
        foreach (array_unique(array_merge(array_keys($want), array_keys($got))) as $field) {
            $a = $want[$field] ?? null;
            $b = $got[$field] ?? null;
            if ($field === 'php_errors') {
                // Only the implementation under test must be free of PHP errors (v4 has known warnings)
                $b = array_values(array_filter((array) $b, static function (string $error) use ($knownWarnings): bool {
                    foreach ($knownWarnings as $known) {
                        if (str_contains($error, $known)) {
                            return false;
                        }
                    }
                    return true;
                }));
                if ($b !== []) {
                    $differences[] = "$id: PHP errors " . self::short($b);
                }
                continue;
            }
            if ($a !== $b) {
                $differences[] = "$id: $field expected " . self::short($a) . ', got ' . self::short($b);
            }
        }
        return $differences;
    }

    private static function short(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '?';
        return strlen($text) > 160 ? substr($text, 0, 160) . '...' : $text;
    }
}
