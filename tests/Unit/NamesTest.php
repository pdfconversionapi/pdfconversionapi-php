<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Exception\InvalidResponseException;
use PdfConversionApi\Internal\Names;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamesTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function segments(): iterable
    {
        yield ['report.docx', 'report.docx'];
        yield ['/tmp/in/report.docx', 'report.docx'];
        yield ['C:\\Users\\me\\report.docx', 'report.docx'];
        yield ['../../My Photo (1).png', 'My Photo (1).png'];
        yield ['mixed/dir\\name.txt', 'name.txt'];
        yield ['folder/', ''];
        yield ['Résumé final.txt', 'Résumé final.txt'];
        yield ['', ''];
    }

    #[DataProvider('segments')]
    public function testLastSegment(string $input, string $expected): void
    {
        self::assertSame($expected, Names::lastSegment($input));
    }

    public function testFileNameReplacesOnlyQuoteCarriageReturnAndLineFeedWithUnderscore(): void
    {
        self::assertSame('Résumé _final___(1);x=y%22.txt', Names::fileName("Résumé \"final\"\r\n(1);x=y%22.txt"));
    }

    public function testFieldNameEscapesQuoteCarriageReturnAndLineFeed(): void
    {
        self::assertSame('Page%22Range', Names::fieldName('Page"Range'));
        self::assertSame('a%0D%0AX-Injected: 1', Names::fieldName("a\r\nX-Injected: 1"));
        self::assertSame('ImageResolution', Names::fieldName('ImageResolution'));
    }

    /** @return iterable<array{string}> */
    public static function unsafeNames(): iterable
    {
        yield [''];
        yield ['.'];
        yield ['..'];
        yield ['a/..'];
        yield ['a\\..'];
        yield ['dir/'];
        yield ["nul\0byte.pdf"];
    }

    #[DataProvider('unsafeNames')]
    public function testSafeOutputNameRefusesNamesOutsideTheFolder(string $name): void
    {
        $this->expectException(InvalidResponseException::class);
        Names::safeOutputName($name);
    }

    public function testSafeOutputNameKeepsOnlyTheLastPart(): void
    {
        self::assertSame('passwd', Names::safeOutputName('../../etc/passwd'));
        self::assertSame('x.pdf', Names::safeOutputName('C:\\temp\\x.pdf'));
        self::assertSame('.hidden.pdf', Names::safeOutputName('.hidden.pdf'));
    }

    public function testExtension(): void
    {
        self::assertSame('docx', Names::extension('dir.v2/report.docx'));
        self::assertSame('GZ', Names::extension('a.tar.GZ'));
        self::assertSame('', Names::extension('README'));
        self::assertSame('', Names::extension('.bashrc'));
        self::assertSame('', Names::extension('dir.d/file'));
        self::assertSame('', Names::extension('trailing.'));
    }
}
