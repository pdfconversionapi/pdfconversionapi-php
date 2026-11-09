<?php

declare(strict_types=1);

namespace PdfConversionApi;

use PdfConversionApi\Internal\Fs;
use PdfConversionApi\Internal\Names;

/**
 * The result of one conversion: every output file, in the API's order.
 *
 * @implements \IteratorAggregate<int, OutputFile>
 */
final class ConversionResult implements \IteratorAggregate, \Countable
{
    /** The first output file, for the common case of one. */
    public readonly OutputFile $file;

    /**
     * @param list<OutputFile> $files
     */
    public function __construct(
        /** @var list<OutputFile> every output file, in order */
        public readonly array $files,
        /** Conversions counted for this request: always 1. */
        public readonly int $conversionCost,
        /** Seconds the conversion took on the API's side. */
        public readonly float $conversionTime,
        /** The x-request-id header, for support requests. */
        public readonly ?string $requestId,
    ) {
        if ($files === []) {
            throw new \InvalidArgumentException('a ConversionResult has at least one file');
        }
        $this->file = $files[0];
    }

    /**
     * Writes every file into $dir under its own name, creating the folder if needed and replacing
     * existing files. Stored files are downloaded.
     *
     * @return list<string> the paths written, in order
     *
     * @throws Exception\PdfConversionApiException
     */
    public function saveAll(string $dir, ?float $timeout = null): array
    {
        if ($dir === '') {
            throw new \InvalidArgumentException('the folder is empty');
        }
        Fs::ensureDirectory($dir);
        $dir = rtrim($dir, '/\\');
        $dir = $dir === '' ? DIRECTORY_SEPARATOR : $dir;
        $paths = [];
        foreach ($this->files as $file) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . Names::safeOutputName($file->name);
            if (is_dir($path)) {
                throw new Exception\FileSystemException(sprintf('cannot write "%s": a folder has that name', $path));
            }
            $paths[] = $file->save($path, $timeout);
        }

        return $paths;
    }

    /** @return \ArrayIterator<int, OutputFile> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->files);
    }

    public function count(): int
    {
        return count($this->files);
    }
}
