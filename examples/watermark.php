<?php

/**
 * Stamps an image on every page of a PDF. The image goes in a named file field, ImageFile.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/watermark.php invoice.pdf logo.png invoice-stamped.pdf
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

[$input, $image, $output] = array_slice($argv ?? [], 1) + ['invoice.pdf', 'logo.png', 'invoice-stamped.pdf'];

$client = new Client();

try {
    $result = $client->convert('pdf', 'image-watermark', $input, [
        'ImageFile' => new SplFileInfo($image), // a file, not text: SplFileInfo or UploadFile
        'Opacity' => 30,
    ]);
    $result->file->save($output);
    echo "wrote $output\n";
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
