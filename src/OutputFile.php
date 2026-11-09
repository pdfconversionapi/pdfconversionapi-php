<?php

declare(strict_types=1);

namespace PdfConversionApi;

use PdfConversionApi\Exception\StoredFileException;
use PdfConversionApi\Internal\Fs;
use PdfConversionApi\Internal\Names;

/**
 * One output file of a conversion: its data, or, with storeFile, a link to it.
 */
final class OutputFile
{
    /** Whether the file was stored (storeFile) and has a link instead of data. */
    public readonly bool $stored;

    private readonly ?string $data;

    public function __construct(
        /** The output's name, extension included: `report.pdf`. */
        public readonly string $name,
        /** The extension in lower case, without the dot: `pdf`. */
        public readonly string $ext,
        /** The size in bytes. */
        public readonly int $size,
        ?string $data = null,
        /** The download link of a stored file (works for 3 hours); null otherwise. */
        public readonly ?string $url = null,
        /** The stored file's id, for Client::deleteStoredFile(); null otherwise. */
        public readonly ?string $fileId = null,
        private readonly ?Client $client = null,
    ) {
        if (($data === null) === ($url === null)) {
            throw new \InvalidArgumentException('an OutputFile has either data or a url');
        }
        $this->data = $data;
        $this->stored = $url !== null;
    }

    /**
     * The file's bytes.
     *
     * @throws StoredFileException for a stored file: call download() instead
     */
    public function data(): string
    {
        if ($this->data === null) {
            throw new StoredFileException(sprintf(
                '%s was stored (storeFile), so the response has a link instead of its data; call download() or downloadTo()',
                $this->name,
            ));
        }

        return $this->data;
    }

    /**
     * The file's bytes, downloading a stored file through its link (no API key is sent).
     *
     * @throws Exception\PdfConversionApiException
     */
    public function download(?float $timeout = null): string
    {
        if ($this->data !== null) {
            return $this->data;
        }

        return $this->client()->download((string) $this->url, $timeout);
    }

    /**
     * Writes the file to $path, or inside $path under its own name when $path is a folder.
     * A stored file is downloaded and streamed to disk. An existing file is replaced.
     *
     * @return string the path written
     *
     * @throws Exception\PdfConversionApiException
     */
    public function save(string $path, ?float $timeout = null): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('the path is empty');
        }
        if (is_dir($path)) {
            $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . Names::safeOutputName($this->name);
        }
        if ($this->data === null) {
            return $this->client()->downloadTo((string) $this->url, $path, $timeout);
        }
        Fs::writeFile($path, $this->data);

        return $path;
    }

    /**
     * The same as save(): writes the file to $path (or inside it, for a folder) and returns the
     * path written.
     *
     * @throws Exception\PdfConversionApiException
     */
    public function downloadTo(string $path, ?float $timeout = null): string
    {
        return $this->save($path, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'ext' => $this->ext,
            'size' => $this->size,
            'stored' => $this->stored,
            'url' => $this->url,
            'fileId' => $this->fileId,
        ];
    }

    private function client(): Client
    {
        return $this->client ?? new Client();
    }
}
