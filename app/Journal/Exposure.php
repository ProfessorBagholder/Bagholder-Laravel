<?php

namespace App\Journal;

/**
 * Port of Bagholder model.exposure_slices + exposure.norm_sector for Portfolio
 * Sectors / Regions donuts. Records come from desktop bagholder.db exposures
 * (imported into Meta by MarketImport).
 */
final class Exposure
{
    public const UNCLASSIFIED = 'Not classified';

    /** @var array<string, string> */
    private const SECTOR_ALIAS = [
        'information technology' => 'Information Technology',
        'information tech' => 'Information Technology',
        'technology' => 'Information Technology',
        'tech' => 'Information Technology',
        'it' => 'Information Technology',
        'financials' => 'Financials',
        'financial' => 'Financials',
        'finance' => 'Financials',
        'health care' => 'Health Care',
        'healthcare' => 'Health Care',
        'health' => 'Health Care',
        'consumer discretionary' => 'Consumer Discretionary',
        'consumer cyclicals' => 'Consumer Discretionary',
        'cyclical' => 'Consumer Discretionary',
        'consumer staples' => 'Consumer Staples',
        'consumer defensive' => 'Consumer Staples',
        'defensive' => 'Consumer Staples',
        'industrials' => 'Industrials',
        'industrial' => 'Industrials',
        'energy' => 'Energy',
        'materials' => 'Materials',
        'basic materials' => 'Materials',
        'utilities' => 'Utilities',
        'real estate' => 'Real Estate',
        'realestate' => 'Real Estate',
        'communication services' => 'Communication Services',
        'communications' => 'Communication Services',
        'communication' => 'Communication Services',
        'media' => 'Communication Services',
        'telecommunications services' => 'Communication Services',
        'telecommunications' => 'Communication Services',
        'telecommunication services' => 'Communication Services',
        'bitcoin holding' => 'Digital assets',
        'digital assets' => 'Digital assets',
        'cryptocurrency' => 'Digital assets',
        'crypto' => 'Digital assets',
        'cash and/or derivatives' => '',
        'cash' => '',
        'other' => '',
        '-' => '',
        'n/a' => '',
    ];

    public static function normSector(?string $name): string
    {
        $key = strtolower(trim((string) $name));
        if ($key === '') {
            return '';
        }
        if (array_key_exists($key, self::SECTOR_ALIAS)) {
            return self::SECTOR_ALIAS[$key];
        }

        return trim((string) $name);
    }

    /**
     * Open long positions by sector and by country (desktop exposure_slices).
     *
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, array{sectors?: array<string, float|int>, countries?: array<string, float|int>}>  $exposures
     * @return array{0: list<array{name:string,value:float,share:float}>, 1: list<array{name:string,value:float,share:float}>}
     */
    public static function slices(array $positions, array $exposures): array
    {
        $secTot = [];
        $ctyTot = [];
        $secUnc = 0.0;
        $ctyUnc = 0.0;
        $total = 0.0;

        foreach ($positions as $p) {
            $mv = $p['mv'] ?? null;
            if ($mv === null) {
                continue;
            }
            $v = (float) $mv;
            if ($v <= 0) {
                continue;
            }
            $total += $v;

            $kind = (string) ($p['kind'] ?? Filters::tradeKind($p));
            $rec = self::recordFor($p, $exposures);

            if ($kind === 'Crypto') {
                $sMap = ['Digital assets' => 1.0];
                $cMap = [];
            } else {
                $sMap = is_array($rec['sectors'] ?? null) ? $rec['sectors'] : [];
                $cMap = is_array($rec['countries'] ?? null) ? $rec['countries'] : [];
            }

            $sSum = 0.0;
            foreach ($sMap as $w) {
                $sSum += (float) $w;
            }
            $cSum = 0.0;
            foreach ($cMap as $w) {
                $cSum += (float) $w;
            }

            foreach ($sMap as $n => $w) {
                $n = self::normSector((string) $n) ?: (string) $n;
                if ($n === '') {
                    continue;
                }
                $secTot[$n] = ($secTot[$n] ?? 0.0) + $v * (float) $w;
            }
            foreach ($cMap as $n => $w) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $ctyTot[$n] = ($ctyTot[$n] ?? 0.0) + $v * (float) $w;
            }
            $secUnc += $v * max(0.0, 1.0 - min(1.0, $sSum));
            $ctyUnc += $v * max(0.0, 1.0 - min(1.0, $cSum));
        }

        return [
            self::rows($secTot, $secUnc, $total),
            self::rows($ctyTot, $ctyUnc, $total),
        ];
    }

    /**
     * Cap known slices + Other + Not classified last (desktop exposureSlices).
     *
     * @param  list<array{name:string,value:float,share:float}>  $rows
     * @return list<array{label:string,value:float,share:float,color:string}>
     */
    public static function displayItems(array $rows, int $cap = 10): array
    {
        $known = array_values(array_filter($rows, fn ($x) => ($x['name'] ?? '') !== self::UNCLASSIFIED));
        $unc = null;
        foreach ($rows as $x) {
            if (($x['name'] ?? '') === self::UNCLASSIFIED) {
                $unc = $x;
                break;
            }
        }

        // Keep meaningful slices only so legend rows ≡ visible arc mass (no 0.0% colour-index noise).
        $kept = [];
        $folded = [];
        foreach ($known as $x) {
            $v = (float) ($x['value'] ?? 0);
            $s = (float) ($x['share'] ?? 0);
            if ($v > 0 && $s >= 0.005) {
                $kept[] = $x;
            } elseif ($v > 0) {
                $folded[] = $x;
            }
        }
        $top = array_slice($kept, 0, $cap);
        $rest = array_merge(array_slice($kept, $cap), $folded);
        $items = [];
        foreach ($top as $i => $x) {
            $items[] = [
                'label' => (string) $x['name'],
                'value' => (float) $x['value'],
                'share' => (float) $x['share'],
                'color' => 'var(--bh-pie-'.(($i % 11) + 1).')',
            ];
        }
        if ($rest !== []) {
            $items[] = [
                'label' => 'Other ('.count($rest).')',
                'value' => array_sum(array_map(fn ($x) => (float) $x['value'], $rest)),
                'share' => array_sum(array_map(fn ($x) => (float) $x['share'], $rest)),
                'color' => 'var(--bh-pie-'.((count($top) % 11) + 1).')',
            ];
        }
        if ($unc !== null && (float) ($unc['value'] ?? 0) > 0) {
            $items[] = [
                'label' => self::UNCLASSIFIED,
                'value' => (float) $unc['value'],
                'share' => (float) $unc['share'],
                // Solid grey (desktop neutral) — rgba/color-mix as SVG stroke was misread as cyan.
                'color' => '#8a909c',
            ];
        }

        // Recompute shares from displayed values so legend % ≡ arc mass exactly.
        $mass = array_sum(array_map(fn ($x) => (float) $x['value'], $items));
        if ($mass > 0) {
            foreach ($items as &$it) {
                $it['share'] = ((float) $it['value']) / $mass;
            }
            unset($it);
        }

        return $items;
    }

    public static function classifiedCount(array $rows): int
    {
        $n = 0;
        foreach ($rows as $x) {
            if (($x['name'] ?? '') !== self::UNCLASSIFIED && (float) ($x['value'] ?? 0) > 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, array<string, mixed>>  $exposures
     * @return array{sectors: array<string, float|int>, countries: array<string, float|int>}
     */
    public static function recordFor(array $p, array $exposures): array
    {
        $empty = ['sectors' => [], 'countries' => []];
        $kind = (string) ($p['kind'] ?? Filters::tradeKind($p));

        $rec = [];
        $sid = trim((string) ($p['securityId'] ?? ''));
        if ($sid !== '' && isset($exposures[$sid]) && is_array($exposures[$sid])) {
            $rec = $exposures[$sid];
        }

        if ($kind === 'Options' || self::isEmptyRec($rec)) {
            $under = strtoupper(trim((string) ($p['underlying'] ?? Symbols::underlyingSymbol($p['symbol'] ?? ''))));
            if ($under !== '' && $under !== '—') {
                $us = 'share:'.$under.'::US';
                $ca = 'share:'.$under.':';
                $ccy = strtoupper((string) ($p['currency'] ?? ''));
                $first = $ccy === 'USD' ? $us : $ca;
                $second = $ccy === 'USD' ? $ca : $us;
                if ($kind === 'Options') {
                    $rec = $exposures[$first] ?? $exposures[$second] ?? [];
                } elseif (self::isEmptyRec($rec)) {
                    $fund = $exposures['fund:'.$under] ?? null;
                    $share = $exposures[$first] ?? $exposures[$second] ?? null;
                    if (is_array($fund) && ! self::isEmptyRec($fund)) {
                        $rec = $fund;
                    } elseif (is_array($share)) {
                        $rec = $share;
                    }
                }
            }
        }

        return [
            'sectors' => is_array($rec['sectors'] ?? null) ? $rec['sectors'] : [],
            'countries' => is_array($rec['countries'] ?? null) ? $rec['countries'] : [],
        ];
    }

    /** @param  array<string, mixed>  $rec */
    private static function isEmptyRec(array $rec): bool
    {
        $s = $rec['sectors'] ?? [];
        $c = $rec['countries'] ?? [];

        return (! is_array($s) || $s === []) && (! is_array($c) || $c === []);
    }

    /**
     * @param  array<string, float>  $tot
     * @return list<array{name:string,value:float,share:float}>
     */
    private static function rows(array $tot, float $unc, float $total): array
    {
        $out = [];
        foreach ($tot as $n => $v) {
            if ($v > 0) {
                $out[] = ['name' => (string) $n, 'value' => (float) $v];
            }
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);
        if ($unc > 0.005) {
            $out[] = ['name' => self::UNCLASSIFIED, 'value' => $unc];
        }
        foreach ($out as &$x) {
            $x['share'] = $total > 0 ? ($x['value'] / $total) : 0.0;
        }
        unset($x);

        return $out;
    }
}
