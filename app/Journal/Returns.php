<?php

namespace App\Journal;

final class Returns
{
    /** @var array<int, array{date:string,equity:float,netDeposits?:float}> */
    public static array $nav = [];

    public static function boot(array $navHistory, array $navByAccount, array $filters): void
    {
        $acc = $filters['account'] ?? '';
        $series = ($acc && ! empty($navByAccount[$acc]))
            ? $navByAccount[$acc]
            : $navHistory;
        usort($series, fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        self::$nav = $series;
    }

    public static function equityCurve(?string $from, ?string $to): array
    {
        $out = [];
        foreach (self::$nav as $p) {
            $d = $p['date'] ?? '';
            if ($d === '') {
                continue;
            }
            if ($from && $d < $from) {
                continue;
            }
            if ($to && $d > $to) {
                continue;
            }
            $out[] = ['date' => $d, 'equity' => (float) ($p['equity'] ?? 0)];
        }

        return $out;
    }

    public static function histNavOn(string $day): ?float
    {
        if (self::$nav === []) {
            return null;
        }
        $v = null;
        foreach (self::$nav as $p) {
            if (($p['date'] ?? '') > $day) {
                break;
            }
            $eq = (float) ($p['equity'] ?? NAN);
            if (is_finite($eq)) {
                $v = $eq;
            }
        }

        return $v;
    }

    public static function histNetDepositsOn(string $day): ?float
    {
        if (self::$nav === []) {
            return null;
        }
        $v = null;
        foreach (self::$nav as $p) {
            if (($p['date'] ?? '') > $day) {
                break;
            }
            if (isset($p['netDeposits']) && is_finite((float) $p['netDeposits'])) {
                $v = (float) $p['netDeposits'];
            }
        }

        return $v;
    }

    public static function yearWindow(string $year): array
    {
        $to = Dates::clampToToday($year.'-12-31');
        $cal = $year.'-01-01';
        if (self::$nav === []) {
            return ['from' => $cal, 'to' => $to, 'start' => null, 'flowAfter' => $cal];
        }
        $startDay = Dates::shiftIsoDate($cal, -1);
        $start = self::histNavOn($startDay);
        $from = $cal;
        $flowAfter = $startDay;
        if (! ($start > 0)) {
            $first = null;
            foreach (self::$nav as $p) {
                if (($p['date'] ?? '') >= $cal && ($p['date'] ?? '') <= $to) {
                    $first = $p;
                    break;
                }
            }
            if (! $first) {
                return ['from' => $cal, 'to' => $to, 'start' => null, 'flowAfter' => $cal];
            }
            $from = $first['date'];
            $start = (float) $first['equity'];
            $flowAfter = $from;
        }

        return ['from' => $from, 'to' => $to, 'start' => $start, 'flowAfter' => $flowAfter];
    }

    public static function bookReturn(string $from, string $to, mixed $start, ?string $flowAfter): ?float
    {
        if (self::$nav === []) {
            return null;
        }
        $after = $flowAfter ?: Dates::shiftIsoDate($from, -1);
        if ($start === null) {
            $start = self::histNavOn($after);
        }
        if (! ($start > 0)) {
            return null;
        }
        $pts = array_values(array_filter(self::$nav, fn ($p) => ($p['date'] ?? '') > $after && ($p['date'] ?? '') <= $to));
        if ($pts === []) {
            return null;
        }
        $prevEq = (float) $start;
        $prevNd = self::histNetDepositsOn($after);
        $factor = 1.0;
        foreach ($pts as $p) {
            $eq = (float) ($p['equity'] ?? NAN);
            if (! is_finite($eq) || ! ($prevEq > 0)) {
                return null;
            }
            $cf = 0.0;
            $nd = isset($p['netDeposits']) ? (float) $p['netDeposits'] : null;
            if ($nd !== null && is_finite($nd) && $prevNd !== null) {
                $cf = $nd - $prevNd;
            }
            $factor *= 1 + (($eq - $prevEq - $cf) / $prevEq);
            $prevEq = $eq;
            if ($nd !== null && is_finite($nd)) {
                $prevNd = $nd;
            }
        }
        $r = $factor - 1;
        if (! is_finite($r)) {
            return null;
        }

        return $r;
    }

    public static function tradeCostCad(array $t): float
    {
        $qty = (float) ($t['quantity'] ?? 0);
        $entry = (float) ($t['entryPrice'] ?? 0);
        $notional = abs($entry * $qty * Symbols::optionMultiplier($t['symbol'] ?? ''));
        if (! ($notional > 0)) {
            return 0;
        }
        $ccy = strtoupper((string) ($t['currency'] ?? 'CAD'));
        if ($ccy !== 'USD') {
            return $notional;
        }
        $cad = Fx::toCad($notional, $ccy, $t['entryDate'] ?? null);

        return is_finite($cad) ? abs($cad) : 0;
    }

    public static function closedYearReturn(array $trades, string $year): ?float
    {
        $from = $year.'-01-01';
        $to = Dates::clampToToday($year.'-12-31');
        $pnl = 0.0;
        $cost = 0.0;
        $n = 0;
        foreach ($trades as $tr) {
            $d = substr((string) ($tr['exitDate'] ?? ''), 0, 10);
            if ($d < $from || $d > $to) {
                continue;
            }
            $pnl += (float) ($tr['pnlCad'] ?? 0);
            $cost += self::tradeCostCad($tr);
            $n++;
        }
        if (! $n || ! ($cost > 0)) {
            return null;
        }

        return $pnl / $cost;
    }

    public static function yearsFromClosed(array $trades): array
    {
        $years = [];
        foreach ($trades as $t) {
            $d = substr((string) ($t['exitDate'] ?? ''), 0, 4);
            if (preg_match('/^\d{4}$/', $d)) {
                $years[$d] = true;
            }
        }
        $keys = array_keys($years);
        rsort($keys);

        return $keys;
    }

    public static function yearsFromActivities(array $activities): array
    {
        $years = [];
        foreach ($activities as $a) {
            $d = substr((string) ($a['transactionDate'] ?? ''), 0, 4);
            if (preg_match('/^\d{4}$/', $d)) {
                $years[$d] = true;
            }
        }
        $keys = array_keys($years);
        rsort($keys);

        return $keys;
    }

    public static function closedAnnualizedReturn(array $trades, array $years): array
    {
        $ys = $years;
        sort($ys);
        $prod = 1.0;
        $days = 0.0;
        $from = '';
        $to = '';
        $firstYear = '';
        $lastYear = '';
        foreach ($ys as $y) {
            $r = self::closedYearReturn($trades, $y);
            if ($r === null || ! is_finite($r) || $r <= -1) {
                continue;
            }
            $wfrom = $y.'-01-01';
            $wto = Dates::clampToToday($y.'-12-31');
            $d = Dates::isoDayDiff($wfrom, $wto);
            if (! ($d >= 30)) {
                continue;
            }
            $prod *= (1 + $r);
            $days += $d;
            if ($from === '') {
                $from = $wfrom;
                $firstYear = $y;
            }
            $to = $wto;
            $lastYear = $y;
        }
        if ($from === '' || ! ($days > 0)) {
            return ['rate' => null, 'from' => '', 'to' => '', 'years' => 0, 'firstYear' => '', 'lastYear' => ''];
        }
        $yrs = $days / 365.25;
        $rate = $yrs >= 1 / 12 ? pow($prod, 1 / $yrs) - 1 : $prod - 1;

        return ['rate' => $rate, 'from' => $from, 'to' => $to, 'years' => $yrs, 'firstYear' => $firstYear, 'lastYear' => $lastYear];
    }

    public static function accountAnnualizedReturn(array $years): array
    {
        $ys = $years;
        sort($ys);
        $prod = 1.0;
        $days = 0.0;
        $from = '';
        $to = '';
        $firstYear = '';
        $lastYear = '';
        foreach ($ys as $y) {
            $w = self::yearWindow($y);
            $r = self::bookReturn($w['from'], $w['to'], $w['start'], $w['flowAfter']);
            if ($r === null || ! is_finite($r) || $r <= -1) {
                continue;
            }
            $d = Dates::isoDayDiff($w['from'], $w['to']);
            if (! ($d >= 30)) {
                continue;
            }
            $prod *= (1 + $r);
            $days += $d;
            if ($from === '') {
                $from = $w['from'];
                $firstYear = $y;
            }
            $to = $w['to'];
            $lastYear = $y;
        }
        if ($from === '' || ! ($days > 0)) {
            return ['rate' => null, 'from' => '', 'to' => '', 'years' => 0, 'firstYear' => '', 'lastYear' => ''];
        }
        $yrs = $days / 365.25;
        $rate = $yrs >= 1 / 12 ? pow($prod, 1 / $yrs) - 1 : $prod - 1;

        return ['rate' => $rate, 'from' => $from, 'to' => $to, 'years' => $yrs, 'firstYear' => $firstYear, 'lastYear' => $lastYear];
    }

    public static function formatYearSpan(float $years): string
    {
        if (! ($years > 0)) {
            return '';
        }
        if ($years >= 1) {
            return number_format($years, 1).' yrs';
        }
        $months = max(1, (int) round($years * 12));

        return $months.' mo';
    }

    public static function compareRows(array $years, array $closedFx, array $filters): array
    {
        $out = [];
        foreach ($years as $year) {
            if (Filters::listingFiltersOn($filters)) {
                $mine = self::closedYearReturn($closedFx, $year);
                $idxFrom = $year.'-01-01';
                $idxTo = Dates::clampToToday($year.'-12-31');
            } else {
                $w = self::yearWindow($year);
                $mine = self::bookReturn($w['from'], $w['to'], $w['start'], $w['flowAfter']);
                $idxFrom = $w['from'];
                $idxTo = $w['to'];
            }
            $idx = Spy::return($idxFrom, $idxTo);
            $vs = ($mine !== null && $idx !== null) ? $mine - $idx : null;
            $out[] = ['year' => $year, 'mine' => $mine, 'spy' => $idx, 'vs' => $vs];
        }

        return $out;
    }
}
