<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Support;

/**
 * Where the shared conformance suite lives: PDFCONVERSIONAPI_CONFORMANCE_DIR, else `conformance/`
 * next to this package (a standalone checkout), else `../conformance` (the SDK monorepo).
 */
final class Paths
{
    public static function conformanceDir(): ?string
    {
        $candidates = [
            getenv('PDFCONVERSIONAPI_CONFORMANCE_DIR') ?: null,
            dirname(__DIR__, 2) . '/conformance',
            dirname(__DIR__, 3) . '/conformance',
        ];
        foreach ($candidates as $dir) {
            if ($dir !== null && is_file($dir . '/cases.json')) {
                return realpath($dir) ?: $dir;
            }
        }

        return null;
    }

    /** The live tests' input files: PDFCONVERSIONAPI_SAMPLES_DIR, else the suite's `samples/`. */
    public static function samplesDir(): ?string
    {
        $fromEnv = getenv('PDFCONVERSIONAPI_SAMPLES_DIR');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }
        $dir = self::conformanceDir();

        return $dir === null ? null : $dir . '/samples';
    }

    public static function newTempDir(string $prefix): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
