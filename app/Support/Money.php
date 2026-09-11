<?php

namespace App\Support;

final class Money
{
    /**
     * Dollar display matching ledger.html formatCad.
     * Always a $ on the amount. Negative uses U+2212, never a CAD/USD prefix.
     */
    public static function formatCad(float|int|null $n, int $digits = 2): string
    {
        $n = (float) $n;
        $abs = number_format(abs($n), $digits, '.', ',');
        if ($n < 0) {
            return '−$'.$abs;
        }

        return '$'.$abs;
    }

    /** Desktop signedMoney: +$1.23 / −$1.23 */
    public static function signedCad(float|int|null $n, int $digits = 2): string
    {
        if ($n === null || ! is_finite((float) $n)) {
            return '—';
        }
        $n = (float) $n;
        $body = self::formatCad($n, $digits);
        if ($n >= 0) {
            return '+'.$body;
        }

        return $body;
    }

    /** Desktop pct(): +3.45% / −1.20% from a ratio. */
    public static function signedPct(?float $n, int $digits = 2): string
    {
        if ($n === null || ! is_finite($n)) {
            return '—';
        }
        $pct = number_format(abs($n) * 100, $digits, '.', '').'%';

        return ($n < 0 ? '−' : '+').$pct;
    }

    public static function formatPct(?float $n): string
    {
        if ($n === null || ! is_finite($n)) {
            return '—';
        }

        return number_format($n * 100, 1).'%';
    }

    public static function formatReturn(?float $r): string
    {
        if ($r === null || ! is_finite($r)) {
            return '—';
        }
        $pct = number_format($r * 100, 1).'%';

        return $r > 0 ? '+'.$pct : $pct;
    }

    public static function formatNumber(float|int|null $n, int $digits = 2): string
    {
        return number_format((float) $n, $digits, '.', ',');
    }

    public static function formatHold(int|float $days): string
    {
        $days = (int) round($days);
        if ($days === 0) {
            return 'intraday';
        }
        if ($days === 1) {
            return '1d';
        }
        if ($days < 60) {
            return $days.'d';
        }
        $months = (int) round($days / 30.44);
        if ($months < 24) {
            return $days.'d (~'.$months.'mo)';
        }

        return $days.'d';
    }

    public static function pnlClass(float $n): string
    {
        if ($n > 0.0001) {
            return 'pos';
        }
        if ($n < -0.0001) {
            return 'neg';
        }

        return 'flat';
    }

    public static function isFinite(mixed $n): bool
    {
        return is_numeric($n) && is_finite((float) $n);
    }
}
