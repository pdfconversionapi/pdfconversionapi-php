<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * Base class of every exception the SDK throws, except \InvalidArgumentException for bad
 * arguments (raised before anything is sent).
 *
 * Messages never contain the API key.
 */
class PdfConversionApiException extends \RuntimeException
{
}
