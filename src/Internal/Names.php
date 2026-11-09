<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\InvalidResponseException;

/**
 * File name rules for uploads and for writing results to disk.
 *
 * @internal
 */
final class Names
{
    /** Everything after the last `/` or `\`. */
    public static function lastSegment(string $name): string
    {
        $slash = strrpos($name, '/');
        $backslash = strrpos($name, '\\');
        $cut = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash);

        return $cut < 0 ? $name : substr($name, $cut + 1);
    }

    /**
     * A file name for `filename="..."`: raw UTF-8, with `"`, CR and LF replaced by `_`. The API
     * reads `%22` literally, and it turns a quote into `_` itself when it cleans a file name, so
     * `_` gives the name it would have chosen.
     */
    public static function fileName(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '_', $name);
    }

    /**
     * A field (parameter) name for `name="..."`, with `"`, CR and LF escaped as %22, %0D and %0A.
     * Such a name is invalid for the API, and escaping keeps it invalid, so the API answers 400
     * instead of silently ignoring a name that `_` would have made valid but unknown.
     */
    public static function fieldName(string $name): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $name);
    }

    /**
     * The name to write an output file under: its last part only. Refuses names that would point
     * outside the folder or can't be a file name.
     *
     * @throws InvalidResponseException
     */
    public static function safeOutputName(string $name): string
    {
        $last = self::lastSegment($name);
        if ($last === '' || $last === '.' || $last === '..' || str_contains($last, "\0")) {
            throw new InvalidResponseException(sprintf('the API returned a file name that is not safe to write: "%s"', addcslashes($name, "\0..\37")));
        }

        return $last;
    }

    /** The extension of a path's last part, without the dot; '' when it has none. */
    public static function extension(string $path): string
    {
        $last = self::lastSegment($path);
        $dot = strrpos($last, '.');
        if ($dot === false || $dot === 0) {
            return '';
        }

        return substr($last, $dot + 1);
    }
}
