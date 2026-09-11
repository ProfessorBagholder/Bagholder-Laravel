<?php

namespace App\Journal;

final class Filters
{
    public static function priceFilterOn(array $f): bool
    {
        if (empty($f['priceOp'])) {
            return false;
        }
        if (($f['priceOp'] ?? '') === 'between') {
            return self::parsePrice($f['priceMin'] ?? null) !== null && self::parsePrice($f['priceMax'] ?? null) !== null;
        }

        return self::parsePrice($f['priceMin'] ?? null) !== null;
    }


    /** Desktop ranges: hold / pnl / qty use under|over (More than / Less than). */
    public static function rangeFilterOn(array $f, string $key): bool
    {
        $op = (string) ($f[$key.'Op'] ?? '');
        if ($op === '' || ! in_array($op, ['under', 'over'], true)) {
            return false;
        }

        return self::parsePrice($f[$key.'Min'] ?? null) !== null;
    }

    public static function rangeMatches(mixed $val, string $op, mixed $threshold): bool
    {
        if (! is_numeric($val)) {
            return false;
        }
        $x = self::parsePrice($threshold);
        if ($x === null) {
            return true;
        }
        $v = (float) $val;
        if ($op === 'under') {
            return $v < $x;
        }
        if ($op === 'over') {
            return $v > $x;
        }

        return true;
    }

    public static function listingFiltersOn(array $f): bool
    {
        return ! empty($f['symbol']) || ! empty($f['exchange']) || self::priceFilterOn($f);
    }

    public static function parsePrice(mixed $s): ?float
    {
        if ($s === null || $s === '') {
            return null;
        }
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    public static function priceMatches(mixed $px, string $op, mixed $a, mixed $b): bool
    {
        if (! is_numeric($px)) {
            return false;
        }
        $p = (float) $px;
        $x = self::parsePrice($a);
        $y = self::parsePrice($b);
        if ($op === 'between') {
            if ($x === null || $y === null) {
                return true;
            }
            $lo = min($x, $y);
            $hi = max($x, $y);

            return $p >= $lo && $p <= $hi;
        }
        if ($x === null) {
            return true;
        }
        if ($op === 'under') {
            return $p < $x;
        }
        if ($op === 'over') {
            return $p > $x;
        }
        if ($op === 'equal') {
            return round($p * 10000) === round($x * 10000);
        }

        return true;
    }

    public static function rowPriceOk(array $prices, array $f): bool
    {
        if (! self::priceFilterOn($f)) {
            return true;
        }
        $list = array_values(array_filter($prices, fn ($px) => is_numeric($px)));
        if ($list === []) {
            return false;
        }
        foreach ($list as $px) {
            if (! self::priceMatches($px, $f['priceOp'] ?? '', $f['priceMin'] ?? null, $f['priceMax'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Shares | Options | Crypto — desktop kind_of parity for filter chips. */
    public static function tradeKind(array $t): string
    {
        $explicit = (string) ($t['kind'] ?? '');
        if (in_array($explicit, ['Shares', 'Options', 'Crypto'], true)) {
            return $explicit;
        }
        $sym = (string) ($t['symbol'] ?? '');
        if ($sym !== '' && str_contains($sym, ' ')) {
            return 'Options';
        }
        $ccy = strtoupper((string) ($t['currency'] ?? ''));
        if (in_array($ccy, ['BTC', 'ETH', 'USDC', 'USDT'], true)) {
            return 'Crypto';
        }

        return 'Shares';
    }

    public static function tradeResult(array $t): string
    {
        $pnl = (float) ($t['pnlCad'] ?? $t['pnl'] ?? 0);
        if ($pnl > 0.0001) {
            return 'win';
        }
        if ($pnl < -0.0001) {
            return 'loss';
        }

        return 'breakeven';
    }

    public static function filteredTrades(array $closedFx, array $f): array
    {
        return array_values(array_filter($closedFx, function ($t) use ($f) {
            if (! empty($f['from']) && ($t['exitDate'] ?? '') < $f['from']) {
                return false;
            }
            if (! empty($f['to']) && ($t['exitDate'] ?? '') > $f['to']) {
                return false;
            }
            if (! empty($f['account']) && ($t['accountId'] ?? '') !== $f['account'] && ($t['accountType'] ?? '') !== $f['account']) {
                return false;
            }
            if (! empty($f['symbol']) && Symbols::underlyingSymbol($t['symbol'] ?? '') !== $f['symbol']) {
                return false;
            }
            if (! empty($f['exchange']) && Listings::listingExchange($t) !== $f['exchange']) {
                return false;
            }
            if (! self::rowPriceOk([$t['entryPrice'] ?? null, $t['exitPrice'] ?? null], $f)) {
                return false;
            }
            if (self::rangeFilterOn($f, 'hold') && ! self::rangeMatches($t['holdDays'] ?? null, $f['holdOp'], $f['holdMin'] ?? null)) {
                return false;
            }
            if (self::rangeFilterOn($f, 'pnl') && ! self::rangeMatches($t['pnlCad'] ?? $t['pnl'] ?? null, $f['pnlOp'], $f['pnlMin'] ?? null)) {
                return false;
            }
            if (self::rangeFilterOn($f, 'qty') && ! self::rangeMatches($t['quantity'] ?? $t['qty'] ?? null, $f['qtyOp'], $f['qtyMin'] ?? null)) {
                return false;
            }
            if (! empty($f['kind']) && self::tradeKind($t) !== $f['kind']) {
                return false;
            }
            if (! empty($f['side'])) {
                $want = strtoupper((string) $f['side']);
                $side = strtoupper((string) ($t['displaySide'] ?? $t['side'] ?? ''));
                $dir = strtoupper((string) ($t['openDirection'] ?? ''));
                // Desktop filter sides are SELL / COVER; keep BUY/SHORT as aliases.
                $ok = $side === $want
                    || ($want === 'COVER' && ($side === 'COVER' || $dir === 'SHORT'))
                    || ($want === 'SHORT' && ($dir === 'SHORT' || $side === 'COVER'))
                    || ($want === 'BUY' && (str_starts_with($side, 'BUY') || $side === 'COVER'))
                    || ($want === 'SELL' && (str_starts_with($side, 'SELL') || $side === 'SELL'));
                if (! $ok) {
                    return false;
                }
            }
            if (! empty($f['result'])) {
                $map = [
                    'win' => 'win',
                    'loss' => 'loss',
                    'breakeven' => 'breakeven',
                    'Winners' => 'win',
                    'Losers' => 'loss',
                    'Breakeven' => 'breakeven',
                ];
                $want = $map[(string) $f['result']] ?? strtolower((string) $f['result']);
                if (self::tradeResult($t) !== $want) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function filteredActivities(array $activities, array $f): array
    {
        return array_values(array_filter($activities, function ($a) use ($f) {
            if (! empty($f['from']) && ($a['transactionDate'] ?? '') < $f['from']) {
                return false;
            }
            if (! empty($f['to']) && ($a['transactionDate'] ?? '') > $f['to']) {
                return false;
            }
            if (! empty($f['account']) && ($a['accountId'] ?? '') !== $f['account'] && ($a['accountType'] ?? '') !== $f['account']) {
                return false;
            }
            if (! empty($f['symbol']) && ! empty($a['symbol']) && Symbols::underlyingSymbol($a['symbol']) !== $f['symbol']) {
                return false;
            }
            if (! empty($f['exchange']) && Listings::listingExchange($a) !== $f['exchange']) {
                return false;
            }
            if (! self::rowPriceOk([$a['unitPrice'] ?? null], $f)) {
                return false;
            }
            if (self::rangeFilterOn($f, 'qty') && ! self::rangeMatches($a['quantity'] ?? null, $f['qtyOp'], $f['qtyMin'] ?? null)) {
                return false;
            }

            return true;
        }));
    }

    public static function sortRows(array $rows, callable $keyFn, string $dir): array
    {
        $m = $dir === 'asc' ? 1 : -1;
        $copy = $rows;
        usort($copy, function ($a, $b) use ($keyFn, $m) {
            return $m * self::cmp($keyFn($a), $keyFn($b));
        });

        return $copy;
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null || $a === '') {
            return 1;
        }
        if ($b === null || $b === '') {
            return -1;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return ((float) $a) <=> ((float) $b);
        }

        return strnatcasecmp((string) $a, (string) $b);
    }

    public static function unique(array $arr): array
    {
        $out = array_values(array_unique(array_filter($arr, fn ($v) => $v !== null && $v !== '')));
        natcasesort($out);

        return array_values($out);
    }
}
