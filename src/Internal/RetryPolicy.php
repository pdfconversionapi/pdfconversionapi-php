<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

/**
 * The retry rules: what to do after each attempt. Attempts count from 1.
 *
 * - No response: retry after jitter(attempt).
 * - 429 or 503: retry after Retry-After + jitter(attempt), unless Retry-After is longer than
 *   maxRetryWait.
 * - 500: retry once per call, after 1 + jitter(attempt).
 * - The client's own timeout and every other status: never retried.
 *
 * jitter(attempt) is uniformly random in [0, 2^attempt) seconds.
 *
 * @internal
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxRetries,
        public readonly float $maxRetryWait,
    ) {
    }

    /** Seconds to wait before retrying an attempt that got no response, or null to give up. */
    public function afterNoResponse(int $attempt, float $jitter): ?float
    {
        return $this->attemptsLeft($attempt) ? $jitter : null;
    }

    /**
     * Seconds to wait before retrying an attempt that got this status, or null to stop there.
     *
     * @param bool $retried500 whether this call already retried a 500
     */
    public function afterStatus(int $attempt, int $status, ?string $retryAfterHeader, bool $retried500, float $jitter): ?float
    {
        if (!$this->attemptsLeft($attempt)) {
            return null;
        }
        if ($status === 429 || $status === 503) {
            $retryAfter = self::parseRetryAfter($retryAfterHeader);

            return $retryAfter <= $this->maxRetryWait ? $retryAfter + $jitter : null;
        }
        if ($status === 500 && !$retried500) {
            return 1 + $jitter;
        }

        return null;
    }

    /** Retry-After in whole seconds; missing or unreadable means 1. */
    public static function parseRetryAfter(?string $header): int
    {
        if ($header === null || preg_match('/^\s*(\d{1,15})\s*$/', $header, $match) !== 1) {
            return 1;
        }

        return (int) $match[1];
    }

    /** A uniformly random number of seconds in [0, 2^attempt). */
    public static function jitter(int $attempt): float
    {
        return mt_rand() / (mt_getrandmax() + 1) * (2 ** min($attempt, 30));
    }

    private function attemptsLeft(int $attempt): bool
    {
        return $attempt <= $this->maxRetries;
    }
}
