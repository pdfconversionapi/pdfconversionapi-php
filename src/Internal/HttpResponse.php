<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

/**
 * @internal
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-case names; the last value of a repeated header
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function requestId(): ?string
    {
        $id = $this->header('x-request-id');

        return $id === null || $id === '' ? null : $id;
    }
}
