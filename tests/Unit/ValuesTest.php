<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Internal\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValuesTest extends TestCase
{
    /** @return iterable<array{mixed, ?string}> */
    public static function values(): iterable
    {
        yield 'string' => ['1-3', '1-3'];
        yield 'empty string' => ['', ''];
        yield 'unicode string' => ['Grüße', 'Grüße'];
        yield 'int' => [72, '72'];
        yield 'negative int' => [-5, '-5'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'null' => [null, null];
        yield 'whole float' => [72.0, '72'];
        yield 'float' => [1.5, '1.5'];
        yield 'tenth' => [0.1, '0.1'];
        yield 'third' => [1 / 3, '0.3333333333333333'];
        yield 'negative float' => [-2.25, '-2.25'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'large float' => [1.0e20, '100000000000000000000'];
        yield 'small float' => [1.5e-7, '0.00000015'];
        yield 'mixed' => [123456.789, '123456.789'];
        yield 'stringable' => [new class () implements \Stringable {
            public function __toString(): string
            {
                return 'from-object';
            }
        }, 'from-object'];
    }

    #[DataProvider('values')]
    public function testFormat(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, Values::format('Name', $value));
    }

    public function testFloatsReadBackExactly(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 2000; $i++) {
            $value = (mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(-12, 12);
            $text = Values::formatFloat('x', $value);
            self::assertMatchesRegularExpression('/^-?\d+(\.\d+)?$/', $text);
            self::assertSame($value == 0.0 ? 0.0 : $value, (float) $text, $text);
        }
    }

    /** @return iterable<array{mixed}> */
    public static function badValues(): iterable
    {
        yield 'array' => [[1, 2]];
        yield 'object' => [new \stdClass()];
        yield 'infinity' => [INF];
        yield 'nan' => [NAN];
    }

    #[DataProvider('badValues')]
    public function testRejectsValuesThatAreNotText(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Opacity');
        Values::format('Opacity', $value);
    }
}
