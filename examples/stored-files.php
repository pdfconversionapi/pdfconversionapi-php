<?php

/**
 * Converts with StoreFile=true: the API keeps each output for 3 hours and returns a link. This
 * downloads every file straight to disk, then deletes the stored copies.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/stored-files.php season-guide.pdf [pages]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

$input = $argv[1] ?? 'season-guide.pdf';
$outDir = $argv[2] ?? 'pages';

$client = new Client();

try {
    $result = $client->convertFile($input, 'jpg', ['ImageResolution' => 72], storeFile: true);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0o777, true);
    }
    foreach ($result->files as $file) {
        $path = $file->save($outDir);          // streamed to disk; no key is sent
        $deleted = $client->deleteStoredFile((string) $file->fileId);
        printf("%s -> %s, stored copy %s\n", $file->name, $path, $deleted ? 'deleted' : 'already gone');
    }
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
