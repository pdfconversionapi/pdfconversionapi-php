<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Live;

use PdfConversionApi\Client;
use PdfConversionApi\Exception\AuthenticationException;
use PdfConversionApi\Exception\BadRequestException;
use PdfConversionApi\Exception\NotFoundException;
use PdfConversionApi\Tests\Support\Paths;
use PdfConversionApi\UploadFile;
use PHPUnit\Framework\TestCase;

/**
 * Live tests against the real API. They read PDFCONVERSIONAPI_LOCAL_BASE_URL and
 * PDFCONVERSIONAPI_LOCAL_KEY from conformance/.local-api.env (the API running locally), else
 * PDFCONVERSIONAPI_LIVE_BASE_URL and PDFCONVERSIONAPI_KEY from the environment (the release
 * workflow's run against the production API), and skip without either. The key alone doesn't
 * start them, and it is never printed. The input files are in conformance/samples. About 5
 * conversions per run.
 *
 *     composer test:live
 */
final class LiveApiTest extends TestCase
{
    private static ?Client $client = null;
    private static string $baseUrl = '';
    private static string $samples = '';
    private static string $skipReason = '';

    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        $dir = Paths::conformanceDir();
        $envFile = $dir === null ? null : $dir . '/.local-api.env';
        if ($envFile !== null && is_file($envFile)) {
            $env = [];
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m) === 1) {
                    $env[$m[1]] = trim($m[2], "\"'");
                }
            }
            $baseUrl = $env['PDFCONVERSIONAPI_LOCAL_BASE_URL'] ?? '';
            $key = $env['PDFCONVERSIONAPI_LOCAL_KEY'] ?? '';
            self::$skipReason = 'conformance/.local-api.env lacks the base URL or the key: live tests skipped';
        } else {
            $baseUrl = (string) getenv('PDFCONVERSIONAPI_LIVE_BASE_URL');
            $key = (string) getenv('PDFCONVERSIONAPI_KEY');
            self::$skipReason = 'no conformance/.local-api.env, and PDFCONVERSIONAPI_LIVE_BASE_URL or PDFCONVERSIONAPI_KEY is not set: live tests skipped';
        }
        if ($baseUrl === '' || $key === '') {
            return;
        }
        self::$baseUrl = rtrim($baseUrl, '/');
        self::$samples = Paths::samplesDir() ?? '';
        self::$client = new Client($key, self::$baseUrl);
    }

    protected function setUp(): void
    {
        if (self::$client === null) {
            self::markTestSkipped(self::$skipReason);
        }
        self::assertFileExists(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'the live tests\' sample files are missing');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            Paths::removeTree($dir);
        }
    }

    public function testDocxToPdf(): void
    {
        $result = $this->client()->convertFile(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'pdf');
        self::assertCount(1, $result);
        self::assertSame(1, $result->conversionCost);
        self::assertNotNull($result->requestId);
        self::assertSame('input-quarterly-report.pdf', $result->file->name);
        self::assertSame('pdf', $result->file->ext);
        self::assertStringStartsWith('%PDF', $result->file->data());
        self::assertSame($result->file->size, strlen($result->file->data()));

        $dir = $this->tempDir();
        self::assertSame([$dir . DIRECTORY_SEPARATOR . 'input-quarterly-report.pdf'], $result->saveAll($dir));
        self::assertSame($result->file->data(), file_get_contents($dir . '/input-quarterly-report.pdf'));
    }

    public function testPdfToJpgGivesOneFilePerPage(): void
    {
        $result = $this->client()->convertFile(self::$samples . '/pdf-to-jpg/input-season-guide.pdf', 'jpg', ['ImageResolution' => 72]);
        self::assertSame(['input-season-guide-1.jpg', 'input-season-guide-2.jpg'], array_map(static fn ($f) => $f->name, $result->files));
        foreach ($result->files as $file) {
            self::assertSame('jpg', $file->ext);
            self::assertStringStartsWith("\xFF\xD8\xFF", $file->data());
            self::assertSame($file->size, strlen($file->data()));
        }
    }

    public function testMergeSeveralFilesWithFileName(): void
    {
        $result = $this->client()->convert('pdf', 'merge', [
            self::$samples . '/merge/input-invoice.pdf',
            new \SplFileInfo(self::$samples . '/merge/input-terms.pdf'),
            UploadFile::fromPath(self::$samples . '/merge/input-timesheet.pdf'),
        ], fileName: 'october-billing');
        self::assertCount(1, $result);
        self::assertSame('october-billing.pdf', $result->file->name);
        self::assertStringStartsWith('%PDF', $result->file->data());
        // Three one-page PDFs make a three-page PDF.
        self::assertSame(3, preg_match_all('#/Type\s*/Page[^s]#', $result->file->data()));
    }

    public function testStoredFileDownloadAndDelete(): void
    {
        $client = $this->client();
        $result = $client->convertFile(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'pdf', storeFile: true);
        $file = $result->file;
        self::assertTrue($file->stored);
        self::assertNotNull($file->url);
        self::assertNotNull($file->fileId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $file->fileId);
        // Only follow the link when it points at the API under test, never at another host.
        self::assertStringStartsWith(self::$baseUrl . '/v2/d/' . $file->fileId . '/', $file->url, 'the stored file link points at another host');

        $bytes = $file->download();
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertSame($file->size, strlen($bytes));
        $dir = $this->tempDir();
        mkdir($dir);
        self::assertSame($dir . DIRECTORY_SEPARATOR . 'input-quarterly-report.pdf', $file->save($dir));
        self::assertSame($bytes, file_get_contents($dir . '/input-quarterly-report.pdf'));
        self::assertSame($dir . '/copy.pdf', $client->downloadTo($file->url, $dir . '/copy.pdf'));
        self::assertSame($bytes, file_get_contents($dir . '/copy.pdf'));

        self::assertTrue($client->deleteStoredFile($file->fileId));
        self::assertFalse($client->deleteStoredFile($file->fileId));
        try {
            $file->download();
            self::fail('downloaded a deleted file');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->getStatus());
        }
    }

    public function testNonAsciiAndQuotedFileNameFromAStream(): void
    {
        $stream = fopen(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'rb');
        self::assertIsResource($stream);
        try {
            $result = $this->client()->convert('docx', 'pdf', UploadFile::fromStream($stream, 'Résumé "final" (1).docx'));
        } finally {
            fclose($stream);
        }
        // The API keeps letters, digits, spaces, - _ and . and turns anything else into _.
        self::assertSame('Résumé _final_ _1_.pdf', $result->file->name);
    }

    public function testConversionsList(): void
    {
        $list = (new Client(baseUrl: self::$baseUrl))->conversions();
        self::assertGreaterThan(100, count($list));
        $pairs = array_map(static fn ($c) => $c->from . '/' . $c->to, $list);
        self::assertContains('docx/pdf', $pairs);
        self::assertContains('pdf/merge', $pairs);
    }

    public function testUnsupportedPairIsNotFound(): void
    {
        try {
            $this->client()->convertFile(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'dwg');
            self::fail('no error');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertSame('conversion from docx to dwg is not supported', $e->getApiMessage());
            self::assertNotNull($e->getRequestId());
        }
    }

    public function testWrongKeyIsAnAuthenticationError(): void
    {
        $client = new Client('pk_wrong_' . bin2hex(random_bytes(8)), self::$baseUrl);
        try {
            $client->convertFile(self::$samples . '/docx-to-pdf/input-quarterly-report.docx', 'pdf');
            self::fail('no error');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->getStatus());
            self::assertStringContainsString('valid API key', $e->getApiMessage());
            self::assertStringNotContainsString('pk_wrong_', (string) $e);
        }
    }

    public function testBadPageRangeIsABadRequest(): void
    {
        try {
            $this->client()->convertFile(self::$samples . '/pdf-to-jpg/input-season-guide.pdf', 'jpg', ['PageRange' => '3-1']);
            self::fail('no error');
        } catch (BadRequestException $e) {
            self::assertSame("PageRange '3-1' is not valid (example: 1-3,5)", $e->getApiMessage());
            self::assertStringStartsWith("HTTP 400: PageRange '3-1' is not valid (example: 1-3,5) (request id: ", $e->getMessage());
        }
    }

    private function client(): Client
    {
        self::assertNotNull(self::$client);

        return self::$client;
    }

    private function tempDir(): string
    {
        $dir = Paths::newTempDir('pdfconversionapi-php-live');
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
