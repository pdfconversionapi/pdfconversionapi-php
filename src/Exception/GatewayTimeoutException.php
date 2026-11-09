<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 504: the conversion ran out of time on the API's side. Not retried.
 */
class GatewayTimeoutException extends ApiException
{
}
