<?php

declare(strict_types=1);

namespace PdfConversionApi;

use PdfConversionApi\Exception\FileSystemException;
use PdfConversionApi\Internal\Names;

/**
 * One file to upload: its bytes and the name sent with them.
 *
 * Wherever the client takes a file, a plain string is a **path**, never file content, and a
 * \SplFileInfo is a path too. Use the factories for the other forms:
 *
 *     UploadFile::fromPath('report.docx');
 *     UploadFile::fromBytes($pdfBytes, 'invoice.pdf');
 *     UploadFile::fromStream(fopen('php://stdin', 'rb'), 'input.html');
 *
 * Only the file's own name is sent, never its folders. Bytes and streams without a name are sent
 * as `file`.
 */
final class UploadFile
{
    private const DEFAULT_NAME = 'file';

    /** The name sent in the request, without folders. */
    public readonly string $name;

    /**
     * @param resource|null $stream
     */
    private function __construct(
        string $name,
        private readonly ?string $path,
        private readonly ?string $bytes,
        private readonly mixed $stream,
        private readonly int $streamStart,
    ) {
        $this->name = $name;
    }

    /**
     * A file on disk. It is read again for every attempt, so a retry sends the same file.
     *
     * @param string|\SplFileInfo $path
     * @param string|null $name the name to send; defaults to the path's last part
     *
     * @throws \InvalidArgumentException when the path is not a readable file
     */
    public static function fromPath(string|\SplFileInfo $path, ?string $name = null): self
    {
        $path = $path instanceof \SplFileInfo ? $path->getPathname() : $path;
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('the input path is empty or not valid');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('the input file "%s" does not exist or is not readable', $path));
        }

        return new self(self::cleanName($name ?? $path), $path, null, null, 0);
    }

    /**
     * Bytes in memory.
     *
     * @param string|null $name the name to send, such as `invoice.pdf`; `file` when null
     */
    public static function fromBytes(string $bytes, ?string $name = null): self
    {
        return new self(self::cleanName($name ?? self::DEFAULT_NAME), null, $bytes, null, 0);
    }

    /**
     * An open stream. A seekable stream is read from its current position and rewound to it for
     * every attempt; any other stream is read into memory now, so retries send the same bytes.
     * The stream is not closed.
     *
     * @param resource $stream
     * @param string|null $name the name to send; `file` when null
     *
     * @throws \InvalidArgumentException when $stream is not an open stream
     */
    public static function fromStream(mixed $stream, ?string $name = null): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('fromStream() needs an open stream resource');
        }
        $name = self::cleanName($name ?? self::DEFAULT_NAME);
        $meta = stream_get_meta_data($stream);
        $start = $meta['seekable'] ? ftell($stream) : false;
        if ($start !== false && fseek($stream, 0, SEEK_END) === 0) {
            fseek($stream, $start);

            return new self($name, null, null, $stream, $start);
        }
        $bytes = stream_get_contents($stream);
        if ($bytes === false) {
            throw new \InvalidArgumentException('the input stream could not be read');
        }

        return new self($name, null, $bytes, null, 0);
    }

    /**
     * Turns anything the client accepts as a file into an UploadFile.
     *
     * @internal
     */
    public static function from(mixed $input): self
    {
        if ($input instanceof self) {
            return $input;
        }
        if (is_string($input) || $input instanceof \SplFileInfo) {
            return self::fromPath($input);
        }
        throw new \InvalidArgumentException(sprintf(
            'a file must be a path (string or SplFileInfo) or an UploadFile, %s given',
            get_debug_type($input),
        ));
    }

    /**
     * Opens the content for one attempt: [size in bytes, string or stream positioned at the start].
     *
     * @internal
     *
     * @return array{int, string|resource}
     */
    public function open(): array
    {
        if ($this->bytes !== null) {
            return [strlen($this->bytes), $this->bytes];
        }
        if ($this->path !== null) {
            clearstatcache(true, $this->path);
            $handle = @fopen($this->path, 'rb');
            $stat = $handle === false ? false : fstat($handle);
            if ($handle === false || $stat === false) {
                throw new FileSystemException(sprintf('the input file "%s" could not be opened', $this->path));
            }

            return [(int) $stat['size'], $handle];
        }
        /** @var resource $stream */
        $stream = $this->stream;
        if (fseek($stream, 0, SEEK_END) !== 0 || ($end = ftell($stream)) === false || fseek($stream, $this->streamStart) !== 0) {
            throw new FileSystemException('the input stream could not be rewound');
        }

        return [max(0, $end - $this->streamStart), $stream];
    }

    /**
     * Whether open() returns a stream that the caller must close after the attempt.
     *
     * @internal
     */
    public function ownsHandle(): bool
    {
        return $this->path !== null;
    }

    private static function cleanName(string $name): string
    {
        $name = Names::lastSegment($name);

        return $name === '' ? self::DEFAULT_NAME : $name;
    }
}
