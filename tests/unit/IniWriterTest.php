<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Config\IniReader;
use Cal5\Config\IniWriter;
use Cal5\Tests\TestCase;

final class IniWriterTest extends TestCase
{
    /** Every value the writer quotes must read back unchanged. */
    public function testQuotingRoundTrips(): void
    {
        $values = ['plain', '', 'a ; b', 'C# rocks # x', '#fff', ';starts', ' padded ', 'say "hi"', "it's", 'back\\slash',
                   "line\nbreak", "tab\there", '"quoted"', "'single'", '$2y$12$abc/def.', 'ünïcödé ✓', 'a=b', '[x]'];
        $ini = "[s]\n";
        foreach ($values as $i => $value) {
            $ini .= "k$i = " . IniWriter::quote($value) . "\n";
        }
        $result = (new IniReader())->parse($ini);
        $this->assertSame([], array_map('strval', $result->errors));
        foreach ($values as $i => $value) {
            $this->assertSame($value, $result->document->get('s', "k$i")?->value, 'value ' . json_encode($value));
        }
    }

    public function testPlainValuesStayReadable(): void
    {
        $this->assertSame('Computer Graphics', IniWriter::quote('Computer Graphics'));
        $this->assertSame('index.html, index.md', IniWriter::quote('index.html, index.md'));
        $this->assertSame('"a ; b"', IniWriter::quote('a ; b'));
    }
}
