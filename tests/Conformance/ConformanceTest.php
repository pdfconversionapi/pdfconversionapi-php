<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Conformance;

use PdfConversionApi\Client;
use PdfConversionApi\ConversionResult;
use PdfConversionApi\Exception;
use PdfConversionApi\Tests\Support\MockServer;
use PdfConversionApi\Tests\Support\Paths;
use PdfConversionApi\UploadFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every case in the shared conformance suite (conformance/cases.json) against
 * conformance/mock-server.mjs. The case format is described in conformance/README.md.
 */
final class ConformanceTest extends TestCase
{
    /** Canonical error names (SPEC.md section 9) and the PHP class each one maps to. */
    private const ERRORS = [
        'ArgumentError' => \InvalidArgumentException::class,
        'PdfConversionApiError' => Exception\PdfConversionApiException::class,
        'MissingApiKeyError' => Exception\MissingApiKeyException::class,
        'StoredFileError' => Exception\StoredFileException::class,
        'ConnectionError' => Exception\ConnectionException::class,
        'TimeoutError' => Exception\TimeoutException::class,
        'InvalidResponseError' => Exception\InvalidResponseException::class,
        'ApiError' => Exception\ApiException::class,
        'BadRequestError' => Exception\BadRequestException::class,
        'AuthenticationError' => Exception\AuthenticationException::class,
        'PermissionDeniedError' => Exception\PermissionDeniedException::class,
        'NotFoundError' => Exception\NotFoundException::class,
        'PayloadTooLargeError' => Exception\PayloadTooLargeException::class,
        'UnprocessableEntityError' => Exception\UnprocessableEntityException::class,
        'RateLimitError' => Exception\RateLimitException::class,
        'InternalServerError' => Exception\InternalServerErrorException::class,
        'ServiceUnavailableError' => Exception\ServiceUnavailableException::class,
        'GatewayTimeoutError' => Exception\GatewayTimeoutException::class,
    ];

    private const ENV_NAMES = ['PDFCONVERSIONAPI_KEY', 'PDFCONVERSIONAPI_BASE_URL'];

    private static ?MockServer $server = null;
    private static string $dir = '';
    private static string $key = '';

    /** @var list<float> waits the client asked for in this case */
    private array $sleeps = [];

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<resource> */
    private array $streams = [];

    public static function setUpBeforeClass(): void
    {
        $dir = Paths::conformanceDir();
        if ($dir === null) {
            self::fail('conformance/cases.json was not found: set PDFCONVERSIONAPI_CONFORMANCE_DIR');
        }
        self::$dir = $dir;
        $suite = json_decode((string) file_get_contents($dir . '/cases.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($suite);
        self::$key = self::str($suite['key']);
        self::$server = MockServer::start($dir);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        // Cases run with exactly the key and base URL they give, whatever the shell has set.
        foreach (self::ENV_NAMES as $name) {
            $this->savedEnv[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$env, $envArray, $server]) {
            if ($env !== false) {
                putenv($name . '=' . $env);
            }
            if ($envArray !== null) {
                $_ENV[$name] = $envArray;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        foreach ($this->tempDirs as $dir) {
            Paths::removeTree($dir);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $dir = Paths::conformanceDir();
        if ($dir === null) {
            return [];
        }
        $suite = json_decode((string) file_get_contents($dir . '/cases.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($suite);
        self::assertIsArray($suite['cases']);
        foreach ($suite['cases'] as $case) {
            $case = self::map($case);
            yield self::str($case['id']) => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $key = self::$key; // not a test argument, so a stack trace that showed it would come from the SDK
        $server = self::$server;
        self::assertNotNull($server);
        $expect = self::map($case['expect'] ?? []);
        $client = $this->client(self::map($case['client'] ?? []), $key, $server->url);
        $call = self::str($case['call']);
        $mockId = self::str($case['id']) . '-' . bin2hex(random_bytes(6));

        $params = [];
        foreach (self::map($case['params'] ?? []) as $name => $value) {
            // A named file is a descriptor; in params a path goes as SplFileInfo (a string is text).
            if (is_array($value)) {
                $params[$name] = $this->input(self::map($value), true);
            } else {
                self::assertTrue($value === null || is_scalar($value), "params.$name");
                $params[$name] = $value;
            }
        }
        if ($call === 'convert' || $call === 'stored_file_flow') {
            $params['MockId'] = $mockId;
        }
        $files = null; // no `files` key: call without files
        if (array_key_exists('files', $case)) {
            $inputs = array_map(fn (mixed $d): UploadFile|\SplFileInfo|string => $this->input(self::map($d)), self::list($case['files']));
            $files = count($inputs) === 1 ? $inputs[0] : $inputs;
        }
        $options = self::map($case['options'] ?? []);
        $fileName = isset($options['file_name']) ? self::str($options['file_name']) : null;
        $storeFile = ($options['store_file'] ?? false) === true;

        $result = null;
        $error = null;
        try {
            $result = match ($call) {
                'convert', 'stored_file_flow' => $client->convert(self::str($case['from']), self::str($case['to']), $files, $params, $fileName, $storeFile),
                'usage' => $client->usage(),
                'conversions' => $client->conversions(),
                'delete_stored_file' => $client->deleteStoredFile(self::str($case['file_id'])),
                default => self::fail('unknown call ' . $call),
            };
        } catch (\Throwable $e) {
            $error = $e;
        }

        if (isset($expect['error'])) {
            self::assertNotNull($error, 'expected an error, but the call succeeded');
            $this->checkError($error, self::map($expect['error']), $key);
        } elseif ($error !== null) {
            throw $error;
        }

        if (array_key_exists('attempts', $expect)) {
            self::assertSame($expect['attempts'], $server->attempts($mockId), 'requests the mock received');
        }
        if (isset($expect['min_sleeps'])) {
            $minimums = self::map($expect['min_sleeps']);
            self::assertCount(count($minimums), $this->sleeps, 'number of waits between retries');
            foreach (array_values($minimums) as $i => $minimum) {
                self::assertIsNumeric($minimum);
                self::assertGreaterThanOrEqual((float) $minimum, $this->sleeps[$i], 'wait ' . ($i + 1));
            }
        }
        if (isset($expect['result'])) {
            self::assertInstanceOf(ConversionResult::class, $result);
            $this->checkResult($result, self::map($expect['result']));
        }
        if (isset($expect['echo'])) {
            self::assertInstanceOf(ConversionResult::class, $result);
            $this->checkEcho($result, self::map($expect['echo']));
        }
        if (isset($expect['save_all'])) {
            self::assertInstanceOf(ConversionResult::class, $result);
            $this->checkSaveAll($result, self::map($expect['save_all']));
        }
        if (isset($expect['conversions'])) {
            self::assertIsArray($result);
            $this->checkConversions($result, self::map($expect['conversions']));
        }
        if (isset($expect['usage'])) {
            self::assertInstanceOf(\PdfConversionApi\Usage::class, $result);
            $this->checkUsage($result, self::map($expect['usage']));
        }
        if (array_key_exists('deleted', $expect)) {
            self::assertSame($expect['deleted'], $result);
        }
        if ($call === 'stored_file_flow') {
            self::assertInstanceOf(ConversionResult::class, $result);
            $this->checkStoredFileFlow($client, $result, $expect);
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function client(array $spec, string $key, string $url): Client
    {
        return new Client(
            apiKey: array_key_exists('api_key', $spec) ? (is_string($spec['api_key']) ? $spec['api_key'] : null) : $key,
            baseUrl: $url . (is_string($spec['base_url_suffix'] ?? null) ? $spec['base_url_suffix'] : ''),
            timeout: is_numeric($spec['timeout_seconds'] ?? null) ? (float) $spec['timeout_seconds'] : 240.0,
            maxRetries: is_int($spec['max_retries'] ?? null) ? $spec['max_retries'] : 4,
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /**
     * An input descriptor, in the form the SDK takes it.
     *
     * @param array<string, mixed> $descriptor
     */
    private function input(array $descriptor, bool $pathAsObject = false): UploadFile|\SplFileInfo|string
    {
        $name = isset($descriptor['name']) ? self::str($descriptor['name']) : null;
        if (isset($descriptor['path'])) {
            $path = $this->fixturePath(self::str($descriptor['path']));

            return $pathAsObject ? new \SplFileInfo($path) : $path;
        }
        if (isset($descriptor['bytes'])) {
            return UploadFile::fromBytes($this->fixture(self::str($descriptor['bytes'])), $name);
        }
        if (isset($descriptor['stream'])) {
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, $this->fixture(self::str($descriptor['stream'])));
            rewind($stream);
            $this->streams[] = $stream;

            return UploadFile::fromStream($stream, $name);
        }
        if (isset($descriptor['bytes_utf8'])) {
            return UploadFile::fromBytes(self::str($descriptor['bytes_utf8']), $name);
        }
        self::fail('unknown input descriptor ' . json_encode($descriptor));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function checkError(\Throwable $error, array $spec, string $key): void
    {
        $type = self::str($spec['type']);
        self::assertArrayHasKey($type, self::ERRORS, 'unknown canonical error ' . $type);
        self::assertInstanceOf(self::ERRORS[$type], $error, sprintf('expected %s, got %s: %s', $type, $error::class, $error->getMessage()));
        if ($type !== 'ArgumentError') {
            self::assertInstanceOf(Exception\PdfConversionApiException::class, $error);
        }
        self::assertStringNotContainsString($key, $error->getMessage(), 'the key must never appear in a message');
        self::assertStringNotContainsString($key, $error->__toString(), 'the key must never appear in a message');

        if (array_key_exists('status', $spec) || array_key_exists('message', $spec) || array_key_exists('message_contains', $spec)
            || array_key_exists('retry_after', $spec) || array_key_exists('request_id', $spec)) {
            self::assertInstanceOf(Exception\ApiException::class, $error);
            if (array_key_exists('status', $spec)) {
                self::assertSame($spec['status'], $error->getStatus());
                self::assertSame($spec['status'], $error->getCode());
            }
            if (array_key_exists('message', $spec)) {
                self::assertSame($spec['message'], $error->getApiMessage());
            }
            if (array_key_exists('message_contains', $spec)) {
                self::assertStringContainsString(self::str($spec['message_contains']), $error->getApiMessage());
            }
            if (array_key_exists('retry_after', $spec)) {
                self::assertSame($spec['retry_after'], $error->getRetryAfter());
            }
            if (($spec['request_id'] ?? null) === true) {
                self::assertNotNull($error->getRequestId());
                self::assertNotSame('', $error->getRequestId());
                self::assertStringContainsString('(request id: ' . $error->getRequestId() . ')', $error->getMessage());
            } elseif (($spec['request_id'] ?? null) === false) {
                self::assertNull($error->getRequestId());
            }
            self::assertStringStartsWith('HTTP ' . $error->getStatus() . ': ' . $error->getApiMessage(), $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function checkResult(ConversionResult $result, array $spec): void
    {
        if (array_key_exists('conversion_cost', $spec)) {
            self::assertSame($spec['conversion_cost'], $result->conversionCost);
        }
        if (($spec['request_id'] ?? null) === true) {
            self::assertIsString($result->requestId);
            self::assertNotSame('', $result->requestId);
        }
        if (!isset($spec['files'])) {
            return;
        }
        $expected = array_values(self::map($spec['files']));
        self::assertCount(count($expected), $result->files);
        self::assertCount(count($expected), $result);
        self::assertSame($result->files[0], $result->file);
        foreach ($expected as $i => $fileSpec) {
            $fileSpec = self::map($fileSpec);
            $file = $result->files[$i];
            foreach (['name' => $file->name, 'ext' => $file->ext, 'size' => $file->size] as $field => $actual) {
                if (array_key_exists($field, $fileSpec)) {
                    self::assertSame($fileSpec[$field], $actual, "files[$i].$field");
                }
            }
            if (isset($fileSpec['data_fixture'])) {
                self::assertSame($this->fixture(self::str($fileSpec['data_fixture'])), $file->data());
            }
            if (isset($fileSpec['data_utf8'])) {
                self::assertSame($fileSpec['data_utf8'], $file->data());
            }
            if (($fileSpec['stored'] ?? null) === true) {
                self::assertTrue($file->stored);
                self::assertNotNull($file->url);
                self::assertNotNull($file->fileId);
                $this->assertThrows(Exception\StoredFileException::class, static fn () => $file->data());
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function checkEcho(ConversionResult $result, array $spec): void
    {
        $first = $result->file;
        $echo = json_decode($first->stored ? $first->download() : $first->data(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($echo);
        $headers = self::map($echo['headers'] ?? []);
        foreach (['method', 'path', 'query'] as $field) {
            if (array_key_exists($field, $spec)) {
                self::assertSame($spec[$field], $echo[$field], "echo $field");
            }
        }
        if (array_key_exists('authorization', $spec)) {
            self::assertSame($spec['authorization'], $headers['authorization']);
        }
        if (isset($spec['user_agent_prefix'])) {
            self::assertStringStartsWith(self::prefix($spec['user_agent_prefix']), self::str($headers['user-agent']));
        }
        if (isset($spec['content_type_prefix'])) {
            self::assertStringStartsWith(self::prefix($spec['content_type_prefix']), self::str($headers['content-type']));
        }
        $fields = self::list($echo['fields'] ?? []);
        $position = 0;
        foreach (self::map($spec['fields'] ?? []) as $wanted) {
            $wanted = self::map($wanted);
            $found = false;
            for ($i = $position; $i < count($fields); $i++) {
                if (self::fieldMatches($wanted, self::map($fields[$i]))) {
                    $found = true;
                    $position = $i + 1;
                    break;
                }
            }
            self::assertTrue($found, 'field ' . json_encode($wanted, JSON_UNESCAPED_UNICODE) . ' in order, in ' . json_encode($fields, JSON_UNESCAPED_UNICODE));
        }
        foreach (self::list($spec['absent_fields'] ?? []) as $absent) {
            $absent = self::str($absent);
            foreach ($fields as $field) {
                self::assertNotSame($absent, self::map($field)['name'] ?? null, "field $absent must not be sent");
            }
        }
    }

    /**
     * @param array<string, mixed> $spec file name => content
     */
    private function checkSaveAll(ConversionResult $result, array $spec): void
    {
        $dir = Paths::newTempDir('pdfconversionapi-php-save');
        $this->tempDirs[] = $dir;
        $paths = $result->saveAll($dir);
        $names = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        $expected = array_map('strval', array_keys($spec));
        sort($names);
        sort($expected);
        self::assertSame($expected, $names);
        self::assertCount(count($spec), $paths);
        foreach ($spec as $name => $content) {
            self::assertSame($content, file_get_contents($dir . DIRECTORY_SEPARATOR . $name));
        }
    }

    /**
     * @param list<mixed> $list
     * @param array<string, mixed> $spec
     */
    private function checkConversions(array $list, array $spec): void
    {
        self::assertCount(self::int($spec['count']), $list);
        $first = $list[0];
        self::assertInstanceOf(\PdfConversionApi\Conversion::class, $first);
        $actual = ['from' => $first->from, 'to' => $first->to, 'title' => $first->title, 'category' => $first->category, 'inputs' => $first->inputs];
        foreach (self::map($spec['first']) as $field => $value) {
            self::assertSame($value, $actual[$field], "first.$field");
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function checkUsage(\PdfConversionApi\Usage $usage, array $spec): void
    {
        $actual = [
            'plan' => $usage->plan,
            'included' => $usage->included,
            'used' => $usage->used,
            'remaining' => $usage->remaining,
            'overage' => $usage->overage,
            'running' => $usage->running,
            'concurrent_limit' => $usage->concurrentLimit,
            'max_upload_mb' => $usage->maxUploadMb,
            'period_start' => $usage->periodStart,
            'period_end' => $usage->periodEnd,
        ];
        foreach ($spec as $field => $value) {
            self::assertArrayHasKey($field, $actual);
            if ($actual[$field] instanceof \DateTimeImmutable) {
                self::assertSame((new \DateTimeImmutable(self::str($value)))->getTimestamp(), $actual[$field]->getTimestamp(), $field);
                self::assertSame('UTC', $actual[$field]->getTimezone()->getName(), $field);
            } else {
                self::assertSame($value, $actual[$field], $field);
            }
        }
    }

    /**
     * @param array<string, mixed> $expect
     */
    private function checkStoredFileFlow(Client $client, ConversionResult $result, array $expect): void
    {
        $file = $result->file;
        $this->assertThrows(self::ERRORS[self::str($expect['data_access_error'])], static fn () => $file->data());
        self::assertSame($this->fixture(self::str($expect['download_fixture'])), $file->download());
        self::assertNotNull($file->fileId);
        self::assertSame($expect['first_delete'], $client->deleteStoredFile($file->fileId));
        self::assertSame($expect['second_delete'], $client->deleteStoredFile($file->fileId));
        $this->assertThrows(self::ERRORS[self::str($expect['download_after_delete_error'])], static fn () => $file->download());

        // The streamed form gives the same answers.
        $dir = Paths::newTempDir('pdfconversionapi-php-flow');
        $this->tempDirs[] = $dir;
        mkdir($dir);
        $this->assertThrows(Exception\NotFoundException::class, static fn () => $file->save($dir));
        self::assertSame([], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), 'a failed download leaves no file behind');
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): mixed $call
     */
    private function assertThrows(string $class, \Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());

            return;
        }
        self::fail("expected $class");
    }

    private function fixturePath(string $name): string
    {
        return self::$dir . '/fixtures/' . $name;
    }

    private function fixture(string $name): string
    {
        $data = file_get_contents($this->fixturePath($name));
        self::assertIsString($data);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return non-empty-string
     */
    private static function prefix(mixed $value): string
    {
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }

    private static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /**
     * Whether every key in $wanted has exactly that value in $actual.
     *
     * @param array<string, mixed> $wanted
     * @param array<string, mixed> $actual
     */
    private static function fieldMatches(array $wanted, array $actual): bool
    {
        foreach ($wanted as $key => $value) {
            if (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
