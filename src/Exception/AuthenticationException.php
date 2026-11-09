<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 401: the API key is missing, unknown, replaced, or its account was deleted.
 */
class AuthenticationException extends ApiException
{
}
