<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\ApiException;
use PdfConversionApi\Exception\AuthenticationException;
use PdfConversionApi\Exception\BadRequestException;
use PdfConversionApi\Exception\GatewayTimeoutException;
use PdfConversionApi\Exception\InternalServerErrorException;
use PdfConversionApi\Exception\NotFoundException;
use PdfConversionApi\Exception\PayloadTooLargeException;
use PdfConversionApi\Exception\PermissionDeniedException;
use PdfConversionApi\Exception\RateLimitException;
use PdfConversionApi\Exception\ServiceUnavailableException;
use PdfConversionApi\Exception\UnprocessableEntityException;

/**
 * Turns an error response into the matching ApiException.
 *
 * @internal
 */
final class ErrorFactory
{
    private const CLASSES = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        413 => PayloadTooLargeException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitException::class,
        500 => InternalServerErrorException::class,
        503 => ServiceUnavailableException::class,
        504 => GatewayTimeoutException::class,
    ];

    public static function fromResponse(HttpResponse $response): ApiException
    {
        $class = self::CLASSES[$response->status] ?? ApiException::class;
        $retryAfter = $response->status === 429 || $response->status === 503
            ? RetryPolicy::parseRetryAfter($response->header('retry-after'))
            : null;

        return new $class(
            $response->status,
            self::message($response),
            $response->requestId(),
            $retryAfter,
            $response->body,
        );
    }

    /**
     * The JSON body's Message; `empty response`; a short text/plain body, trimmed; otherwise
     * `non-JSON response (<content type>)`.
     */
    public static function message(HttpResponse $response): string
    {
        $body = $response->body;
        if ($body === '') {
            return 'empty response';
        }
        try {
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($json) && isset($json['Message']) && is_string($json['Message'])) {
                return $json['Message'];
            }
        } catch (\JsonException) {
            // Not JSON: fall through to the content type.
        }
        $contentType = self::mediaType($response->header('content-type'));
        if ($contentType === 'text/plain' && self::characters($body) <= 1000) {
            return trim($body);
        }

        return sprintf('non-JSON response (%s)', $contentType ?? 'no content type');
    }

    /** The media type without parameters, in lower case; null when there is none. */
    private static function mediaType(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        $type = strtolower(trim(explode(';', $header, 2)[0]));

        return $type === '' ? null : $type;
    }

    private static function characters(string $text): int
    {
        $count = preg_match_all('/./su', $text);

        return is_int($count) ? $count : strlen($text);
    }
}
