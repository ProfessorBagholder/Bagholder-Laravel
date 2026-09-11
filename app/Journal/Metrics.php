<?php

namespace App\Journal;

use App\Support\Money;

final class Metrics
{
    public static function compute(array $trades, int $openLotCount = 0, float $incomeCad = 0, float $feesCad = 0): array
    {
        $wins = array_values(array_filter($trades, fn ($t) => ($t['pnlCad'] ?? 0) > 0));
        $losses = array_values(array_filter($trades, fn ($t) => ($t['pnlCad'] ?? 0) < 0));
        $grossProfit = array_sum(array_map(fn ($t) => (float) $t['pnlCad'], $wins));
        $grossLoss = abs(array_sum(array_map(fn ($t) => (float) $t['pnlCad'], $losses)));
        $avgWin = count($wins) ? $grossProfit / count($wins) : 0;
        $avgLoss = count($losses) ? -$grossLoss / count($losses) : 0;
        $n = count($trades);
        $winRate = $n ? count($wins) / $n : 0;
        $lossRate = $n ? count($losses) / $n : 0;
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? INF : 0);
        $expectancy = $n ? $winRate * $avgWin + $lossRate * $avgLoss : 0;
        $curve = self::realizedPnlCurve($trades);
        $realizedPnlCad = array_sum(array_map(fn ($t) => (float) $t['pnlCad'], $trades));
        $avgHoldDays = $n ? array_sum(array_map(fn ($t) => (float) ($t['holdDays'] ?? 0), $trades)) / $n : 0;

        $bySym = [];
        foreach ($trades as $t) {
            $k = Symbols::underlyingSymbol($t['symbol'] ?? '');
            $bySym[$k] = ($bySym[$k] ?? 0) + (float) $t['pnlCad'];
        }
        $maxWinSymbol = '';
        $maxWinPnl = 0.0;
        $maxLossSymbol = '';
        $maxLossPnl = 0.0;
        foreach ($bySym as $sym => $pnl) {
            if ($pnl > $maxWinPnl) {
                $maxWinPnl = $pnl;
                $maxWinSymbol = $sym;
            }
            if ($pnl < $maxLossPnl) {
                $maxLossPnl = $pnl;
                $maxLossSymbol = $sym;
            }
        }
        $best = null;
        $worst = null;
        foreach ($trades as $t) {
            if ($best === null || ($t['pnlCad'] ?? 0) > ($best['pnlCad'] ?? 0)) {
                $best = $t;
            }
            if ($worst === null || ($t['pnlCad'] ?? 0) < ($worst['pnlCad'] ?? 0)) {
                $worst = $t;
            }
        }

        return [
            'realizedPnlCad' => $realizedPnlCad,
            'tradeCount' => $n,
            'winCount' => count($wins),
            'lossCount' => count($losses),
            'evenCount' => $n - count($wins) - count($losses),
            'grossProfit' => $grossProfit,
            'grossLoss' => $grossLoss,
            'winRate' => $winRate,
            'profitFactor' => $profitFactor,
            'avgWin' => $avgWin,
            'avgLoss' => $avgLoss,
            'expectancy' => $expectancy,
            'maxDrawdown' => self::maxDrawdown($curve),
            'maxWinPnl' => $maxWinPnl,
            'maxWinSymbol' => $maxWinSymbol,
            'maxLossPnl' => $maxLossPnl,
            'maxLossSymbol' => $maxLossSymbol,
            'avgHoldDays' => $avgHoldDays,
            'bestTrade' => $best,
            'worstTrade' => $worst,
            'incomeCad' => $incomeCad,
            'feesCad' => $feesCad,
            'openLotCount' => $openLotCount,
        ];
    }

    public static function realizedPnlCurve(array $trades): array
    {
        $sorted = $trades;
        usort($sorted, fn ($a, $b) => strcmp($a['exitDate'] ?? '', $b['exitDate'] ?? ''));
        $points = [];
        $equity = 0.0;
        foreach ($sorted as $t) {
            $equity += (float) ($t['pnlCad'] ?? 0);
            $last = $points[array_key_last($points)] ?? null;
            if ($last && $last['date'] === ($t['exitDate'] ?? '')) {
                $points[array_key_last($points)]['equity'] = $equity;
            } else {
                $points[] = ['date' => $t['exitDate'] ?? '', 'equity' => $equity];
            }
        }

        return $points;
    }

    public static function monthlyPnl(array $trades): array
    {
        $map = [];
        foreach ($trades as $t) {
            $month = substr((string) ($t['exitDate'] ?? ''), 0, 7);
            if ($month === '') {
                continue;
            }
            $map[$month] = ($map[$month] ?? 0) + (float) ($t['pnlCad'] ?? 0);
        }
        ksort($map);
        $out = [];
        foreach ($map as $month => $pnl) {
            $out[] = ['month' => $month, 'pnl' => $pnl];
        }

        return $out;
    }

    public static function maxDrawdown(array $points): float
    {
        $peak = 0.0;
        $maxDd = 0.0;
        foreach ($points as $p) {
            if ($p['equity'] > $peak) {
                $peak = $p['equity'];
            }
            $dd = $peak - $p['equity'];
            if ($dd > $maxDd) {
                $maxDd = $dd;
            }
        }

        return $maxDd;
    }

    public static function breakdown(array $trades, callable $keyFn): array
    {
        $map = [];
        foreach ($trades as $t) {
            $key = $keyFn($t) ?: '—';
            $map[$key] ??= ['key' => $key, 'label' => $key, 'pnlCad' => 0.0, 'trades' => 0, 'wins' => 0, 'hold' => 0.0];
            $map[$key]['pnlCad'] += (float) ($t['pnlCad'] ?? $t['pnl'] ?? 0);
            $map[$key]['trades'] += 1;
            $map[$key]['hold'] += (float) ($t['holdDays'] ?? 0);
            if (($t['pnlCad'] ?? $t['pnl'] ?? 0) > 0) {
                $map[$key]['wins'] += 1;
            }
        }
        $out = [];
        foreach ($map as $v) {
            $v['winRate'] = $v['trades'] ? $v['wins'] / $v['trades'] : 0;
            $v['avgHold'] = $v['trades'] ? $v['hold'] / $v['trades'] : 0;
            $out[] = $v;
        }
        usort($out, fn ($a, $b) => $b['pnlCad'] <=> $a['pnlCad']);

        return $out;
    }

    public static function gradeBuckets(array $trades): array
    {
        $buckets = [];
        foreach (['A', 'B', 'C', 'F'] as $g) {
            $rows = array_values(array_filter($trades, fn ($t) => ($t['grade'] ?? '') === $g));
            $buckets[] = [
                'grade' => $g,
                'n' => count($rows),
                'pnl' => array_sum(array_map(fn ($t) => (float) ($t['pnlCad'] ?? $t['pnl'] ?? 0), $rows)),
                'tradeIds' => array_map(fn ($t) => (string) ($t['id'] ?? ''), $rows),
            ];
        }
        $ungraded = count(array_filter($trades, fn ($t) => ($t['grade'] ?? '') === ''));
        $graded = count($trades) - $ungraded;

        return ['buckets' => $buckets, 'ungraded' => $ungraded, 'graded' => $graded];
    }

    public static function reviewQueue(array $trades): array
    {
        $out = [];
        foreach ($trades as $t) {
            $noGrade = ($t['grade'] ?? '') === '';
            $thesis = trim((string) ($t['notes'] ?? $t['thesis'] ?? ''));
            $noThesis = $thesis === '';
            if (! ($noGrade || $noThesis)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($t['id'] ?? ''),
                'symbol' => (string) ($t['displaySymbol'] ?? $t['symbol'] ?? ''),
                'date' => (string) ($t['exitDate'] ?? ''),
                'pnl' => (float) ($t['pnlCad'] ?? $t['pnl'] ?? 0),
                'missing' => ($noGrade && $noThesis) ? 'no grade or thesis' : ($noGrade ? 'no grade' : 'no thesis'),
            ];
        }

        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $out;
    }

    public static function profitFactorLabel(array $m): string
    {
        $pf = $m['profitFactor'];
        if ($pf === INF) {
            return '∞';
        }

        return is_finite($pf) ? number_format($pf, 2, '.', '') : '—';
    }

    public static function winRateSubtitle(array $m): string
    {
        return number_format($m['winCount']).' W, '.number_format($m['lossCount']).' L, '.number_format($m['evenCount']).' BE';
    }

    public static function profitFactorSubtitle(array $m): string
    {
        return Money::formatCad($m['grossProfit'], 2).' W, '.Money::formatCad($m['grossLoss'], 2).' L';
    }

    public static function closedPnlPct(array $t): ?float
    {
        $qty = (float) ($t['quantity'] ?? 0);
        $entry = (float) ($t['entryPrice'] ?? 0);
        if (! ($qty > 0) || ! ($entry > 0)) {
            return null;
        }
        $cost = $entry * $qty * Symbols::optionMultiplier($t['symbol'] ?? '');
        if (! ($cost > 0) || ! is_finite((float) ($t['pnl'] ?? NAN))) {
            return null;
        }

        return ((float) $t['pnl']) / $cost;
    }
}
