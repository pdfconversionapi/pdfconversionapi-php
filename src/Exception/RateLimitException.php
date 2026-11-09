<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 429: too many requests, too many conversions at once, or the month's conversions are used
 * up. getRetryAfter() gives the seconds to wait.
 */
class RateLimitException extends ApiException
{
}
