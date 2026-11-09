<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Exception;
use PdfConversionApi\Internal\ErrorFactory;
use PdfConversionApi\Internal\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorFactoryTest extends TestCase
{
    /** @return iterable<array{int, class-string<Exception\ApiException>}> */
    public static function statuses(): iterable
    {
        yield [400, Exception\BadRequestException::class];
        yield [401, Exception\AuthenticationException::class];
        yield [403, Exception\PermissionDeniedException::class];
        yield [404, Exception\NotFoundException::class];
        yield [413, Exception\PayloadTooLargeException::class];
        yield [422, Exception\UnprocessableEntityException::class];
        yield [429, Exception\RateLimitException::class];
        yield [500, Exception\InternalServerErrorException::class];
        yield [503, Exception\ServiceUnavailableException::class];
        yield [504, Exception\GatewayTimeoutException::class];
        yield [302, Exception\ApiException::class];
        yield [405, Exception\ApiException::class];
        yield [502, Exception\ApiException::class];
    }

    /**
     * @param class-string<Exception\ApiException> $class
     */
    #[DataProvider('statuses')]
    public function testStatusMapsToClass(int $status, string $class): void
    {
        $error = ErrorFactory::fromResponse(self::json($status, 'went wrong', ['x-request-id' => 'abc']));
        self::assertSame($class, $error::class);
        self::assertSame($status, $error->getStatus());
        self::assertSame($status, $error->getCode());
        self::assertSame('went wrong', $error->getApiMessage());
        self::assertSame('abc', $error->getRequestId());
        self::assertSame("HTTP $status: went wrong (request id: abc)", $error->getMessage());
    }

    public function testRetryAfterOnlyOn429And503(): void
    {
        self::assertSame(10, ErrorFactory::fromResponse(self::json(429, 'slow down', ['retry-after' => '10']))->getRetryAfter());
        self::assertSame(1, ErrorFactory::fromResponse(self::json(503, 'busy', []))->getRetryAfter());
        self::assertNull(ErrorFactory::fromResponse(self::json(400, 'bad', ['retry-after' => '10']))->getRetryAfter());
    }

    public function testStringFormWithoutRequestId(): void
    {
        $error = ErrorFactory::fromResponse(new HttpResponse(413, ['content-type' => 'text/html'], '<html>413</html>'));
        self::assertNull($error->getRequestId());
        self::assertSame('HTTP 413: non-JSON response (text/html)', $error->getMessage());
        self::assertSame('<html>413</html>', $error->getBody());
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function messages(): iterable
    {
        yield 'json message' => [['content-type' => 'application/json'], '{"Code":422,"Message":"the input file is password-protected"}', 'the input file is password-protected'];
        yield 'json message kept as is' => [['content-type' => 'application/json'], '{"Message":"  spaced  "}', '  spaced  '];
        yield 'empty body' => [['content-type' => 'application/json'], '', 'empty response'];
        yield 'plain text' => [['content-type' => 'text/plain; charset=utf-8'], "Invalid `boundary` for `multipart/form-data` request\n", 'Invalid `boundary` for `multipart/form-data` request'];
        yield 'plain text 1000 characters' => [['content-type' => 'text/plain'], str_repeat('é', 1000), str_repeat('é', 1000)];
        yield 'plain text too long' => [['content-type' => 'text/plain'], str_repeat('x', 1001), 'non-JSON response (text/plain)'];
        yield 'html' => [['content-type' => 'text/html; charset=UTF-8'], '<html><h1>502 Bad Gateway</h1></html>', 'non-JSON response (text/html)'];
        yield 'json without Message' => [['content-type' => 'application/json'], '{"error":"x"}', 'non-JSON response (application/json)'];
        yield 'json with numeric Message' => [['content-type' => 'application/json'], '{"Message":5}', 'non-JSON response (application/json)'];
        yield 'no content type' => [[], 'garbage', 'non-JSON response (no content type)'];
        yield 'upper-case content type' => [['content-type' => 'Text/HTML'], '<p>', 'non-JSON response (text/html)'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('messages')]
    public function testMessage(array $headers, string $body, string $expected): void
    {
        self::assertSame($expected, ErrorFactory::message(new HttpResponse(400, $headers, $body)));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function json(int $status, string $message, array $headers): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode(['Code' => $status, 'Message' => $message]));
    }
}
