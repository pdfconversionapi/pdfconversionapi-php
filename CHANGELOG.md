# Changelog

All notable changes to this package are listed here. It follows
[Semantic Versioning](https://semver.org/).

## 0.1.0 - unreleased

First release.

- `Client` with `convert()`, `convertFile()`, `usage()`, `conversions()`, `download()`,
  `downloadTo()` and `deleteStoredFile()`.
- Inputs from paths, `SplFileInfo`, bytes (`UploadFile::fromBytes()`) and streams
  (`UploadFile::fromStream()`); named file fields such as `ImageFile`; calls without files.
- Results with every output file: `data()`, `save()`, `download()`, `downloadTo()`, and
  `ConversionResult::saveAll()`. Stored files (`storeFile: true`) download straight to disk.
- Typed exceptions under `PdfConversionApiException`, one per common status.
- Retries for failed connections, short 429 and 503 waits, and a first 500, with backoff and
  jitter. A 240-second timeout per attempt, never retried.
- No dependencies beyond ext-curl and ext-json; PHP 8.1 to 8.5.
