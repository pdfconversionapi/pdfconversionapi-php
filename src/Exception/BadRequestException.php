<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 400: the request is not valid, for example a parameter value the conversion can't use.
 */
class BadRequestException extends ApiException
{
}
