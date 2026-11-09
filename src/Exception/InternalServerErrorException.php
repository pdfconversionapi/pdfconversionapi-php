<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 500: an unexpected failure on the API's side. Retried once before it is raised.
 */
class InternalServerErrorException extends ApiException
{
}
