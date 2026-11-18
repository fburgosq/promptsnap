<?php

namespace App\Support;

use InvalidArgumentException;

final class Nano
{
    public const int PER_DOLLAR = 1_000_000_000;

    public const int PER_MILLION_TOKENS_FACTOR = 1000;

    public const int SCALE = 9;

    /**
     * Parse an exact USD decimal string (e.g. "0.046125000", "12.50") into integer nanodollars.
     * Uses pure string validation and integer arithmetic — never floats.
     */
    public static function fromDecimalString(string $value): int
    {
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,9})?$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid USD decimal string: [{$value}].");
        }

        $parts = explode('.', $value, 2);
        $wholeStr = ltrim($parts[0], '0');
        $whole = $wholeStr === '' ? 0 : (int) $wholeStr;

        if ($whole > intdiv(PHP_INT_MAX, self::PER_DOLLAR)) {
            throw new InvalidArgumentException("Decimal value overflows integer nanodollars: [{$value}].");
        }

        $fractionPadded = str_pad($parts[1] ?? '', self::SCALE, '0', STR_PAD_RIGHT);
        $fraction = (int) $fractionPadded;

        return ($whole * self::PER_DOLLAR) + $fraction;
    }

    /**
     * Convert integer nanodollars to an exact fixed-scale decimal string.
     */
    public static function toDecimalString(int $nanodollars, int $scale = self::SCALE): string
    {
        if ($nanodollars < 0) {
            throw new InvalidArgumentException("Nanodollars cannot be negative: [{$nanodollars}].");
        }

        if ($scale < 0 || $scale > self::SCALE) {
            throw new InvalidArgumentException("Scale must be between 0 and 9, got [{$scale}].");
        }

        $whole = intdiv($nanodollars, self::PER_DOLLAR);
        $remainder = $nanodollars % self::PER_DOLLAR;

        if ($scale === 0) {
            return (string) $whole;
        }

        $fractionFull = str_pad((string) $remainder, self::SCALE, '0', STR_PAD_LEFT);
        $fraction = substr($fractionFull, 0, $scale);

        return "{$whole}.{$fraction}";
    }

    /**
     * Format integer nanodollars for display:
     * prefixed with $, minimum 4 decimals, trailing zeros trimmed up to 9 decimals.
     */
    public static function format(int $nanodollars, int $minDecimals = 4): string
    {
        $decimal = self::toDecimalString($nanodollars, self::SCALE);
        [$whole, $fraction] = explode('.', $decimal, 2);

        $trimmed = rtrim($fraction, '0');
        $fractionFormatted = str_pad($trimmed, $minDecimals, '0', STR_PAD_RIGHT);

        return "\${$whole}.{$fractionFormatted}";
    }

    /**
     * Compute the cost in nanodollars for a token count at a given USD rate per 1M tokens.
     *
     * Since 1 USD = 1_000_000_000 nanodollars and rates are per 1_000_000 tokens:
     * cost_nano = tokens * rate_usd * (1_000_000_000 / 1_000_000)
     *           = tokens * rate_usd * 1000
     *           = (tokens * rate_nano) / 1_000_000
     *
     * Uses exact integer / bcmath arithmetic without floats.
     */
    public static function tokenCost(int $tokens, string $rateUsdPerMillion): int
    {
        if ($tokens < 0) {
            throw new InvalidArgumentException("Token count cannot be negative: [{$tokens}].");
        }

        if ($tokens === 0) {
            self::fromDecimalString($rateUsdPerMillion);

            return 0;
        }

        $rateNano = self::fromDecimalString($rateUsdPerMillion);

        if ($rateNano % 1_000_000 === 0) {
            $nanoPerToken = intdiv($rateNano, 1_000_000);

            return $tokens * $nanoPerToken;
        }

        $totalSubNano = $tokens * $rateNano;

        return intdiv($totalSubNano + 999_999, 1_000_000);
    }

    /**
     * Integer ceiling division: ceil(numerator / denominator) for non-negative integers.
     */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException("Denominator must be positive, got [{$denominator}].");
        }

        if ($numerator < 0) {
            throw new InvalidArgumentException("Numerator cannot be negative, got [{$numerator}].");
        }

        if ($numerator === 0) {
            return 0;
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
