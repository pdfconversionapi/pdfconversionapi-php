<?php

declare(strict_types=1);

namespace PdfConversionApi;

/**
 * One conversion the API offers, from GET /v2/info.
 */
final class Conversion
{
    public function __construct(
        /** The input format, as it goes in the path: `docx`. */
        public readonly string $from,
        /** The output format or tool name: `pdf`, `merge`. */
        public readonly string $to,
        /** A title for people: `DOCX to PDF`. */
        public readonly string $title,
        /** The group it belongs to: `Document`, `PDF Tools`. */
        public readonly string $category,
        /** How many files it takes: `one`, `many` or `optional`. */
        public readonly string $inputs,
    ) {
    }
}
