<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * No response from the API after every retry: DNS failure, refused or reset connection, or a
 * connection closed before a response.
 */
class ConnectionException extends PdfConversionApiException
{
}
