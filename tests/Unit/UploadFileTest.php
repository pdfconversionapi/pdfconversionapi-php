<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\UploadFile;
use PHPUnit\Framework\TestCase;

final class UploadFileTest extends TestCase
{
    public function testNames(): void
    {
        self::assertSame('UploadFileTest.php', UploadFile::fromPath(__FILE__)->name);
        self::assertSame('UploadFileTest.php', UploadFile::fromPath(new \SplFileInfo(__FILE__))->name);
        self::assertSame('other.txt', UploadFile::fromPath(__FILE__, 'dir/other.txt')->name);
        self::assertSame('file', UploadFile::fromBytes('x')->name);
        self::assertSame('file', UploadFile::fromBytes('x', '')->name);
        self::assertSame('a.pdf', UploadFile::fromBytes('x', 'C:\\docs\\a.pdf')->name);
        self::assertSame('Résumé final (1).txt', UploadFile::fromBytes('x', 'Résumé final (1).txt')->name);
        self::assertSame('UploadFileTest.php', UploadFile::from(__FILE__)->name);
        $upload = UploadFile::fromBytes('x');
        self::assertSame($upload, UploadFile::from($upload));
    }

    public function testMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        UploadFile::fromPath(__DIR__ . '/missing.pdf');
    }

    public function testFolderIsNotAFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadFile::fromPath(__DIR__);
    }

    public function testStreamMustBeAStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (a string instead of a stream is the error under test) */
        UploadFile::fromStream('not a stream');
    }

    public function testFromRejectsOtherTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadFile::from(['a.pdf']);
    }
}
