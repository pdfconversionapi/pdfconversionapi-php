<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 503: the API is busy, or conversions on the plan are paused. getRetryAfter() gives the
 * seconds to wait.
 */
class ServiceUnavailableException extends ApiException
{
}
