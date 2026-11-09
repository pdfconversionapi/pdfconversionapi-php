<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Client;
use PdfConversionApi\ConversionResult;
use PdfConversionApi\Exception\ConnectionException;
use PdfConversionApi\Exception\MissingApiKeyException;
use PdfConversionApi\OutputFile;
use PdfConversionApi\UploadFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** Nothing listens on the discard port, so a request that is sent fails to connect. */
    private const UNREACHABLE = 'http://127.0.0.1:9';
    private const SECRET = 'sk-test-0123456789-secret';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PDFCONVERSIONAPI_KEY', 'PDFCONVERSIONAPI_BASE_URL'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    public function testDefaults(): void
    {
        $client = new Client();
        self::assertSame('https://api.pdfconversionapi.com', $client->getBaseUrl());
        self::assertFalse($client->hasApiKey());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Client::VERSION);
    }

    public function testReadsTheEnvironment(): void
    {
        putenv('PDFCONVERSIONAPI_KEY=' . self::SECRET);
        putenv('PDFCONVERSIONAPI_BASE_URL=http://localhost:8110/');
        $client = new Client();
        self::assertTrue($client->hasApiKey());
        self::assertSame('http://localhost:8110', $client->getBaseUrl());
        self::assertTrue((new Client(''))->hasApiKey(), 'an empty key falls back to the environment');
    }

    public function testReadsEnvArrayWhenGetenvIsEmpty(): void
    {
        $_ENV['PDFCONVERSIONAPI_KEY'] = self::SECRET;
        try {
            self::assertTrue((new Client())->hasApiKey());
        } finally {
            unset($_ENV['PDFCONVERSIONAPI_KEY']);
        }
    }

    public function testExplicitOptionsWinOverTheEnvironment(): void
    {
        putenv('PDFCONVERSIONAPI_BASE_URL=http://env.example.test');
        self::assertSame('https://mine.example.test/prefix', (new Client('k', 'https://mine.example.test/prefix///'))->getBaseUrl());
    }

    /**
     * @return iterable<string, array{array{apiKey?: string, baseUrl?: string, timeout?: float, connectTimeout?: float, maxRetries?: int, maxRetryWait?: float, curlOptions?: array<mixed>}}>
     */
    public static function badOptions(): iterable
    {
        yield 'ftp base URL' => [['baseUrl' => 'ftp://example.test']];
        yield 'relative base URL' => [['baseUrl' => 'api.example.test']];
        yield 'base URL with query' => [['baseUrl' => 'https://example.test/?a=1']];
        yield 'base URL with credentials' => [['baseUrl' => 'https://user:pw@example.test']];
        yield 'zero timeout' => [['timeout' => 0.0]];
        yield 'infinite timeout' => [['timeout' => INF]];
        yield 'negative connect timeout' => [['connectTimeout' => -1.0]];
        yield 'negative retries' => [['maxRetries' => -1]];
        yield 'negative retry wait' => [['maxRetryWait' => -1.0]];
        yield 'string curl option' => [['curlOptions' => ['CURLOPT_PROXY' => 'x']]];
        yield 'unknown curl option' => [['curlOptions' => [999999 => 'x']]];
        yield 'key with a line break' => [['apiKey' => "abc\r\nX-Injected: 1"]];
    }

    /**
     * @param array{apiKey?: string, baseUrl?: string, timeout?: float, connectTimeout?: float, maxRetries?: int, maxRetryWait?: float, curlOptions?: array<mixed>} $options
     */
    #[DataProvider('badOptions')]
    public function testRejectsBadOptions(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (string keys in curlOptions are the error under test) */
        new Client(...$options);
    }

    public function testAcceptsCurlOptions(): void
    {
        $client = new Client('k', curlOptions: [CURLOPT_PROXY => 'http://proxy.example.test:3128', CURLOPT_SSL_VERIFYPEER => true]);
        self::assertTrue($client->hasApiKey());
    }

    public function testKeyIsNeverShownWhenTheClientIsDumped(): void
    {
        $client = new Client(self::SECRET, self::UNREACHABLE);
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        self::assertStringContainsString('(set, hidden)', $dump);
        foreach ([$dump, print_r($client, true), var_export($client, true), (string) json_encode($client)] as $text) {
            self::assertStringNotContainsString(self::SECRET, $text);
        }
        $result = new ConversionResult([new OutputFile('a.pdf', 'pdf', 1, 'x', client: $client)], 1, 0.1, null);
        self::assertStringNotContainsString(self::SECRET, print_r($result, true));
        self::assertStringNotContainsString(self::SECRET, var_export($result, true));
        $this->expectException(\Throwable::class);
        serialize($client);
    }

    #[RequiresPhp('>= 8.2')]
    public function testKeyIsNotInTheTraceOfAConstructorError(): void
    {
        try {
            new Client(self::SECRET, 'ftp://example.test');
            self::fail('no error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString(self::SECRET, (string) $e);
            self::assertStringNotContainsString(self::SECRET, print_r($e->getTrace(), true));
        }
    }

    public function testMissingKeyIsRaisedBeforeAnythingIsSent(): void
    {
        $client = new Client(baseUrl: self::UNREACHABLE, maxRetries: 0);
        foreach ([
            static fn () => $client->convert('txt', 'pdf', UploadFile::fromBytes('x')),
            static fn () => $client->convert('data', 'qrcode', params: ['Data' => 'x']),
            static fn () => $client->usage(),
            static fn () => $client->deleteStoredFile(str_repeat('a', 32)),
        ] as $call) {
            try {
                $call();
                self::fail('no error');
            } catch (MissingApiKeyException $e) {
                self::assertStringContainsString('PDFCONVERSIONAPI_KEY', $e->getMessage());
            }
        }
    }

    public function testCallsWithoutKeyAreSent(): void
    {
        $client = new Client(baseUrl: self::UNREACHABLE, maxRetries: 0);
        foreach ([
            static fn () => $client->conversions(),
            static fn () => $client->download(self::UNREACHABLE . '/v2/d/00000000000000000000000000000000/a.pdf'),
        ] as $call) {
            try {
                $call();
                self::fail('no error');
            } catch (ConnectionException $e) {
                self::assertStringContainsString('127.0.0.1:9', $e->getMessage());
            }
        }
    }

    public function testConnectionFailuresAreRetriedWithJitter(): void
    {
        $sleeps = [];
        $client = new Client(self::SECRET, self::UNREACHABLE, maxRetries: 2, sleep: static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        try {
            $client->download(self::UNREACHABLE . '/v2/d/0123456789abcdef0123456789abcdef/secret-name.pdf');
            self::fail('no error');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('after 3 attempts', $e->getMessage());
            self::assertStringNotContainsString('0123456789abcdef', $e->getMessage(), 'a stored file link never appears in a message');
            self::assertStringNotContainsString(self::SECRET, (string) $e);
        }
        self::assertCount(2, $sleeps);
        self::assertLessThan(2.0, $sleeps[0]);
        self::assertLessThan(4.0, $sleeps[1]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badFormats(): iterable
    {
        yield 'path traversal' => ['../etc', 'pdf'];
        yield 'empty' => ['', 'pdf'];
        yield 'too long' => [str_repeat('a', 21), 'pdf'];
        yield 'space' => ['do cx', 'pdf'];
        yield 'slash in to' => ['pdf', 'to/pdf'];
        yield 'query' => ['pdf', 'jpg?x=1'];
        yield 'unicode' => ['dócx', 'pdf'];
        yield 'trailing newline' => ["pdf\n", 'jpg'];
    }

    #[DataProvider('badFormats')]
    public function testFormatsAreCheckedBeforeSending(string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Client('k', self::UNREACHABLE, maxRetries: 0))->convert($from, $to, UploadFile::fromBytes('x'));
    }

    public function testConvertFileNeedsAnExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('README');
        (new Client('k', self::UNREACHABLE))->convertFile('/tmp/some.dir/README', 'pdf');
    }

    public function testFilesMustBeAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (a map instead of a list is the error under test) */
        (new Client('k', self::UNREACHABLE))->convert('pdf', 'merge', ['a' => UploadFile::fromBytes('x')]);
    }

    public function testFilesMustBePathsOrUploadFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (an int among the files is the error under test) */
        (new Client('k', self::UNREACHABLE))->convert('pdf', 'merge', [UploadFile::fromBytes('x'), 42]);
    }

    public function testParameterValuesAreChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PageRange');
        /** @phpstan-ignore argument.type (an array value is the error under test) */
        (new Client('k', self::UNREACHABLE))->convert('pdf', 'jpg', UploadFile::fromBytes('x'), ['PageRange' => [1, 2]]);
    }

    /** @return iterable<array{string}> */
    public static function badFileIds(): iterable
    {
        yield ['abc'];
        yield [str_repeat('g', 32)];
        yield [str_repeat('a', 33)];
        yield ['../' . str_repeat('a', 29)];
    }

    #[DataProvider('badFileIds')]
    public function testFileIdIsChecked(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Client('k', self::UNREACHABLE))->deleteStoredFile($id);
    }

    public function testDownloadNeedsAnHttpUrl(): void
    {
        $client = new Client('k', self::UNREACHABLE);
        foreach (['file:///etc/passwd', '/v2/d/x/y', 'ftp://example.test/a', ''] as $url) {
            try {
                $client->download($url);
                self::fail("accepted $url");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('http', $e->getMessage());
            }
        }
    }

    public function testPerCallTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Client('k', self::UNREACHABLE))->convert('txt', 'pdf', UploadFile::fromBytes('x'), timeout: 0.0);
    }
}
