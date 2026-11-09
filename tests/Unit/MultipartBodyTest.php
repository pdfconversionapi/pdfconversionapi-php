<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Exception\FileSystemException;
use PdfConversionApi\Internal\MultipartBody;
use PdfConversionApi\Tests\Support\Paths;
use PdfConversionApi\UploadFile;
use PHPUnit\Framework\TestCase;

final class MultipartBodyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Paths::newTempDir('pdfconversionapi-php-mp');
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Paths::removeTree($this->dir);
    }

    public function testExactBytes(): void
    {
        $body = new MultipartBody([
            ['File', UploadFile::fromBytes("%PDF\r\n--b--", "Résumé \"final\"\r\n(1).pdf")],
            ['PageRange', '1-3'],
            ['Note', "two\r\nlines"],
            ["Bad\"Name\r\n", 'x'],
            ['Logo"File', UploadFile::fromBytes('PNG', 'l"o"go.png')],
        ], 'b');
        self::assertSame('multipart/form-data; boundary=b', $body->contentType());
        $expected = "--b\r\n"
            . "Content-Disposition: form-data; name=\"File\"; filename=\"Résumé _final___(1).pdf\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . "%PDF\r\n--b--\r\n"
            . "--b\r\n"
            . "Content-Disposition: form-data; name=\"PageRange\"\r\n\r\n"
            . "1-3\r\n"
            . "--b\r\n"
            . "Content-Disposition: form-data; name=\"Note\"\r\n\r\n"
            . "two\r\nlines\r\n"
            . "--b\r\n"
            . "Content-Disposition: form-data; name=\"Bad%22Name%0D%0A\"\r\n\r\n"
            . "x\r\n"
            . "--b\r\n"
            . "Content-Disposition: form-data; name=\"Logo%22File\"; filename=\"l_o_go.png\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . "PNG\r\n"
            . "--b--\r\n";
        self::assertSame(strlen($expected), $body->open());
        self::assertSame($expected, self::readAll($body, 7));
    }

    public function testNoPartsIsAClosingBoundaryOnly(): void
    {
        $body = new MultipartBody([], 'b');
        self::assertSame(7, $body->open());
        self::assertSame("--b--\r\n", self::readAll($body, 100));
    }

    public function testRandomBoundary(): void
    {
        $one = new MultipartBody([]);
        $two = new MultipartBody([]);
        self::assertMatchesRegularExpression('/^pdfconversionapi-[0-9a-f]{32}$/', $one->boundary);
        self::assertNotSame($one->boundary, $two->boundary);
    }

    public function testPathIsReadAgainForEveryAttempt(): void
    {
        $path = $this->dir . '/in.txt';
        file_put_contents($path, 'first');
        $body = new MultipartBody([['File', UploadFile::fromPath($path)]], 'b');
        $first = self::readAll($body, 2, $body->open());
        file_put_contents($path, 'second version');
        $second = self::readAll($body, 3, $body->open());
        self::assertStringContainsString('filename="in.txt"', $first);
        self::assertStringContainsString("\r\n\r\nfirst\r\n", $first);
        self::assertStringContainsString("\r\n\r\nsecond version\r\n", $second);
        $body->close();
    }

    public function testLargeFileIsStreamedInChunks(): void
    {
        $path = $this->dir . '/big.bin';
        $data = random_bytes(3 * 1048576 + 17);
        file_put_contents($path, $data);
        $body = new MultipartBody([['Files', UploadFile::fromPath($path)], ['Files', UploadFile::fromBytes('tail', 't.bin')]], 'b');
        $length = $body->open();
        $read = '';
        $largest = 0;
        while (($chunk = $body->read(16 * 1048576)) !== '') {
            $largest = max($largest, strlen($chunk));
            $read .= $chunk;
        }
        self::assertSame($length, strlen($read));
        self::assertLessThanOrEqual(1048576, $largest, 'file content is read in chunks, not all at once');
        self::assertStringContainsString("\r\n\r\n" . $data . "\r\n--b\r\n", $read);
        $body->close();
    }

    public function testSeekableStreamIsRewoundToWhereItStarted(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'skip:payload');
        fseek($stream, 5);
        $body = new MultipartBody([['File', UploadFile::fromStream($stream, 's.txt')]], 'b');
        $first = self::readAll($body, 4, $body->open());
        $second = self::readAll($body, 4, $body->open());
        self::assertSame($first, $second);
        self::assertStringContainsString("\r\n\r\npayload\r\n", $first);
        fclose($stream);
    }

    public function testNonSeekableStreamIsReadOnceIntoMemory(): void
    {
        $stream = popen('printf piped', 'r');
        self::assertIsResource($stream);
        $file = UploadFile::fromStream($stream);
        pclose($stream);
        self::assertSame('file', $file->name);
        $body = new MultipartBody([['File', $file]], 'b');
        $first = self::readAll($body, 3, $body->open());
        $second = self::readAll($body, 3, $body->open());
        self::assertSame($first, $second);
        self::assertStringContainsString("\r\n\r\npiped\r\n", $first);
    }

    public function testFileThatShrinksWhileSendingIsReported(): void
    {
        $path = $this->dir . '/shrinks.txt';
        file_put_contents($path, str_repeat('x', 100));
        $body = new MultipartBody([['File', UploadFile::fromPath($path)]], 'b');
        $body->open();
        $body->read(10); // the headers
        file_put_contents($path, 'short');
        self::readAll($body, 1000);
        self::assertNotNull($body->failure());
        $body->close();
    }

    public function testDeletedInputIsAFileSystemError(): void
    {
        $path = $this->dir . '/gone.txt';
        file_put_contents($path, 'x');
        $body = new MultipartBody([['File', UploadFile::fromPath($path)]], 'b');
        unlink($path);
        $this->expectException(FileSystemException::class);
        $body->open();
    }

    private static function readAll(MultipartBody $body, int $chunk, ?int $length = null): string
    {
        $text = '';
        while (($part = $body->read($chunk)) !== '') {
            self::assertLessThanOrEqual($chunk, strlen($part));
            $text .= $part;
        }
        if ($length !== null) {
            self::assertSame($length, strlen($text));
        }

        return $text;
    }
}
