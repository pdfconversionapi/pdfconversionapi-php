<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * No API key for a call that needs one. Raised before anything is sent.
 *
 * Pass the key to the client, or set the PDFCONVERSIONAPI_KEY environment variable.
 */
class MissingApiKeyException extends PdfConversionApiException
{
}
