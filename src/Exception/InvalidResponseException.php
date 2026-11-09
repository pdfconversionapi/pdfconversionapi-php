<?php

declare(strict_types=1);

namespace PdfConversionApi\Exception;

/**
 * A response the SDK can't use: a 200 with a body that isn't valid JSON, has no Files, or
 * whose decoded data doesn't match FileSize; or an output file name that isn't safe to write.
 */
class InvalidResponseException extends PdfConversionApiException
{
}
