<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\ConnectionException;
use PdfConversionApi\Exception\TimeoutException;

/**
 * The retry loop every call goes through, downloads included.
 *
 * @internal
 */
final class Requester
{
    /** Error bodies of failed downloads are kept up to this size. */
    private const ERROR_BODY_LIMIT = 1048576;

    /**
     * @param \Closure(float): void $sleep
     */
    public function __construct(
        private readonly CurlTransport $transport,
        private readonly RetryPolicy $policy,
        private readonly \Closure $sleep,
    ) {
    }

    /**
     * Sends the request, retrying as RetryPolicy says, and returns the last response, whatever
     * its status. A download with a sink path is written there only on a 200.
     *
     * @throws TimeoutException on the client's own timeout
     * @throws ConnectionException when no attempt got a response
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $retried500 = false;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->attempt($request);
            } catch (TransportFailure $failure) {
                if ($failure->kind === TransportFailure::TIMEOUT) {
                    throw new TimeoutException(self::timeoutMessage($request), 0, $failure);
                }
                if ($failure->kind === TransportFailure::BROKEN_RESPONSE) {
                    throw new ConnectionException(self::brokenMessage($request, $failure), 0, $failure);
                }
                $wait = $this->policy->afterNoResponse($attempt, RetryPolicy::jitter($attempt));
                if ($wait === null) {
                    throw new ConnectionException(sprintf(
                        'no response from %s after %d %s: %s',
                        self::origin($request->url),
                        $attempt,
                        $attempt === 1 ? 'attempt' : 'attempts',
                        $failure->getMessage(),
                    ), 0, $failure);
                }
                ($this->sleep)($wait);
                continue;
            }

            $wait = $this->policy->afterStatus($attempt, $response->status, $response->header('retry-after'), $retried500, RetryPolicy::jitter($attempt));
            if ($wait === null) {
                return $response;
            }
            $retried500 = $retried500 || $response->status === 500;
            ($this->sleep)($wait);
        }
    }

    private function attempt(HttpRequest $request): HttpResponse
    {
        if ($request->sinkPath === null) {
            return $this->transport->send($request);
        }
        [$temp, $sink] = Fs::createTemp(dirname($request->sinkPath), basename($request->sinkPath));
        try {
            $response = $this->transport->send($request, $sink);
            fclose($sink);
            if ($response->status === 200) {
                Fs::moveInto($temp, $request->sinkPath);

                return $response;
            }

            return new HttpResponse($response->status, $response->headers, Fs::readHead($temp, self::ERROR_BODY_LIMIT));
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
            Fs::remove($temp);
        }
    }

    private static function timeoutMessage(HttpRequest $request): string
    {
        $text = sprintf("no response within %s seconds (the client's timeout)", Values::formatFloat('timeout', $request->timeout));
        if ($request->kind === HttpRequest::KIND_CONVERSION) {
            $text .= '. The conversion may still be running on the API, and it counts toward this month\'s'
                . ' conversions if it succeeds. Raise the timeout instead of retrying right away.';
        }

        return $text;
    }

    private static function brokenMessage(HttpRequest $request, TransportFailure $failure): string
    {
        $text = sprintf('the connection broke while the HTTP %d response was being read: %s', $failure->status, $failure->getMessage());
        if ($request->kind === HttpRequest::KIND_CONVERSION) {
            $text .= '. Not retried: the conversion may already have run and counted toward this month.';
        }

        return $text;
    }

    /** scheme://host[:port] of a URL, so messages never carry a stored file's link. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'the server';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
