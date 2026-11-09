<?php

/**
 * Shows the key's plan and this month's count. Uses no conversion.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/usage.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\PdfConversionApiException;

$client = new Client();

try {
    $usage = $client->usage();
    printf(
        "%s plan: %d of %d conversions used this month, %s left; the count starts again on %s (UTC)\n",
        $usage->plan,
        $usage->used,
        $usage->included,
        $usage->remaining ?? 'unknown',
        $usage->periodEnd->format('Y-m-d'),
    );
    printf("%d running now, %d at once allowed, uploads up to %d MB\n", $usage->running, $usage->concurrentLimit, $usage->maxUploadMb);
} catch (PdfConversionApiException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
