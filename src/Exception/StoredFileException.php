<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * The data of a stored file (StoreFile=true) was read. Call download() or downloadTo() instead.
 */
class StoredFileException extends PdfConversionApiException
{
}
