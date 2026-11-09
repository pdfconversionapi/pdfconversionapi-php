<?php

/**
 * Merges several PDFs into one, in the order given.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/merge.php merged.pdf invoice.pdf terms.pdf timesheet.pdf
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

$args = array_slice($argv ?? [], 1);
if (count($args) < 3) {
    fwrite(STDERR, "usage: php merge.php OUTPUT.pdf INPUT.pdf INPUT.pdf [...]\n");
    exit(2);
}
$output = $args[0];
$inputs = array_slice($args, 1);

$client = new Client();

try {
    $result = $client->convert('pdf', 'merge', $inputs);
    $result->file->save($output);
    printf("merged %d files into %s (%d bytes)\n", count($inputs), $output, $result->file->size);
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
