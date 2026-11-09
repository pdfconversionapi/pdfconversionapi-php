# PDF Conversion API for PHP

The official PHP client for the [PDF Conversion API](https://pdfconversionapi.com). Convert Word,
Excel, PowerPoint, HTML, images and many other formats to PDF, turn PDF pages into images, and
merge, split, compress, protect or watermark PDFs, with one call that returns the finished files.

- PHP 8.1 or later, with ext-curl and ext-json. No other dependencies.
- Typed results and typed exceptions; retries with backoff built in.
- [Documentation](https://pdfconversionapi.com/docs) ·
  [Get an API key](https://app.pdfconversionapi.com) ·
  [Pricing](https://pdfconversionapi.com/pricing)

## Install

```bash
composer require pdfconversionapi/pdfconversionapi
```

## Quick start

Get a key from the [dashboard](https://app.pdfconversionapi.com) and put it in the
`PDFCONVERSIONAPI_KEY` environment variable:

```bash
export PDFCONVERSIONAPI_KEY="your API key"
```

```php
<?php
require 'vendor/autoload.php';

$client = new PdfConversionApi\Client();         // reads getenv('PDFCONVERSIONAPI_KEY')
$result = $client->convertFile('report.docx', 'pdf');
$result->saveAll('out');                         // writes out/report.pdf
```

Every call waits for the result and returns it: there is nothing to poll. The client keeps no
state between calls, so create it once and reuse it. Keep the key on your server; the API is not
meant to be called from browsers.

The examples below use a `$client` made this way. The [`examples/`](examples) folder has the
same programs as runnable scripts.

## Converting files

### One file to PDF

`convertFile()` reads the input format from the file's extension. `convert()` takes both formats
explicitly; tools such as `merge`, `compress` or `protect` go where the output format goes.

```php
$result = $client->convert('docx', 'pdf', 'report.docx');

$pdf = $result->file;                            // the first (here, the only) output
echo $pdf->name, ', ', $pdf->size, " bytes\n";   // report.pdf, 82830 bytes
file_put_contents('report.pdf', $pdf->data());   // or: $pdf->save('report.pdf')
```

The output takes the uploaded file's name with the new extension.

### Files: paths, bytes and streams

Wherever the client takes a file, **a string is a path**, never file content. A `SplFileInfo` is
a path too. For data you already have in memory, or a stream, use `UploadFile`:

```php
use PdfConversionApi\UploadFile;

$html = '<h1>Invoice 1042</h1><p>Total: $120</p>';
$result = $client->convert('html', 'pdf', UploadFile::fromBytes($html, 'invoice-1042.html'));
echo $result->file->name, "\n";                  // invoice-1042.pdf

$stream = fopen('report.docx', 'rb');
$result = $client->convert('docx', 'pdf', UploadFile::fromStream($stream, 'report.docx'));
fclose($stream);
```

Only the file's own name is sent, never its folders. Bytes or streams without a name are sent as
`file`, so their output is called `file.pdf`. A path is read again for each retry, a seekable
stream is rewound, and any other stream is read into memory once so a retry sends the same bytes.

### Several output files: PDF to JPG

Conversions that work page by page return one file per page, in page order:

```php
$result = $client->convertFile('season-guide.pdf', 'jpg', ['ImageResolution' => 72]);
foreach ($result->files as $file) {
    echo $file->name, ': ', $file->size, " bytes\n";
}
$paths = $result->saveAll('pages');              // creates pages/ and writes every file
```

```text
season-guide-1.jpg: 65272 bytes
season-guide-2.jpg: 66627 bytes
```

`saveAll()` replaces files that already exist and returns the paths it wrote.

### Several input files: merge

Pass a list. The files are sent in that order, and the result is named after the first one unless
you choose a name:

```php
$result = $client->convert('pdf', 'merge', ['invoice.pdf', 'terms.pdf', 'timesheet.pdf'], fileName: 'october-billing');
$result->file->save('.');                        // writes ./october-billing.pdf
```

`pdf/to/merge`, `images/to/pdf`, `images/to/join` and `file/to/zip` take several files; the
others answer 400 when they get more than one.

### A second file with its own role: image watermark

Some tools take a second file in a named field, such as `ImageFile` for `pdf/to/image-watermark`
or `OverlayFile` for `pdf/to/pdf-watermark`. Pass it in the parameters as a `SplFileInfo` or an
`UploadFile` (in parameters, a plain string is sent as text):

```php
$result = $client->convert('pdf', 'image-watermark', 'invoice.pdf', [
    'ImageFile' => new SplFileInfo('logo.png'),
    'Opacity' => 30,
]);
$result->file->save('invoice-stamped.pdf');
```

### Parameters

Parameters go in the array after the files, under the names each conversion's page lists, such
as [DOCX to PDF](https://pdfconversionapi.com/convert/docx-to-pdf) or
[PDF to JPG](https://pdfconversionapi.com/convert/pdf-to-jpg). Strings are sent as they are,
integers and floats in plain decimal, booleans as `true` or `false`, and `null` values are left
out. The API checks names and values and answers 400 with a clear message when one is wrong.

```php
$result = $client->convertFile('season-guide.pdf', 'png', [
    'PageRange' => '2',
    'ImageResolution' => 96,
]);
echo $result->file->name, "\n";                  // season-guide-2.png
```

A few conversions take no file at all. Leave the files out and pass the data as a parameter:

```php
$result = $client->convert('data', 'qrcode', params: ['Data' => 'https://pdfconversionapi.com']);
$result->file->save('qrcode.svg');
```

To choose the output's name, pass `fileName` (without the extension); with several outputs it
replaces the part before the page number:

```php
$result = $client->convertFile('season-guide.pdf', 'jpg', ['ImageResolution' => 72], fileName: 'march');
echo implode(', ', array_map(fn ($file) => $file->name, $result->files)), "\n";   // march-1.jpg, march-2.jpg
```

## Stored files: a link instead of the data

With `storeFile: true` the API keeps each output for 3 hours and returns a link to it instead of
its data. The link needs no key, so anyone who has it can download the file: treat it like a
password. Downloads stream straight to disk.

```php
$result = $client->convertFile('season-guide.pdf', 'jpg', ['ImageResolution' => 72], storeFile: true);
foreach ($result->files as $file) {
    echo $file->url, "\n";                       // https://api.pdfconversionapi.com/v2/d/<FileId>/season-guide-1.jpg
    $file->save('.');                            // downloads it to ./season-guide-1.jpg
    $client->deleteStoredFile($file->fileId);    // true; false when it was already gone
}
```

- `$file->data()` throws a `StoredFileException` for a stored file; call `$file->download()`
  to get the bytes, or `$file->save($path)` / `$file->downloadTo($path)` to write them to disk.
- `$client->download($url)` and `$client->downloadTo($url, $path)` take a link you kept
  elsewhere. Once the link has expired or the file was deleted, they throw a `NotFoundException`.

### Large results

A normal response holds every output file, base64-encoded, and the client keeps the whole
response in memory while it decodes it: a 60 MB result arrives as about 80 MB of JSON. For large
outputs, use `storeFile: true` and then `save()` or `downloadTo()`, which write the file to disk
as it arrives. Uploads are always streamed from disk, whatever their size.

## Usage

```php
$usage = $client->usage();
printf("%s plan: %d of %d conversions used, %d left until %s\n",
    $usage->plan, $usage->used, $usage->included, $usage->remaining, $usage->periodEnd->format('Y-m-d'));
```

```text
Free plan: 12 of 250 conversions used, 238 left until 2026-12-01
```

`usage()` uses no conversion. `$usage->running`, `$usage->concurrentLimit` and
`$usage->maxUploadMb` give the plan's other limits, and both dates are in UTC.
`$client->conversions()` lists every conversion the API offers (it needs no key).

## Errors

Every exception the client throws extends `PdfConversionApi\Exception\PdfConversionApiException`,
except `InvalidArgumentException` for a bad argument, which is thrown before anything is sent.

| Exception | When |
|---|---|
| `MissingApiKeyException` | No key, for a call that needs one. Nothing was sent. |
| `ApiException` | The API answered with an error status. The subclasses below name the common ones. |
| ├ `BadRequestException` | 400: a parameter value the conversion can't use, a bad page range... |
| ├ `AuthenticationException` | 401: the key is missing, unknown or replaced. |
| ├ `PermissionDeniedException` | 403: the key's plan is not set up. |
| ├ `NotFoundException` | 404: a conversion the API doesn't offer, or an expired or deleted stored file. |
| ├ `PayloadTooLargeException` | 413: the upload, or an image or page in it, is over a limit. |
| ├ `UnprocessableEntityException` | 422: the file can't be read or converted as asked (damaged, password-protected...). |
| ├ `RateLimitException` | 429: too many requests or conversions at once, or the month's conversions are used up. |
| ├ `InternalServerErrorException` | 500. |
| ├ `ServiceUnavailableException` | 503: busy, or conversions on the plan are paused. |
| └ `GatewayTimeoutException` | 504: the conversion ran out of time on the API's side. |
| `TimeoutException` | The client's own timeout ran out (see [Timeouts](#timeouts)). |
| `ConnectionException` | No response after the retries, or the connection broke while a response was arriving. |
| `InvalidResponseException` | A 200 response the client can't use, or an output name that isn't safe to write. |
| `StoredFileException` | `data()` was called on a stored file. |
| `FileSystemException` | A local file couldn't be read or written. |

An `ApiException` carries the status, the API's message, the request id and the raw body:

```php
use PdfConversionApi\Exception\ApiException;
use PdfConversionApi\Exception\PdfConversionApiException;
use PdfConversionApi\Exception\RateLimitException;

try {
    $client->convertFile('report.docx', 'dwg');
} catch (RateLimitException $e) {
    echo 'try again in ', $e->getRetryAfter(), " seconds\n";
} catch (ApiException $e) {
    echo $e->getStatus(), "\n";                  // 404
    echo $e->getApiMessage(), "\n";              // conversion from docx to dwg is not supported
    echo $e->getMessage(), "\n";                 // HTTP 404: conversion from docx to dwg is not supported (request id: ...)
} catch (PdfConversionApiException $e) {
    echo $e->getMessage(), "\n";                 // no response, a timeout, a local file problem...
}
```

Branch on the exception class or the status, not on the message text, which can change. Log the
request id (`getRequestId()`, also in `getMessage()`) and send it with any question about a
failed request. Messages never contain your API key.

### Retries

The client already retries what is safe to retry, up to 4 times (5 attempts in all):

- a failed connection (DNS failure, refused or reset connection, connect timeout), after a random
  wait of up to 2, 4, 8 and then 16 seconds;
- 429 and 503 answers, after the `Retry-After` seconds plus that random wait, but only when
  `Retry-After` is 60 seconds or less. A longer wait, such as a used-up month or paused
  conversions, is thrown at once as a `RateLimitException` or `ServiceUnavailableException`
  with `getRetryAfter()` set;
- a 500, once.

Other errors, the client's own timeout, and a connection that breaks after the response started
are never retried. Retrying is safe: a conversion changes nothing on the API, and only a
successful attempt counts toward your month. `maxRetries: 0` turns retries off and `maxRetryWait`
changes the 60 seconds (see [Configuration](#configuration)).

## Timeouts

Each attempt may take 240 seconds by default, the whole response included, which leaves room for
the longest conversions. When the timeout runs out, the client throws a `TimeoutException` and
does not retry: the conversion may still be running on the API, and it counts toward your month
if it succeeds. Raise the timeout instead of retrying right away, for every call or for one:

```php
$client = new PdfConversionApi\Client(timeout: 600);
$result = $client->convertFile('report.docx', 'pdf', timeout: 900);
```

## Configuration

Every option is a named argument of the constructor:

```php
$client = new PdfConversionApi\Client(
    apiKey: getenv('MY_PDF_KEY') ?: null,        // default: the PDFCONVERSIONAPI_KEY environment variable
    baseUrl: 'https://api.pdfconversionapi.com', // default: PDFCONVERSIONAPI_BASE_URL, else this URL
    timeout: 240,                                // seconds per attempt, for the whole response
    connectTimeout: 10,                          // seconds to open a connection
    maxRetries: 4,                               // 0 turns retries off
    maxRetryWait: 60,                            // longest Retry-After to wait for, in seconds
    curlOptions: [CURLOPT_PROXY => 'http://proxy.internal:3128'],
);
```

- The key may be missing when the client is created: `conversions()` and downloads work without
  one, and a call that needs one throws a `MissingApiKeyException` before sending anything.
- `curlOptions` adds cURL options to every request, for example a proxy or a CA bundle
  (`CURLOPT_CAINFO`). The client's own settings (URL, method, headers, body, timeouts, redirects)
  take precedence, and TLS certificates are verified unless you turn that off yourself.
- `PdfConversionApi\Client::VERSION` is the library's version; it is also sent in the
  `User-Agent` header.

## Requirements and support

- PHP 8.1, 8.2, 8.3, 8.4 or 8.5 with ext-curl and ext-json.
- [API documentation](https://pdfconversionapi.com/docs): every conversion, its parameters, the
  limits and the error messages.
- [Changelog](CHANGELOG.md) · [MIT license](LICENSE)
