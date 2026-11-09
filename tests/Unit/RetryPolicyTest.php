<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Internal\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, int, ?string, bool, ?float}> */
    public static function statusDecisions(): iterable
    {
        // attempt, status, Retry-After, already retried a 500, expected wait (jitter = 0.5)
        yield '429 short wait' => [1, 429, '10', false, 10.5];
        yield '503 short wait' => [2, 503, '5', false, 5.5];
        yield '429 at max_retry_wait' => [1, 429, '60', false, 60.5];
        yield '429 longer than max_retry_wait' => [1, 429, '61', false, null];
        yield '429 used-up month' => [1, 429, '2000000', false, null];
        yield '503 paused' => [1, 503, '600', false, null];
        yield '503 missing Retry-After' => [1, 503, null, false, 1.5];
        yield '503 HTTP-date Retry-After' => [1, 503, 'Wed, 21 Oct 2026 07:28:00 GMT', false, 1.5];
        yield '503 last attempt' => [5, 503, '1', false, null];
        yield '500 first' => [1, 500, null, false, 1.5];
        yield '500 second' => [2, 500, null, true, null];
        yield '500 on last attempt' => [5, 500, null, false, null];
        foreach ([200, 204, 301, 400, 401, 403, 404, 413, 422, 502, 504] as $status) {
            yield "$status never" => [1, $status, '1', false, null];
        }
    }

    #[DataProvider('statusDecisions')]
    public function testAfterStatus(int $attempt, int $status, ?string $retryAfter, bool $retried500, ?float $expected): void
    {
        $policy = new RetryPolicy(4, 60.0);
        self::assertSame($expected, $policy->afterStatus($attempt, $status, $retryAfter, $retried500, 0.5));
    }

    public function testNoResponseRetriesUntilAttemptsRunOut(): void
    {
        $policy = new RetryPolicy(4, 60.0);
        foreach ([1, 2, 3, 4] as $attempt) {
            self::assertSame(0.25, $policy->afterNoResponse($attempt, 0.25));
        }
        self::assertNull($policy->afterNoResponse(5, 0.25));
        self::assertNull((new RetryPolicy(0, 60.0))->afterNoResponse(1, 0.25));
    }

    public function testRetriesDisabled(): void
    {
        self::assertNull((new RetryPolicy(0, 60.0))->afterStatus(1, 503, '1', false, 0.1));
        self::assertNull((new RetryPolicy(0, 60.0))->afterStatus(1, 500, null, false, 0.1));
    }

    public function testParseRetryAfter(): void
    {
        self::assertSame(1, RetryPolicy::parseRetryAfter(null));
        self::assertSame(1, RetryPolicy::parseRetryAfter(''));
        self::assertSame(1, RetryPolicy::parseRetryAfter('soon'));
        self::assertSame(1, RetryPolicy::parseRetryAfter('1.5'));
        self::assertSame(1, RetryPolicy::parseRetryAfter('-3'));
        self::assertSame(0, RetryPolicy::parseRetryAfter('0'));
        self::assertSame(42, RetryPolicy::parseRetryAfter(' 42 '));
        self::assertSame(2000000, RetryPolicy::parseRetryAfter('2000000'));
    }

    public function testJitterStaysBelowTheBound(): void
    {
        foreach ([1 => 2.0, 2 => 4.0, 3 => 8.0, 4 => 16.0] as $attempt => $bound) {
            for ($i = 0; $i < 500; $i++) {
                $jitter = RetryPolicy::jitter($attempt);
                self::assertGreaterThanOrEqual(0.0, $jitter);
                self::assertLessThan($bound, $jitter);
            }
        }
    }
}
