<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\FileSystemException;
use PdfConversionApi\Exception\MissingApiKeyException;

/**
 * Sends one attempt of a request with ext-curl.
 *
 * @internal
 */
final class CurlTransport
{
    private const CURLE_WRITE_ERROR = 23;
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /**
     * @param \Closure(): ?string $apiKey
     * @param array<int, mixed> $curlOptions extra options applied before the SDK's own
     */
    public function __construct(
        private readonly \Closure $apiKey,
        private readonly float $connectTimeout,
        private readonly array $curlOptions,
        private readonly string $userAgent,
    ) {
    }

    /**
     * @param resource|null $sink where a download's body goes; null keeps the body in memory
     *
     * @throws TransportFailure when no response arrived
     * @throws FileSystemException when an input couldn't be read or the download couldn't be written
     */
    public function send(HttpRequest $request, mixed $sink = null): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportFailure('curl could not be started', TransportFailure::NO_RESPONSE);
        }
        if ($this->curlOptions !== []) {
            curl_setopt_array($handle, $this->curlOptions);
        }

        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: ' . ($request->kind === HttpRequest::KIND_DOWNLOAD ? '*/*' : 'application/json'),
        ];
        if ($request->authenticated) {
            $key = ($this->apiKey)();
            if ($key === null) {
                throw new MissingApiKeyException('this call needs an API key');
            }
            $headers[] = 'Authorization: Bearer ' . $key;
            unset($key);
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => self::milliseconds($request->timeout),
            CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds(min($this->connectTimeout, $request->timeout)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue, for example)
                } elseif (($colon = strpos($line, ':')) !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $body = $request->body;
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $body->contentType();
            $headers[] = 'Expect:';
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_CUSTOMREQUEST] = $request->method;
            $options[CURLOPT_INFILESIZE] = $body->open();
            $options[CURLOPT_READFUNCTION] = static fn ($curl, $stream, int $length): string => $body->read($length);
        } elseif ($request->method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $request->method;
        }
        if ($sink !== null) {
            $options[CURLOPT_FILE] = $sink;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        unset($headers);

        try {
            curl_setopt_array($handle, $options);
            unset($options);
            $result = curl_exec($handle);
        } finally {
            $body?->close();
        }

        if ($result === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            if ($body?->failure() !== null) {
                throw new FileSystemException((string) $body->failure());
            }
            if ($errno === self::CURLE_WRITE_ERROR && $sink !== null) {
                throw new FileSystemException('the download could not be written to disk');
            }
            $message = $error !== '' ? $error : 'curl error ' . $errno;
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
                // Before the request went out (the connection never opened) a timeout is a
                // connection failure, safe to retry; after it, it is the client's own timeout.
                $started = (int) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME_T) > 0;
                throw new TransportFailure($message, $started ? TransportFailure::TIMEOUT : TransportFailure::NO_RESPONSE);
            }
            throw new TransportFailure($message, $status >= 200 ? TransportFailure::BROKEN_RESPONSE : TransportFailure::NO_RESPONSE, $status);
        }

        return new HttpResponse(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $responseHeaders,
            is_string($result) ? $result : '',
        );
    }

    private static function milliseconds(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }
}
