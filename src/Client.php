<?php

declare(strict_types=1);

namespace PdfConversionApi;

use PdfConversionApi\Exception\MissingApiKeyException;
use PdfConversionApi\Exception\PdfConversionApiException;
use PdfConversionApi\Internal\CurlTransport;
use PdfConversionApi\Internal\ErrorFactory;
use PdfConversionApi\Internal\HttpRequest;
use PdfConversionApi\Internal\MultipartBody;
use PdfConversionApi\Internal\Names;
use PdfConversionApi\Internal\Requester;
use PdfConversionApi\Internal\ResponseParser;
use PdfConversionApi\Internal\RetryPolicy;
use PdfConversionApi\Internal\Values;

/**
 * The PDF Conversion API client.
 *
 *     $client = new PdfConversionApi\Client();          // reads PDFCONVERSIONAPI_KEY
 *     $result = $client->convertFile('report.docx', 'pdf');
 *     $result->saveAll('out');                         // writes out/report.pdf
 *
 * Every call waits for its result. Failed connections, 429 and 503 answers with a short
 * Retry-After, and a first 500 are retried; see https://pdfconversionapi.com/docs/errors.
 * The client keeps no state between calls, so one instance can serve a whole application.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.pdfconversionapi.com';

    private const FORMAT = '/^[A-Za-z0-9-]{1,20}$/D';
    private const FILE_ID = '/^[0-9a-fA-F]{32}$/D';

    private readonly string $baseUrl;
    private readonly bool $hasApiKey;
    private readonly float $timeout;
    private readonly float $connectTimeout;
    private readonly int $maxRetries;
    private readonly float $maxRetryWait;
    private readonly Requester $requester;

    /**
     * @param string|null $apiKey your API key; when null or empty, the PDFCONVERSIONAPI_KEY
     *     environment variable. It may be missing: conversions() and downloads work without one.
     * @param string|null $baseUrl defaults to PDFCONVERSIONAPI_BASE_URL, else https://api.pdfconversionapi.com
     * @param float $timeout seconds to wait for each attempt's whole response
     * @param float $connectTimeout seconds to wait for a connection to open
     * @param int $maxRetries retries after the first attempt; 0 turns retries off
     * @param float $maxRetryWait a Retry-After longer than this many seconds is not waited for:
     *     the error is raised at once
     * @param array<int, mixed> $curlOptions extra CURLOPT_* options for every request, such as
     *     CURLOPT_PROXY or CURLOPT_CAINFO. The SDK's own options (URL, method, headers, body,
     *     timeouts, redirects) take precedence.
     * @param (callable(float): void)|null $sleep replaces the wait between retries; for tests
     *
     * @throws \InvalidArgumentException for an option out of range
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 240.0,
        float $connectTimeout = 10.0,
        int $maxRetries = 4,
        float $maxRetryWait = 60.0,
        array $curlOptions = [],
        ?callable $sleep = null,
    ) {
        $key = $apiKey !== null && $apiKey !== '' ? $apiKey : self::env('PDFCONVERSIONAPI_KEY');
        if ($key !== null && preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new \InvalidArgumentException('the API key contains a line break or another control character');
        }
        self::requirePositive('timeout', $timeout);
        self::requirePositive('connectTimeout', $connectTimeout);
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be 0 or more');
        }
        if (!is_finite($maxRetryWait) || $maxRetryWait < 0) {
            throw new \InvalidArgumentException('maxRetryWait must be 0 or more seconds');
        }
        foreach (array_keys($curlOptions) as $option) {
            if (!is_int($option)) {
                throw new \InvalidArgumentException('curlOptions keys must be CURLOPT_* constants');
            }
        }
        if ($curlOptions !== []) {
            $probe = curl_init();
            try {
                $accepted = $probe !== false && curl_setopt_array($probe, $curlOptions);
            } catch (\ValueError|\TypeError $e) {
                throw new \InvalidArgumentException('cURL refused one of the curlOptions: ' . $e->getMessage(), 0, $e);
            }
            if (!$accepted) {
                throw new \InvalidArgumentException('cURL refused one of the curlOptions');
            }
        }

        $this->baseUrl = self::cleanBaseUrl($baseUrl ?? self::env('PDFCONVERSIONAPI_BASE_URL') ?? self::DEFAULT_BASE_URL);
        $this->hasApiKey = $key !== null;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRetries = $maxRetries;
        $this->maxRetryWait = $maxRetryWait;
        $this->requester = new Requester(
            new CurlTransport(
                static fn (): ?string => $key,
                $connectTimeout,
                $curlOptions,
                'pdfconversionapi-php/' . self::VERSION . ' php/' . PHP_VERSION,
            ),
            new RetryPolicy($maxRetries, $maxRetryWait),
            $sleep !== null ? \Closure::fromCallable($sleep) : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            },
        );
    }

    /**
     * Converts one or several files: POST /v2/convert/{from}/to/{to}.
     *
     * A string is a path, never file content; use UploadFile::fromBytes() or ::fromStream() for
     * data in memory. One file is sent as `File`, several as repeated `Files` (for merge, join, zip
     * and images to PDF). With no file (null or []), the request has parameters only: right for
     * conversions that take none, such as `data/to/qrcode` with `Data`; the others answer 400.
     *
     * @param string $from the input format, such as `docx`, or `file`, `office`, `images`, `data`
     * @param string $to the output format, such as `pdf`, or a tool such as `merge` or `compress`
     * @param string|\SplFileInfo|UploadFile|list<string|\SplFileInfo|UploadFile>|null $files
     * @param array<string, string|int|float|bool|\Stringable|UploadFile|\SplFileInfo|null> $params
     *     conversion parameters, such as ['PageRange' => '1-3', 'ImageResolution' => 150]. A null
     *     value is left out. An UploadFile or SplFileInfo value is sent as a named file field,
     *     such as ImageFile.
     * @param string|null $fileName the output's name, without the extension (sent as FileName)
     * @param bool $storeFile keep the outputs on the API for 3 hours and return links instead of
     *     data (sent as StoreFile=true)
     * @param float|null $timeout seconds for this call, instead of the client's timeout
     *
     * @throws \InvalidArgumentException before sending, for a bad format or a bad file or value
     * @throws PdfConversionApiException
     */
    public function convert(
        string $from,
        string $to,
        string|\SplFileInfo|UploadFile|array|null $files = null,
        array $params = [],
        ?string $fileName = null,
        bool $storeFile = false,
        ?float $timeout = null,
    ): ConversionResult {
        $from = self::format($from, 'from');
        $to = self::format($to, 'to');
        $parts = self::formParts($files, $params, $fileName, $storeFile);
        $timeout = $this->callTimeout($timeout);
        $this->requireKey();

        $response = $this->requester->send(new HttpRequest(
            'POST',
            $this->baseUrl . '/v2/convert/' . $from . '/to/' . $to,
            HttpRequest::KIND_CONVERSION,
            true,
            $timeout,
            new MultipartBody($parts),
        ));
        if ($response->status !== 200) {
            throw ErrorFactory::fromResponse($response);
        }
        $requestId = $response->requestId();
        $body = $response->body;
        unset($response);

        return ResponseParser::conversionResult($body, $requestId, $this);
    }

    /**
     * Converts the file at $path, reading the input format from its extension:
     * convertFile('report.docx', 'pdf') is convert('docx', 'pdf', 'report.docx').
     *
     * @param array<string, string|int|float|bool|\Stringable|UploadFile|\SplFileInfo|null> $params
     *
     * @throws \InvalidArgumentException when the path has no extension, and as for convert()
     * @throws PdfConversionApiException
     */
    public function convertFile(
        string|\SplFileInfo $path,
        string $to,
        array $params = [],
        ?string $fileName = null,
        bool $storeFile = false,
        ?float $timeout = null,
    ): ConversionResult {
        $pathString = $path instanceof \SplFileInfo ? $path->getPathname() : $path;
        $extension = strtolower(Names::extension($pathString));
        if ($extension === '') {
            throw new \InvalidArgumentException(sprintf(
                '"%s" has no extension to read the input format from; call convert() with the format',
                Names::lastSegment($pathString),
            ));
        }

        return $this->convert($extension, $to, $path, $params, $fileName, $storeFile, $timeout);
    }

    /**
     * The key's plan, limits and this month's count: GET /v2/usage. Uses no conversion.
     *
     * @throws PdfConversionApiException
     */
    public function usage(): Usage
    {
        $this->requireKey();
        $response = $this->requester->send(new HttpRequest('GET', $this->baseUrl . '/v2/usage', HttpRequest::KIND_API, true, $this->timeout));
        if ($response->status !== 200) {
            throw ErrorFactory::fromResponse($response);
        }

        return ResponseParser::usage($response);
    }

    /**
     * Every conversion the API offers: GET /v2/info. Needs no key.
     *
     * @return list<Conversion>
     *
     * @throws PdfConversionApiException
     */
    public function conversions(): array
    {
        $response = $this->requester->send(new HttpRequest('GET', $this->baseUrl . '/v2/info', HttpRequest::KIND_API, false, $this->timeout));
        if ($response->status !== 200) {
            throw ErrorFactory::fromResponse($response);
        }

        return ResponseParser::conversions($response);
    }

    /**
     * Downloads a stored file's link (OutputFile::$url) into memory. No API key is sent.
     *
     * @throws \InvalidArgumentException for a URL that isn't http or https
     * @throws PdfConversionApiException NotFoundException once the link has expired or the file was deleted
     */
    public function download(string $url, ?float $timeout = null): string
    {
        $url = self::checkUrl($url);
        $response = $this->requester->send(new HttpRequest('GET', $url, HttpRequest::KIND_DOWNLOAD, false, $this->callTimeout($timeout)));
        if ($response->status !== 200) {
            throw ErrorFactory::fromResponse($response);
        }

        return $response->body;
    }

    /**
     * Downloads a stored file's link straight to disk, without holding it in memory. When $path is
     * a folder, the file is written inside it under the name at the end of the link. An existing
     * file is replaced only once the download is complete. No API key is sent.
     *
     * @return string the path written
     *
     * @throws \InvalidArgumentException for a URL that isn't http or https
     * @throws PdfConversionApiException
     */
    public function downloadTo(string $url, string $path, ?float $timeout = null): string
    {
        $url = self::checkUrl($url);
        if ($path === '') {
            throw new \InvalidArgumentException('the path is empty');
        }
        if (is_dir($path)) {
            $name = rawurldecode(Names::lastSegment((string) parse_url($url, PHP_URL_PATH)));
            $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . Names::safeOutputName($name);
        }
        $response = $this->requester->send(new HttpRequest('GET', $url, HttpRequest::KIND_DOWNLOAD, false, $this->callTimeout($timeout), sinkPath: $path));
        if ($response->status !== 200) {
            throw ErrorFactory::fromResponse($response);
        }

        return $path;
    }

    /**
     * Deletes a stored file before its link expires: DELETE /v2/d/{fileId}.
     *
     * @param string $fileId OutputFile::$fileId, 32 hex characters
     *
     * @return bool true when it was deleted, false when there was nothing to delete (unknown id,
     *     already deleted or expired, or another account's file)
     *
     * @throws \InvalidArgumentException unless $fileId is 32 hex characters
     * @throws PdfConversionApiException
     */
    public function deleteStoredFile(string $fileId): bool
    {
        if (preg_match(self::FILE_ID, $fileId) !== 1) {
            throw new \InvalidArgumentException('a stored file id is 32 hex characters');
        }
        $this->requireKey();
        $response = $this->requester->send(new HttpRequest(
            'DELETE',
            $this->baseUrl . '/v2/d/' . strtolower($fileId),
            HttpRequest::KIND_API,
            true,
            $this->timeout,
        ));

        return match ($response->status) {
            200, 204 => true,
            404 => false,
            default => throw ErrorFactory::fromResponse($response),
        };
    }

    /** The base URL requests go to, without a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Whether the client has an API key (from the constructor or the environment). */
    public function hasApiKey(): bool
    {
        return $this->hasApiKey;
    }

    /**
     * var_dump() and print_r() show the settings, never the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->hasApiKey ? '(set, hidden)' : '(not set)',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'maxRetries' => $this->maxRetries,
            'maxRetryWait' => $this->maxRetryWait,
        ];
    }

    /**
     * @param string|\SplFileInfo|UploadFile|array<mixed>|null $files
     * @param array<mixed> $params
     *
     * @return list<array{string, string|UploadFile}>
     */
    private static function formParts(string|\SplFileInfo|UploadFile|array|null $files, array $params, ?string $fileName, bool $storeFile): array
    {
        $inputs = is_array($files) ? $files : ($files === null ? [] : [$files]);
        if (!array_is_list($inputs)) {
            throw new \InvalidArgumentException('several input files must be passed as a list');
        }
        $field = count($inputs) === 1 ? 'File' : 'Files';
        $parts = [];
        foreach ($inputs as $input) {
            $parts[] = [$field, UploadFile::from($input)];
        }
        foreach ($params as $name => $value) {
            $name = (string) $name;
            if ($name === '') {
                throw new \InvalidArgumentException('a parameter name is empty');
            }
            $lower = strtolower($name);
            if (($fileName !== null && $lower === 'filename') || ($storeFile && $lower === 'storefile')) {
                continue; // the typed option wins
            }
            if ($value instanceof UploadFile || $value instanceof \SplFileInfo) {
                $parts[] = [$name, UploadFile::from($value)];
                continue;
            }
            $text = Values::format($name, $value);
            if ($text !== null) {
                $parts[] = [$name, $text];
            }
        }
        if ($fileName !== null) {
            $parts[] = ['FileName', $fileName];
        }
        if ($storeFile) {
            $parts[] = ['StoreFile', 'true'];
        }

        return $parts;
    }

    private function requireKey(): void
    {
        if (!$this->hasApiKey) {
            throw new MissingApiKeyException(
                'no API key: pass apiKey to the Client or set the PDFCONVERSIONAPI_KEY environment variable'
                . ' (get a key at https://app.pdfconversionapi.com)',
            );
        }
    }

    private function callTimeout(?float $timeout): float
    {
        if ($timeout === null) {
            return $this->timeout;
        }
        self::requirePositive('timeout', $timeout);

        return $timeout;
    }

    private static function format(string $format, string $which): string
    {
        if (preg_match(self::FORMAT, $format) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'the %s format must be 1 to 20 letters, digits or hyphens, "%s" given',
                $which,
                addcslashes($format, "\0..\37"),
            ));
        }

        return strtolower($format);
    }

    private static function requirePositive(string $name, float $seconds): void
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException($name . ' must be a positive number of seconds');
        }
    }

    private static function cleanBaseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
        ) {
            throw new \InvalidArgumentException('the base URL must be an http or https URL without credentials, query or fragment');
        }

        return rtrim($url, '/');
    }

    /**
     * @return non-empty-string
     */
    private static function checkUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('a download link must be an http or https URL');
        }

        return $url;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
