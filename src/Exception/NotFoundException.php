<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * HTTP 404: an unsupported conversion, or a stored file that expired or was deleted.
 */
class NotFoundException extends ApiException
{
}
