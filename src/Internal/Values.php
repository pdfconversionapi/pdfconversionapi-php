<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

/**
 * How parameter values are written in the form.
 *
 * @internal
 */
final class Values
{
    /**
     * A text parameter's value: strings as they are, integers and floats in plain decimal,
     * booleans as `true`/`false`, null as "not sent".
     *
     * @throws \InvalidArgumentException for values that aren't text, numbers, booleans or null
     */
    public static function format(string $name, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::formatFloat($name, $value),
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(sprintf(
                'parameter %s must be a string, number, boolean, null or a file, %s given',
                $name,
                get_debug_type($value),
            )),
        };
    }

    /** The shortest plain decimal that reads back as the same float: 72.0 is `72`, 1.5e-7 is `0.00000015`. */
    public static function formatFloat(string $name, float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException(sprintf('parameter %s must be a finite number', $name));
        }
        if ($value == 0.0) {
            return '0';
        }
        $text = '';
        for ($digits = 1; $digits <= 17; $digits++) {
            $text = sprintf('%.' . ($digits - 1) . 'e', $value);
            if ((float) $text === $value) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $text);
        $negative = $mantissa[0] === '-';
        $digitString = rtrim(str_replace(['-', '+', '.'], '', $mantissa), '0');
        $point = (int) $exponent + 1; // digits before the decimal point
        $length = strlen($digitString);
        if ($point <= 0) {
            $plain = '0.' . str_repeat('0', -$point) . $digitString;
        } elseif ($point >= $length) {
            $plain = $digitString . str_repeat('0', $point - $length);
        } else {
            $plain = substr($digitString, 0, $point) . '.' . substr($digitString, $point);
        }

        return ($negative ? '-' : '') . $plain;
    }
}
