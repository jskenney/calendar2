<?php

declare(strict_types=1);

namespace Cal5\Tests\Unit;

use Cal5\Config\IniReader;
use Cal5\Tests\TestCase;

final class IniReaderTest extends TestCase
{
    /** @return array<string, string> key => value for a section */
    private static function values(string $ini, string $section): array
    {
        $values = [];
        foreach ((new IniReader())->parse($ini)->document->entries($section) as $entry) {
            $values[$entry->key] = $entry->value;
        }
        return $values;
    }

    public function testReservedWordsAreOrdinaryKeys(): void
    {
        // parse_ini_file() rejects the whole file for these keys
        $values = self::values("[replace]\non = 1\noff = 2\nyes = 3\nno = 4\ntrue = 5\nnull = 6\nnone = 7\n", 'replace');
        $this->assertSame(['on' => '1', 'off' => '2', 'yes' => '3', 'no' => '4', 'true' => '5', 'null' => '6', 'none' => '7'], $values);
    }

    public function testCommentsAndQuotes(): void
    {
        $ini = <<<'INI'
;<?php http_response_code(404); exit; ?>
# a comment
[A]
plain = some text   ; comment
hash = C# rocks # comment
color = #fff
empty =   ; only a comment
double = "keeps ; and # and \"quotes\" and \\ \n"
single = 'single \n literal'
spaces = "  padded  "
dollar = ${not_expanded} $2y$12$abc
url = https://example.edu/a?b=c&d=e
equals = a = b
INI;
        $values = self::values($ini, 'a');
        $this->assertSame('some text', $values['plain']);
        $this->assertSame('C# rocks', $values['hash']);
        $this->assertSame('#fff', $values['color'], '# at the start of a value is kept');
        $this->assertSame('', $values['empty'], '; at the start of a value is a comment');
        $this->assertSame("keeps ; and # and \"quotes\" and \\ \n", $values['double']);
        $this->assertSame('single \n literal', $values['single']);
        $this->assertSame('  padded  ', $values['spaces']);
        $this->assertSame('${not_expanded} $2y$12$abc', $values['dollar']);
        $this->assertSame('https://example.edu/a?b=c&d=e', $values['url']);
        $this->assertSame('a = b', $values['equals'], 'only the first = separates key and value');
    }

    public function testKeysAndSectionsAreCaseInsensitiveButKeepTheirSpelling(): void
    {
        $doc = (new IniReader())->parse("[Course]\nNumber = SI460\n[COURSE]\nname = Graphics\n")->document;
        $this->assertSame('SI460', $doc->get('course', 'number')?->value);
        $this->assertSame('Number', $doc->get('course', 'NUMBER')?->key);
        $this->assertSame('Graphics', $doc->get('course', 'name')?->value, 'a repeated section continues the first');
    }

    public function testRepeatedKeysAreKeptInOrder(): void
    {
        $doc = (new IniReader())->parse("[style]\ncss = a.css\ncss = b.css\n")->document;
        $this->assertSame(2, count($doc->all('style', 'css')));
        $this->assertSame('b.css', $doc->get('style', 'css')?->value, 'get() returns the last one');
    }

    public function testErrorsHaveLineNumbersAndParsingContinues(): void
    {
        $result = (new IniReader())->parse("[a]\nok = 1\nno equals sign\n[broken\n= value\nq = \"open\nlast = 2\nq2 = \"x\" trailing\n", 'test.ini');
        $messages = array_map('strval', $result->errors);
        $this->assertSame([
            'test.ini:3: expected "key = value"',
            'test.ini:4: section header should look like [name]',
            'test.ini:5: missing key before "="',
            'test.ini:6: missing closing quote',
            'test.ini:8: unexpected text after the closing quote',
        ], $messages);
        $this->assertSame('2', $result->document->get('a', 'last')?->value, 'later lines are still read');
    }

    public function testByteOrderMarkAndWindowsLineEndings(): void
    {
        $doc = (new IniReader())->parse("\xEF\xBB\xBF[course]\r\nnumber = SI460\r\nname = Graphics\r\n")->document;
        $this->assertSame('SI460', $doc->get('course', 'number')?->value);
        $this->assertSame('Graphics', $doc->get('course', 'name')?->value);
        $this->assertSame(3, $doc->get('course', 'name')?->line);
    }

    public function testEntriesBeforeAnySection(): void
    {
        $doc = (new IniReader())->parse("stray = 1\n[course]\n")->document;
        $this->assertSame(['', 'course'], $doc->sectionNames());
    }
}
