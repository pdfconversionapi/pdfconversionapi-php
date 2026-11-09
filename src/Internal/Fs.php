<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Exception\FileSystemException;

/**
 * File system helpers that throw FileSystemException instead of returning false with a warning.
 * Results are written to a temporary file next to the target and renamed over it, so a failed
 * write never leaves a half-written file under the real name.
 *
 * @internal
 */
final class Fs
{
    /**
     * Creates a new temporary file in $dir for a result that will be renamed to $forName.
     *
     * @return array{string, resource} the temporary path and a write handle
     */
    public static function createTemp(string $dir, string $forName): array
    {
        $dir = $dir === '' ? '.' : $dir;
        if (!is_dir($dir)) {
            throw new FileSystemException(sprintf('cannot write into "%s": the folder does not exist', $dir));
        }
        for ($try = 0; $try < 5; $try++) {
            $path = $dir . DIRECTORY_SEPARATOR . '.' . substr($forName, 0, 100) . '.' . bin2hex(random_bytes(6)) . '.part';
            $handle = self::quietly(static fn () => fopen($path, 'xb'), $error);
            if (is_resource($handle)) {
                return [$path, $handle];
            }
        }
        throw new FileSystemException(sprintf('could not create a file in "%s": %s', $dir, $error ?? 'unknown error'));
    }

    /**
     * Writes $data to $path, replacing any file there.
     *
     * @throws FileSystemException
     */
    public static function writeFile(string $path, string $data): void
    {
        [$temp, $handle] = self::createTemp(dirname($path), basename($path));
        try {
            $length = strlen($data);
            for ($written = 0; $written < $length; $written += $count) {
                $count = self::quietly(static fn () => fwrite($handle, substr($data, $written, 8388608)), $error);
                if (!is_int($count) || $count === 0) {
                    throw new FileSystemException(sprintf('could not write "%s": %s', $path, $error ?? 'disk full?'));
                }
            }
            if (!fclose($handle)) {
                throw new FileSystemException(sprintf('could not write "%s"', $path));
            }
            self::moveInto($temp, $path);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            self::remove($temp);
        }
    }

    /**
     * Renames a finished temporary file to its real name, replacing any file there.
     *
     * @throws FileSystemException
     */
    public static function moveInto(string $temp, string $path): void
    {
        if (self::quietly(static fn () => rename($temp, $path), $error) !== true) {
            throw new FileSystemException(sprintf('could not write "%s": %s', $path, $error ?? 'unknown error'));
        }
    }

    /**
     * Creates a folder and its parents when they don't exist yet.
     *
     * @throws FileSystemException
     */
    public static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (file_exists($dir)) {
            throw new FileSystemException(sprintf('"%s" exists and is not a folder', $dir));
        }
        if (self::quietly(static fn () => mkdir($dir, 0o777, true), $error) !== true && !is_dir($dir)) {
            throw new FileSystemException(sprintf('could not create the folder "%s": %s', $dir, $error ?? 'unknown error'));
        }
    }

    /**
     * Reads at most $max bytes of a file.
     *
     * @param int<0, max> $max
     */
    public static function readHead(string $path, int $max): string
    {
        $data = self::quietly(static fn () => file_get_contents($path, false, null, 0, $max), $error);

        return is_string($data) ? $data : '';
    }

    public static function remove(string $path): void
    {
        if (is_file($path)) {
            self::quietly(static fn () => unlink($path), $error);
        }
    }

    /**
     * Runs $operation with PHP warnings captured in $error instead of printed.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @param-out string|null $error
     *
     * @return T
     */
    private static function quietly(\Closure $operation, ?string &$error = null): mixed
    {
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = preg_replace('/^[a-z_]+\(.*?\): /', '', $message) ?? $message;

            return true;
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
