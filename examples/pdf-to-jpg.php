<?php

/**
 * Renders every page of a PDF as a JPG image: one output file per page.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/pdf-to-jpg.php season-guide.pdf [pages]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

$input = $argv[1] ?? 'season-guide.pdf';
$outDir = $argv[2] ?? 'pages';

$client = new Client();

try {
    $result = $client->convertFile($input, 'jpg', ['ImageResolution' => 72]);
    $paths = $result->saveAll($outDir); // creates the folder; one file per page, in page order
    foreach ($result->files as $i => $file) {
        echo "{$paths[$i]} ({$file->size} bytes)\n";
    }
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
