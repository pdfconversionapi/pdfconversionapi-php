<?php

/**
 * What each kind of failure looks like. Asks for a conversion the API doesn't offer, so it ends
 * with a NotFoundException.
 *
 *     PDFCONVERSIONAPI_KEY=... php examples/error-handling.php report.docx
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PdfConversionApi\Client;
use PdfConversionApi\Exception\ApiException;
use PdfConversionApi\Exception\ConnectionException;
use PdfConversionApi\Exception\PdfConversionApiException;
use PdfConversionApi\Exception\RateLimitException;
use PdfConversionApi\Exception\TimeoutException;

$input = $argv[1] ?? 'report.docx';
$client = new Client();

try {
    $client->convertFile($input, 'dwg');
} catch (RateLimitException $e) {
    // Retried already when the wait was short; here Retry-After was long (a used-up month).
    echo 'rate limited; retry in ', $e->getRetryAfter(), " seconds\n";
} catch (ApiException $e) {
    // Any other error status. Branch on the status; log the message and the request id.
    echo 'status:     ', $e->getStatus(), "\n";
    echo 'message:    ', $e->getApiMessage(), "\n";
    echo 'request id: ', $e->getRequestId() ?? '-', "\n";
    echo 'as text:    ', $e->getMessage(), "\n";
} catch (TimeoutException $e) {
    echo "timed out; the conversion may still finish and count. Raise the timeout.\n";
} catch (ConnectionException $e) {
    echo 'no response after retries: ', $e->getMessage(), "\n";
} catch (PdfConversionApiException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} catch (InvalidArgumentException $e) {
    echo 'bad argument: ', $e->getMessage(), "\n";
}
