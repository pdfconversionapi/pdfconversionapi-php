<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\ConversionResult;
use PdfConversionApi\Exception\FileSystemException;
use PdfConversionApi\Exception\InvalidResponseException;
use PdfConversionApi\Exception\StoredFileException;
use PdfConversionApi\OutputFile;
use PdfConversionApi\Tests\Support\Paths;
use PHPUnit\Framework\TestCase;

final class OutputFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::newTempDir('pdfconversionapi-php-out');
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Paths::removeTree($this->dir);
    }

    public function testSaveToAPathAndIntoAFolder(): void
    {
        $file = new OutputFile('report.pdf', 'pdf', 4, '%PDF');
        self::assertSame($this->dir . '/custom.pdf', $file->save($this->dir . '/custom.pdf'));
        self::assertSame('%PDF', file_get_contents($this->dir . '/custom.pdf'));
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'report.pdf', $file->save($this->dir . '/'));
        self::assertSame('%PDF', file_get_contents($this->dir . '/report.pdf'));
        self::assertSame($this->dir . '/again.pdf', $file->downloadTo($this->dir . '/again.pdf'));
        self::assertSame('%PDF', $file->download());
        self::assertSame(['again.pdf', 'custom.pdf', 'report.pdf'], self::listing($this->dir), 'no temporary files are left behind');
    }

    public function testSaveReplacesAnExistingFile(): void
    {
        file_put_contents($this->dir . '/a.pdf', 'old content that is longer');
        (new OutputFile('a.pdf', 'pdf', 3, 'new'))->save($this->dir . '/a.pdf');
        self::assertSame('new', file_get_contents($this->dir . '/a.pdf'));
    }

    public function testSaveIntoAMissingFolderFails(): void
    {
        $this->expectException(FileSystemException::class);
        (new OutputFile('a.pdf', 'pdf', 1, 'x'))->save($this->dir . '/missing/a.pdf');
    }

    public function testSaveAllCreatesTheFolder(): void
    {
        $result = new ConversionResult([
            new OutputFile('p-1.jpg', 'jpg', 2, 'p1'),
            new OutputFile('p-2.jpg', 'jpg', 2, 'p2'),
        ], 1, 0.5, 'id');
        $target = $this->dir . '/nested/out/';
        $paths = $result->saveAll($target);
        self::assertSame([$this->dir . '/nested/out' . DIRECTORY_SEPARATOR . 'p-1.jpg', $this->dir . '/nested/out' . DIRECTORY_SEPARATOR . 'p-2.jpg'], $paths);
        self::assertSame(['p-1.jpg', 'p-2.jpg'], self::listing($this->dir . '/nested/out'));
        self::assertSame('p2', file_get_contents($paths[1]));
    }

    public function testSaveAllWritesOnlyTheNameItself(): void
    {
        $result = new ConversionResult([new OutputFile('../../escape.pdf', 'pdf', 1, 'x')], 1, 0.1, null);
        $result->saveAll($this->dir . '/out');
        self::assertSame(['escape.pdf'], self::listing($this->dir . '/out'));
        self::assertFileDoesNotExist(dirname($this->dir) . '/escape.pdf');
    }

    public function testSaveAllRefusesUnsafeNames(): void
    {
        foreach (['..', '.', '', 'a/'] as $name) {
            try {
                (new ConversionResult([new OutputFile($name, 'pdf', 1, 'x')], 1, 0.1, null))->saveAll($this->dir . '/out');
                self::fail("accepted \"$name\"");
            } catch (InvalidResponseException) {
                self::assertSame([], self::listing($this->dir . '/out'));
            }
        }
    }

    public function testSaveAllIntoAFileFails(): void
    {
        file_put_contents($this->dir . '/file', 'x');
        $this->expectException(FileSystemException::class);
        (new ConversionResult([new OutputFile('a.pdf', 'pdf', 1, 'x')], 1, 0.1, null))->saveAll($this->dir . '/file');
    }

    public function testStoredFileHasNoData(): void
    {
        $file = new OutputFile('a.pdf', 'pdf', 9, url: 'https://example.test/v2/d/' . str_repeat('a', 32) . '/a.pdf', fileId: str_repeat('a', 32));
        self::assertTrue($file->stored);
        $this->expectException(StoredFileException::class);
        $file->data();
    }

    public function testDumpShowsTheFileNotItsData(): void
    {
        $dump = print_r(new OutputFile('a.pdf', 'pdf', 12, 'secret-bytes'), true);
        self::assertStringContainsString('a.pdf', $dump);
        self::assertStringNotContainsString('secret-bytes', $dump);
    }

    public function testFileHasEitherDataOrALink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OutputFile('a.pdf', 'pdf', 1);
    }

    /** @return list<string> */
    private static function listing(string $dir): array
    {
        $names = is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
        sort($names);

        return $names;
    }
}
