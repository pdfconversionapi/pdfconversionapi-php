<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\FileSystemException;
use PdfConversionApi\UploadFile;

/**
 * A multipart/form-data body, written by hand so file names go out as raw UTF-8 in
 * `filename="..."` (the only form the API reads) and file content is streamed, not copied.
 * File names get `_` for `"`, CR and LF; field names get %22, %0D and %0A (see Names).
 *
 * @internal
 */
final class MultipartBody
{
    private const CHUNK = 1048576;

    public readonly string $boundary;

    /** @var list<array{string, string|UploadFile}> */
    private readonly array $parts;

    /** @var list<string|resource> */
    private array $segments = [];

    /** @var list<int> */
    private array $sizes = [];

    /** @var list<resource> */
    private array $owned = [];

    private int $index = 0;
    private int $offset = 0;
    private ?string $failure = null;

    /**
     * @param list<array{string, string|UploadFile}> $parts field name and text value or file, in order
     */
    public function __construct(array $parts, ?string $boundary = null)
    {
        $this->parts = $parts;
        $this->boundary = $boundary ?? 'pdfconversionapi-' . bin2hex(random_bytes(16));
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /**
     * Opens every input for one attempt and returns the body's length in bytes.
     *
     * @throws FileSystemException when an input file can't be opened
     */
    public function open(): int
    {
        $this->close();
        $this->index = 0;
        $this->offset = 0;
        $this->failure = null;
        $dash = '--' . $this->boundary;
        $pending = '';
        try {
            foreach ($this->parts as [$name, $value]) {
                if ($value instanceof UploadFile) {
                    $pending .= $dash . "\r\n"
                        . 'Content-Disposition: form-data; name="' . Names::fieldName($name) . '"; filename="' . Names::fileName($value->name) . "\"\r\n"
                        . "Content-Type: application/octet-stream\r\n\r\n";
                    [$size, $content] = $value->open();
                    if (!is_string($content) && $value->ownsHandle()) {
                        $this->owned[] = $content;
                    }
                    $this->push($pending);
                    $pending = "\r\n";
                    if ($size > 0) {
                        $this->segments[] = $content;
                        $this->sizes[] = $size;
                    }
                } else {
                    $pending .= $dash . "\r\n"
                        . 'Content-Disposition: form-data; name="' . Names::fieldName($name) . "\"\r\n\r\n"
                        . $value . "\r\n";
                }
            }
            $pending .= $dash . "--\r\n";
            $this->push($pending);
        } catch (FileSystemException $e) {
            $this->close();
            throw $e;
        }

        return array_sum($this->sizes);
    }

    /** The next bytes of the body, at most $length; '' at the end. */
    public function read(int $length): string
    {
        while ($this->index < count($this->segments)) {
            $segment = $this->segments[$this->index];
            $size = $this->sizes[$this->index];
            $left = $size - $this->offset;
            if ($left <= 0) {
                $this->index++;
                $this->offset = 0;
                continue;
            }
            $want = max(1, min($length, $left, self::CHUNK));
            if (is_string($segment)) {
                $chunk = substr($segment, $this->offset, $want);
            } else {
                $chunk = fread($segment, $want);
                if ($chunk === false || $chunk === '') {
                    $this->failure = 'an input file got shorter while it was being sent';

                    return '';
                }
            }
            $this->offset += strlen($chunk);

            return $chunk;
        }

        return '';
    }

    /** Why the last attempt's body couldn't be read in full, if it couldn't. */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /** Closes the files this body opened. */
    public function close(): void
    {
        foreach ($this->owned as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->owned = [];
        $this->segments = [];
        $this->sizes = [];
    }

    private function push(string $text): void
    {
        if ($text !== '') {
            $this->segments[] = $text;
            $this->sizes[] = strlen($text);
        }
    }
}
