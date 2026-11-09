<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * The client's own timeout ran out before the response arrived. Never retried: the conversion may
 * still be running on the API and counts toward the month if it succeeds. Raise the timeout
 * instead of retrying right away.
 */
class TimeoutException extends PdfConversionApiException
{
}
