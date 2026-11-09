<?php

/**
 * Converts one file to PDF (or to the format you name) and saves the result.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/convert-file.php report.docx [pdf] [out]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

$input = $argv[1] ?? 'report.docx';
$to = $argv[2] ?? 'pdf';
$outDir = $argv[3] ?? 'out';

$client = new Client(); // reads PDFCONVERSIONAPI_KEY

try {
    $result = $client->convertFile($input, $to);
    foreach ($result->saveAll($outDir) as $path) {
        echo "wrote $path\n";
    }
    printf("%d file(s), %.3f s on the API, request id %s\n", count($result), $result->conversionTime, $result->requestId ?? '-');
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
