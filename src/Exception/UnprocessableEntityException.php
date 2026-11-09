<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 422: the file can't be read or converted as asked (damaged, password-protected, restricted).
 */
class UnprocessableEntityException extends ApiException
{
}
