<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

use PdfConversionApi\Client;
use PdfConversionApi\Conversion;
use PdfConversionApi\ConversionResult;
use PdfConversionApi\Exception\InvalidResponseException;
use PdfConversionApi\OutputFile;
use PdfConversionApi\Usage;

/**
 * Reads the JSON bodies of successful responses.
 *
 * @internal
 */
final class ResponseParser
{
    /**
     * Parses a conversion's JSON. $body is passed by reference and emptied once decoded, and each
     * file's base64 text is freed as soon as it is decoded, so a large result isn't held in
     * memory several times over.
     *
     * @throws InvalidResponseException
     */
    public static function conversionResult(string &$body, ?string $requestId, Client $client): ConversionResult
    {
        $json = self::decode($body);
        $body = '';
        $entries = $json['Files'] ?? null;
        unset($json['Files']);
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new InvalidResponseException('the response has no Files');
        }
        $files = [];
        $count = count($entries);
        for ($index = 0; $index < $count; $index++) {
            $entry = $entries[$index];
            unset($entries[$index]);
            if (!is_array($entry)) {
                throw new InvalidResponseException(sprintf('Files[%d] is not an object', $index));
            }
            $files[] = self::outputFile($entry, $index, $client);
            unset($entry);
        }

        return new ConversionResult(
            $files,
            (int) self::number($json['ConversionCost'] ?? null, 0),
            (float) self::number($json['ConversionTime'] ?? null, 0.0),
            $requestId,
        );
    }

    /**
     * @throws InvalidResponseException
     */
    public static function usage(HttpResponse $response): Usage
    {
        $json = self::decode($response->body);
        $remaining = $json['Remaining'] ?? null;
        if ($remaining !== null && !is_int($remaining)) {
            throw new InvalidResponseException('Remaining is not an integer');
        }

        return new Usage(
            plan: self::requireString($json, 'Plan'),
            included: self::requireInt($json, 'Included'),
            used: self::requireInt($json, 'Used'),
            remaining: $remaining,
            overage: self::requireBool($json, 'Overage'),
            running: self::requireInt($json, 'Running'),
            concurrentLimit: self::requireInt($json, 'ConcurrentLimit'),
            maxUploadMb: self::requireInt($json, 'MaxUploadMB'),
            periodStart: self::requireDate($json, 'PeriodStart'),
            periodEnd: self::requireDate($json, 'PeriodEnd'),
        );
    }

    /**
     * @return list<Conversion>
     *
     * @throws InvalidResponseException
     */
    public static function conversions(HttpResponse $response): array
    {
        $json = self::decode($response->body);
        if (!array_is_list($json)) {
            throw new InvalidResponseException('the list of conversions is not a JSON array');
        }
        $list = [];
        foreach ($json as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidResponseException(sprintf('conversion %d is not an object', $index));
            }
            $list[] = new Conversion(
                self::requireString($entry, 'From'),
                self::requireString($entry, 'To'),
                self::optionalString($entry, 'Title'),
                self::optionalString($entry, 'Category'),
                self::optionalString($entry, 'Inputs'),
            );
        }

        return $list;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function outputFile(array &$entry, int $index, Client $client): OutputFile
    {
        $name = $entry['FileName'] ?? null;
        $size = $entry['FileSize'] ?? null;
        if (!is_string($name) || !is_int($size) || $size < 0) {
            throw new InvalidResponseException(sprintf('Files[%d] has no valid FileName and FileSize', $index));
        }
        $ext = is_string($entry['FileExt'] ?? null) ? $entry['FileExt'] : strtolower(Names::extension($name));
        $encoded = $entry['FileData'] ?? null;
        unset($entry['FileData']);
        if (is_string($encoded)) {
            $data = base64_decode($encoded, true);
            unset($encoded);
            if ($data === false) {
                throw new InvalidResponseException(sprintf('Files[%d].FileData is not valid base64', $index));
            }
            if (strlen($data) !== $size) {
                throw new InvalidResponseException(sprintf(
                    '%s: FileSize says %d bytes, but FileData holds %d',
                    $name,
                    $size,
                    strlen($data),
                ));
            }

            return new OutputFile($name, $ext, $size, data: $data, client: $client);
        }
        $url = $entry['Url'] ?? null;
        $fileId = $entry['FileId'] ?? null;
        if (is_string($url) && is_string($fileId)) {
            return new OutputFile($name, $ext, $size, url: $url, fileId: $fileId, client: $client);
        }
        throw new InvalidResponseException(sprintf('Files[%d] has neither FileData nor Url and FileId', $index));
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidResponseException('the response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($json)) {
            throw new InvalidResponseException('the response is not a JSON object or array');
        }

        return $json;
    }

    private static function number(mixed $value, int|float $default): int|float
    {
        return is_int($value) || is_float($value) ? $value : $default;
    }

    /** @param array<mixed> $json */
    private static function requireString(array $json, string $key): string
    {
        $value = $json[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidResponseException($key . ' is missing or not a string');
        }

        return $value;
    }

    /** @param array<mixed> $json */
    private static function optionalString(array $json, string $key): string
    {
        $value = $json[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<mixed> $json */
    private static function requireInt(array $json, string $key): int
    {
        $value = $json[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidResponseException($key . ' is missing or not an integer');
        }

        return $value;
    }

    /** @param array<mixed> $json */
    private static function requireBool(array $json, string $key): bool
    {
        $value = $json[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidResponseException($key . ' is missing or not a boolean');
        }

        return $value;
    }

    /** @param array<mixed> $json */
    private static function requireDate(array $json, string $key): \DateTimeImmutable
    {
        $value = self::requireString($json, $key);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            throw new InvalidResponseException($key . ' is not an ISO 8601 date-time');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new InvalidResponseException($key . ' is not a valid date-time', 0, $e);
        }
    }
}
