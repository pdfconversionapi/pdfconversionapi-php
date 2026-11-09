<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

/**
 * One API call, sent once or several times by the retry loop.
 *
 * @internal
 */
final class HttpRequest
{
    public const KIND_CONVERSION = 'conversion';
    public const KIND_API = 'api';
    public const KIND_DOWNLOAD = 'download';

    /**
     * @param non-empty-string $method
     * @param non-empty-string $url
     * @param self::KIND_* $kind
     * @param string|null $sinkPath for downloads to disk: the file to write on success
     */
    public function __construct(
        /** @var non-empty-string */
        public readonly string $method,
        /** @var non-empty-string */
        public readonly string $url,
        public readonly string $kind,
        public readonly bool $authenticated,
        public readonly float $timeout,
        public readonly ?MultipartBody $body = null,
        public readonly ?string $sinkPath = null,
    ) {
    }
}
