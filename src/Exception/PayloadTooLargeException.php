<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 413: the request, or an image or page in it, is over a limit.
 */
class PayloadTooLargeException extends ApiException
{
}
