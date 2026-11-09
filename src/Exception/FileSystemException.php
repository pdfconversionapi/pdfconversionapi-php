<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * A local file couldn't be read or written: an input that disappeared before it was sent, or a
 * result that couldn't be saved (folder not writable, disk full).
 */
class FileSystemException extends PdfConversionApiException
{
}
