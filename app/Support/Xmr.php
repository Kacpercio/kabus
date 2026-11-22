<?php

namespace App\Support;

/**
 * Exact XMR arithmetic on decimal strings (12 decimal places = 1 piconero).
 *
 * Floats cannot represent most XMR amounts exactly, so all escrow math goes
 * through bcmath instead.
 */
final class Xmr
{
    public const SCALE = 12;

    /** Normalise any numeric value to a 12-decimal string. */
    public static function of(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.000000000000';
        }

        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }

        return bcadd((string) $value, '0', self::SCALE);
    }

    /** Convert atomic units (piconero) to XMR. */
    public static function fromAtomic(int|string $atomic): string
    {
        return bcdiv((string) $atomic, '1000000000000', self::SCALE);
    }

    /** Convert XMR to atomic units (piconero), rounding down. */
    public static function toAtomic(string|int|float $xmr): string
    {
        return bcmul(self::of($xmr), '1000000000000', 0);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::of($a), (string) $b, self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        return bcdiv(self::of($a), (string) $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isPositive(string|int|float|null $a): bool
    {
        return self::cmp(self::of($a), '0') > 0;
    }
}
