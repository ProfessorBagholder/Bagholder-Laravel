<?php

namespace App\Journal;

use App\Support\Money;

final class Charts
{
    /** Trade-detail timeframe pills — same ids/labels as ledger.html TIMEFRAMES. */
    public const TRADE_TFS = [
        ['1h', '1H'],
        ['4h', '4H'],
        ['1d', '1D'],
        ['1w', '1W'],
        ['1M', '1M'],
    ];

    public static function equity(array $points): string
    {
        $w = 640;
        $h = 220;
        $padL = 52;
        $padR = 12;
        $padT = 16;
        $padB = 28;
        if ($points === []) {
            return '<svg viewBox="0 0 640 220" class="chart" role="img" aria-label="Equity curve"><text x="320" y="110" text-anchor="middle" fill="#6b7380" font-size="12">No equity yet</text></svg>';
        }
        $ys = array_map(fn ($p) => (float) ($p['equity'] ?? 0), $points);
        $minY = min(0, ...$ys);
        $maxY = max(0, ...$ys);
        if ($minY === $maxY) {
            $minY -= 1;
            $maxY += 1;
        }
        $innerW = $w - $padL - $padR;
        $innerH = $h - $padT - $padB;
        $n = count($points);
        $x = function (int $i) use ($padL, $innerW, $n): float {
            return $n === 1 ? $padL + $innerW / 2 : $padL + ($i / ($n - 1)) * $innerW;
        };
        $y = function (float $v) use ($padT, $innerH, $maxY, $minY): float {
            return $padT + (($maxY - $v) / ($maxY - $minY)) * $innerH;
        };
        $d = '';
        foreach ($points as $i => $p) {
            $d .= ($i === 0 ? 'M' : 'L').number_format($x($i), 1, '.', '').' '.number_format($y((float) $p['equity']), 1, '.', '').' ';
        }
        $zero = $y(0);
        $last = $points[array_key_last($points)];
        $cls = ((float) $last['equity']) >= ((float) $points[0]['equity']) ? '#3ecf8e' : '#ef6b73';
        $grid = '';
        for ($g = 0; $g < 4; $g++) {
            $gy = $padT + ($innerH * $g) / 3;
            $gv = $maxY - (($maxY - $minY) * $g) / 3;
            $grid .= '<line x1="'.$padL.'" y1="'.number_format($gy, 1, '.', '').'" x2="'.($w - $padR).'" y2="'.number_format($gy, 1, '.', '').'" stroke="#262c36"/>';
            $grid .= '<text x="'.($padL - 6).'" y="'.number_format($gy + 3, 1, '.', '').'" text-anchor="end" fill="#6b7380" font-size="10">'.e(self::axisCad($gv)).'</text>';
        }
        $hits = '';
        foreach ($points as $i => $p) {
            $x0 = $i === 0 ? $padL : ($x($i - 1) + $x($i)) / 2;
            $x1 = $i === $n - 1 ? $w - $padR : ($x($i) + $x($i + 1)) / 2;
            $tip = self::chartDate($p['date'] ?? '').'  '.Money::formatCad($p['equity'] ?? 0);
            $hits .= '<rect x="'.number_format($x0, 1, '.', '').'" y="'.$padT.'" width="'.number_format(max(1, $x1 - $x0), 1, '.', '').'" height="'.number_format($innerH, 1, '.', '').'" fill="#fff" fill-opacity="0"><title>'.e($tip).'</title></rect>';
        }

        return '<svg viewBox="0 0 '.$w.' '.$h.'" class="chart" preserveAspectRatio="none" role="img" aria-label="Equity curve">'
            .$grid
            .'<line x1="'.$padL.'" y1="'.number_format($zero, 1, '.', '').'" x2="'.($w - $padR).'" y2="'.number_format($zero, 1, '.', '').'" stroke="#343b47" stroke-dasharray="3 3"/>'
            .'<path d="'.trim($d).'" fill="none" stroke="'.$cls.'" stroke-width="2" pointer-events="none"/>'
            .'<text x="'.$padL.'" y="'.($h - 8).'" fill="#6b7380" font-size="10">'.e(self::chartDate($points[0]['date'] ?? '')).'</text>'
            .'<text x="'.($w - $padR).'" y="'.($h - 8).'" text-anchor="end" fill="#6b7380" font-size="10">'.e(self::chartDate($last['date'] ?? '')).'</text>'
            .$hits
            .'</svg>';
    }

    public static function monthly(array $rows): string
    {
        $w = 640;
        $h = 220;
        $padL = 52;
        $padR = 16;
        $padT = 16;
        $padB = 44;
        if ($rows === []) {
            return '<svg viewBox="0 0 640 220" class="chart" role="img" aria-label="Monthly P&amp;L"><text x="320" y="110" text-anchor="middle" fill="#6b7380" font-size="12">No monthly P&amp;L</text></svg>';
        }
        $ys = array_map(fn ($r) => (float) ($r['pnl'] ?? 0), $rows);
        $minY = min(0, ...$ys);
        $maxY = max(0, ...$ys);
        if ($minY === $maxY) {
            $minY -= 1;
            $maxY += 1;
        }
        $innerW = $w - $padL - $padR;
        $innerH = $h - $padT - $padB;
        $y = function (float $v) use ($padT, $innerH, $maxY, $minY): float {
            return $padT + (($maxY - $v) / ($maxY - $minY)) * $innerH;
        };
        $zero = $y(0);
        $n = count($rows);
        $gap = 4;
        $bw = $n ? max(2, ($innerW / $n) - $gap) : $innerW;
        $bars = '';
        foreach ($rows as $i => $r) {
            $pnl = (float) ($r['pnl'] ?? 0);
            $x = $padL + ($i / max(1, $n)) * $innerW + $gap / 2;
            $top = $y(max($pnl, 0));
            $bot = $y(min($pnl, 0));
            $fill = $pnl >= 0 ? '#3ecf8e' : '#ef6b73';
            $tip = self::monthTick($r['month'] ?? '').'  '.Money::formatCad($pnl);
            $bars .= '<rect x="'.number_format($x, 1, '.', '').'" y="'.number_format($top, 1, '.', '').'" width="'.number_format($bw, 1, '.', '').'" height="'.number_format(max(1, $bot - $top), 1, '.', '').'" fill="'.$fill.'"><title>'.e($tip).'</title></rect>';
        }
        $grid = '';
        for ($g = 0; $g < 4; $g++) {
            $gy = $padT + ($innerH * $g) / 3;
            $gv = $maxY - (($maxY - $minY) * $g) / 3;
            $grid .= '<line x1="'.$padL.'" y1="'.number_format($gy, 1, '.', '').'" x2="'.($w - $padR).'" y2="'.number_format($gy, 1, '.', '').'" stroke="#262c36"/>';
            $grid .= '<text x="'.($padL - 6).'" y="'.number_format($gy + 3, 1, '.', '').'" text-anchor="end" fill="#6b7380" font-size="10">'.e(self::axisCad($gv)).'</text>';
        }
        $first = $rows[0]['month'] ?? '';
        $last = $rows[array_key_last($rows)]['month'] ?? '';

        return '<svg viewBox="0 0 '.$w.' '.$h.'" class="chart" preserveAspectRatio="none" role="img" aria-label="Monthly P&amp;L">'
            .$grid
            .'<line x1="'.$padL.'" y1="'.number_format($zero, 1, '.', '').'" x2="'.($w - $padR).'" y2="'.number_format($zero, 1, '.', '').'" stroke="#343b47" stroke-dasharray="3 3"/>'
            .$bars
            .'<text x="'.$padL.'" y="'.($h - 10).'" fill="#6b7380" font-size="10">'.e(self::monthTick($first)).'</text>'
            .'<text x="'.($w - $padR).'" y="'.($h - 10).'" text-anchor="end" fill="#6b7380" font-size="10">'.e(self::monthTick($last)).'</text>'
            .'</svg>';
    }

    /**
     * Trade price chart (~300px). Uses synced OHLC/close bars when present;
     * otherwise an empty frame with an honest empty-state message.
     *
     * @param  list<array{date?:string,time?:int|string,open?:float|null,high?:float|null,low?:float|null,close?:float|null}>  $bars
     */
    public static function price(array $bars, string $emptyReason = 'No price history for this span.'): string
    {
        $frame = 'position:relative;height:300px;border-radius:6px;box-shadow:inset 0 0 0 1px rgba(148,163,184,.14);background:color-mix(in srgb, var(--bh-ink) 2%, transparent)';
        if ($bars === []) {
            return '<div class="bh-trade-chart" style="'.$frame.';display:flex;align-items:center;justify-content:center">'
                .'<span class="bh-dim" style="font-size:12px;text-align:center;padding:0 20px">'.e($emptyReason).'</span>'
                .'</div>';
        }

        $w = 640;
        $h = 300;
        $padL = 48;
        $padR = 12;
        $padT = 16;
        $padB = 28;
        $closes = [];
        foreach ($bars as $b) {
            if (isset($b['close']) && is_numeric($b['close'])) {
                $closes[] = (float) $b['close'];
            }
        }
        if ($closes === []) {
            return self::price([], $emptyReason);
        }
        $minY = min($closes);
        $maxY = max($closes);
        if ($minY === $maxY) {
            $minY -= 1;
            $maxY += 1;
        }
        $pad = ($maxY - $minY) * 0.06;
        $minY -= $pad;
        $maxY += $pad;
        $innerW = $w - $padL - $padR;
        $innerH = $h - $padT - $padB;
        $n = count($bars);
        $x = function (int $i) use ($padL, $innerW, $n): float {
            return $n === 1 ? $padL + $innerW / 2 : $padL + ($i / ($n - 1)) * $innerW;
        };
        $y = function (float $v) use ($padT, $innerH, $maxY, $minY): float {
            return $padT + (($maxY - $v) / ($maxY - $minY)) * $innerH;
        };
        $d = '';
        $firstClose = null;
        $lastClose = null;
        foreach ($bars as $i => $b) {
            if (! isset($b['close']) || ! is_numeric($b['close'])) {
                continue;
            }
            $c = (float) $b['close'];
            $firstClose ??= $c;
            $lastClose = $c;
            $d .= ($d === '' ? 'M' : 'L').number_format($x($i), 1, '.', '').' '.number_format($y($c), 1, '.', '').' ';
        }
        $cls = ($lastClose ?? 0) >= ($firstClose ?? 0) ? '#3ecf8e' : '#ef6b73';
        $grid = '';
        for ($g = 0; $g < 4; $g++) {
            $gy = $padT + ($innerH * $g) / 3;
            $gv = $maxY - (($maxY - $minY) * $g) / 3;
            $grid .= '<line x1="'.$padL.'" y1="'.number_format($gy, 1, '.', '').'" x2="'.($w - $padR).'" y2="'.number_format($gy, 1, '.', '').'" stroke="#262c36"/>';
            $grid .= '<text x="'.($padL - 6).'" y="'.number_format($gy + 3, 1, '.', '').'" text-anchor="end" fill="#6b7380" font-size="10">'.e(number_format($gv, 2)).'</text>';
        }
        $firstLabel = (string) ($bars[0]['date'] ?? '');
        $lastLabel = (string) ($bars[array_key_last($bars)]['date'] ?? '');

        return '<div class="bh-trade-chart" style="'.$frame.'">'
            .'<svg viewBox="0 0 '.$w.' '.$h.'" class="chart" preserveAspectRatio="none" role="img" aria-label="Price chart" style="width:100%;height:100%;display:block">'
            .$grid
            .'<path d="'.trim($d).'" fill="none" stroke="'.$cls.'" stroke-width="2" pointer-events="none"/>'
            .'<text x="'.$padL.'" y="'.($h - 8).'" fill="#6b7380" font-size="10">'.e(self::chartDate($firstLabel)).'</text>'
            .'<text x="'.($w - $padR).'" y="'.($h - 8).'" text-anchor="end" fill="#6b7380" font-size="10">'.e(self::chartDate($lastLabel)).'</text>'
            .'</svg></div>';
    }

    /**
     * Cashflow / Portfolio Allocation donut.
     * $items: list of [symbol|label, value, display?] with value > 0.
     * Centre matches desktop: label (Projected / Market value) over the total.
     */
    public static function donut(array $items, string $centreValue, string $centreLabel, string $empty = 'No income holdings in scope.', int $palette = 8): string
    {
        if ($items === []) {
            return '<div class="bh-dim" style="margin:auto;font-size:12px">'.e($empty).'</div>';
        }
        $total = array_sum(array_map(fn ($x) => (float) ($x['value'] ?? 0), $items));
        if ($total <= 0) {
            return '<div class="bh-dim" style="margin:auto;font-size:12px">'.e($empty).'</div>';
        }
        $R = 44;
        $W = 14;
        $C = 60;
        $SIZE = 200;
        $acc = 0.0;
        $arcs = '';
        $legend = '';
        $mod = max(1, $palette);
        foreach ($items as $i => $item) {
            $v = (float) ($item['value'] ?? 0);
            $share = $v / $total;
            $color = (string) ($item['color'] ?? ('var(--bh-pie-'.(($i % $mod) + 1).')'));
            $a0 = $acc * 2 * M_PI - M_PI / 2;
            $a1 = ($acc + $share) * 2 * M_PI - M_PI / 2;
            $acc += $share;
            if ($share >= 0.9999) {
                $arcs .= '<circle cx="'.$C.'" cy="'.$C.'" r="'.$R.'" fill="none" stroke-width="'.$W.'" style="stroke:'.$color.'"/>';
            } else {
                $large = $share > 0.5 ? 1 : 0;
                $x0 = $C + $R * cos($a0);
                $y0 = $C + $R * sin($a0);
                $x1 = $C + $R * cos($a1);
                $y1 = $C + $R * sin($a1);
                $d = 'M'.number_format($x0, 2, '.', '').' '.number_format($y0, 2, '.', '')
                    .' A'.$R.' '.$R.' 0 '.$large.' 1 '.number_format($x1, 2, '.', '').' '.number_format($y1, 2, '.', '');
                $arcs .= '<path d="'.$d.'" fill="none" stroke-width="'.$W.'" style="stroke:'.$color.'"/>';
            }
            $sym = e((string) ($item['symbol'] ?? $item['label'] ?? ''));
            $legend .= '<span style="width:9px;height:9px;border-radius:2px;background:'.$color.'"></span>'
                .'<span style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'.$sym.'</span>'
                .'<span style="text-align:right;white-space:nowrap">'.e((string) ($item['display'] ?? Money::formatCad($v, 0))).'</span>'
                .'<span class="bh-dim" style="text-align:right;white-space:nowrap;min-width:44px">'.e(number_format($share * 100, 1).'%').'</span>';
        }

        return '<div style="display:flex;gap:18px;align-items:center;flex:1;min-height:0">'
            .'<div style="position:relative;width:'.$SIZE.'px;height:'.$SIZE.'px;flex:none">'
            .'<svg viewBox="0 0 120 120" style="width:'.$SIZE.'px;height:'.$SIZE.'px;display:block">'.$arcs.'</svg>'
            .'<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;pointer-events:none">'
            .'<div class="bh-muted" style="font-size:11px">'.e($centreLabel).'</div>'
            .'<div style="font-size:17px;font-weight:500;margin-top:2px">'.e($centreValue).'</div>'
            .'</div></div>'
            .'<div class="bh-scroll" style="flex:1;min-width:0;max-height:100%;display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;column-gap:12px;row-gap:9px;align-content:center;align-items:center;font-size:12.5px">'
            .$legend
            .'</div></div>';
    }

    /**
     * Portfolio Allocation: market-value donut, top 10 + Other (desktop portfolioSlices).
     *
     * @param  list<array{symbol?:string,value:float,share?:float}>  $allocation
     */
    public static function portfolioAllocationCard(array $allocation, float $marketValue): string
    {
        $all = array_values(array_filter($allocation, fn ($x) => (float) ($x['value'] ?? 0) > 0));
        $top = array_slice($all, 0, 10);
        $rest = array_slice($all, 10);
        $items = [];
        foreach ($top as $i => $x) {
            $v = (float) $x['value'];
            $items[] = [
                'symbol' => (string) ($x['symbol'] ?? ''),
                'value' => $v,
                'display' => Money::formatCad($v, 0),
                'color' => 'var(--bh-pie-'.(($i % 11) + 1).')',
            ];
        }
        if ($rest !== []) {
            $v = array_sum(array_map(fn ($x) => (float) $x['value'], $rest));
            $items[] = [
                'symbol' => 'Other ('.count($rest).')',
                'value' => $v,
                'display' => Money::formatCad($v, 0),
                'color' => 'var(--bh-pie-'.((count($top) % 11) + 1).')',
            ];
        }
        $total = $marketValue > 0 ? $marketValue : array_sum(array_map(fn ($x) => (float) $x['value'], $items));
        $body = self::donut($items, Money::formatCad($total, 0), 'Market value', 'No open positions in scope.', 11);

        return '<div class="bh-card" style="padding:16px 18px 12px;display:flex;flex-direction:column;min-height:0">'
            .'<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:10px"><h5 class="bh-h5" style="margin:0">Allocation</h5></div>'
            .$body
            .'</div>';
    }

    public static function chartDate(string $iso): string
    {
        $parts = explode('-', $iso);
        if (count($parts) < 3) {
            return count($parts) === 2 ? self::monthTick($iso) : $iso;
        }
        $names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $mon = $names[((int) $parts[1]) - 1] ?? $parts[1];

        return ((int) $parts[2]).' '.$mon.' \''.substr($parts[0], 2);
    }

    public static function monthTick(string $ym): string
    {
        $parts = explode('-', $ym);
        $y = $parts[0] ?? '';
        $m = (int) ($parts[1] ?? 0);
        $names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $mon = $names[$m - 1] ?? ($parts[1] ?? '');

        return $mon.' \''.substr($y, 2);
    }

    public static function axisCad(float $n): string
    {
        $abs = number_format(abs($n), 0, '.', ',');
        if ($n < 0) {
            return '−$'.$abs;
        }
        if ($n > 0) {
            return '+$'.$abs;
        }

        return '$'.$abs;
    }
    /** Desktop nocturne pie palette — hex so SVG stroke matches legend swatches. */
    private static function pieColor(string $color): string
    {
        static $map = [
            'var(--bh-pie-1)' => '#9184d9',
            'var(--bh-pie-2)' => '#6fd39b',
            'var(--bh-pie-3)' => '#5fb0e6',
            'var(--bh-pie-4)' => '#e8b36a',
            'var(--bh-pie-5)' => '#e0778a',
            'var(--bh-pie-6)' => '#7fd3c9',
            'var(--bh-pie-7)' => '#c48fdc',
            'var(--bh-pie-8)' => '#d7c46a',
            'var(--bh-pie-9)' => '#e59a6a',
            'var(--bh-pie-10)' => '#8fb87a',
            'var(--bh-pie-11)' => '#a0a8b8',
        ];

        return $map[$color] ?? $color;
    }

    /**
     * Portfolio Sectors | donut | Regions donut | legend — desktop exposureCardsHtml.
     *
     * @param  list<array{name:string,value:float,share:float}>  $sectors
     * @param  list<array{name:string,value:float,share:float}>  $regions
     */
    public static function exposureCard(array $sectors, array $regions): string
    {
        $secItems = Exposure::displayItems($sectors, 12);
        $regItems = Exposure::displayItems($regions, 10);
        $head = '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:10px"><h5 class="bh-h5" style="margin:0">Sectors</h5><h5 class="bh-h5" style="margin:0">Regions</h5></div>';
        if ($secItems === [] && $regItems === []) {
            return '<div class="bh-card" style="padding:16px 18px 12px">'.$head.'<div class="bh-muted" style="font-size:12px">No open positions in scope.</div></div>';
        }
        $secCount = (string) Exposure::classifiedCount($sectors);
        $regCount = (string) Exposure::classifiedCount($regions);
        $a = self::exposureDonutPieces('xsec', 'Sectors', $secItems, $secCount, 'l');
        $b = self::exposureDonutPieces('xreg', 'Regions', $regItems, $regCount, 'r');

        return '<div class="bh-card" style="padding:16px 18px 12px">'.$head
            .'<div style="display:grid;grid-template-columns:minmax(max-content,1fr) minmax(240px,340px) minmax(240px,340px) minmax(max-content,1fr);column-gap:40px;align-items:center">'
            .$a['legend'].$a['ring'].$b['ring'].$b['legend']
            .'</div></div>';
    }

    /**
     * @param  list<array{label:string,value:float,share:float,color:string}>  $items
     * @return array{ring:string,legend:string}
     */
    private static function exposureDonutPieces(string $key, string $centreLabel, array $items, string $centreText, string $side): array
    {
        // Prefer model shares (desktop); fall back to value mass so legend ≡ arcs.
        $shareSum = array_sum(array_map(fn ($x) => (float) ($x['share'] ?? 0), $items));
        $mass = array_sum(array_map(fn ($x) => (float) ($x['value'] ?? 0), $items));
        $useShare = $shareSum > 0.98 && $shareSum < 1.02;
        $norm = [];
        foreach ($items as $x) {
            $v = (float) ($x['value'] ?? 0);
            $norm[] = [
                'label' => (string) ($x['label'] ?? ''),
                'value' => $v,
                'share' => $useShare
                    ? (float) ($x['share'] ?? 0)
                    : ($mass > 0 ? ($v / $mass) : 0.0),
                'color' => self::pieColor((string) ($x['color'] ?? 'var(--bh-pie-1)')),
            ];
        }
        $R = 44;
        $W = 14;
        $C = 60;
        $acc = 0.0;
        $arcs = '';
        foreach ($norm as $x) {
            $share = (float) $x['share'];
            $color = $x['color'];
            $a0 = $acc * 2 * M_PI - M_PI / 2;
            $a1 = ($acc + $share) * 2 * M_PI - M_PI / 2;
            $acc += $share;
            if ($share <= 0) {
                continue;
            }
            if ($share >= 0.9999) {
                $arcs .= '<circle cx="'.$C.'" cy="'.$C.'" r="'.$R.'" fill="none" stroke-width="'.$W.'" style="stroke:'.$color.'"/>';
            } else {
                $large = $share > 0.5 ? 1 : 0;
                $x0 = $C + $R * cos($a0);
                $y0 = $C + $R * sin($a0);
                $x1 = $C + $R * cos($a1);
                $y1 = $C + $R * sin($a1);
                $d = 'M'.number_format($x0, 2, '.', '').' '.number_format($y0, 2, '.', '')
                    .' A'.$R.' '.$R.' 0 '.$large.' 1 '.number_format($x1, 2, '.', '').' '.number_format($y1, 2, '.', '');
                $arcs .= '<path d="'.$d.'" fill="none" stroke-width="'.$W.'" style="stroke:'.$color.'"/>';
            }
        }
        $ring = '<div style="position:relative;width:100%;aspect-ratio:1/1;min-width:0">'
            .'<svg viewBox="0 0 120 120" style="width:100%;height:100%;display:block">'.$arcs.'</svg>'
            .'<div style="position:absolute;inset:21%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;pointer-events:none">'
            .'<div class="bh-muted" style="font-size:11px">'.e($centreLabel).'</div>'
            .'<div style="font-size:17px;font-weight:500;margin-top:2px">'.e($centreText).'</div>'
            .'</div></div>';

        $legend = '<div style="min-width:0;justify-self:'.($side === 'r' ? 'end' : 'start')
            .';display:grid;grid-template-columns:auto minmax(0,1fr) auto;column-gap:12px;row-gap:9px;align-content:center;align-items:center;font-size:12.5px;overflow:visible">';
        foreach ($norm as $x) {
            $swatch = '<span style="width:9px;height:9px;border-radius:2px;background:'.e($x['color']).'"></span>';
            $name = '<span style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'.($side === 'r' ? ';text-align:right' : '').'">'.e($x['label']).'</span>';
            $share = '<span class="bh-dim" style="text-align:'.($side === 'r' ? 'left' : 'right').';white-space:nowrap">'.e(number_format(((float) $x['share']) * 100, 1).'%').'</span>';
            $legend .= $side === 'r' ? ($share.$name.$swatch) : ($swatch.$name.$share);
        }
        $legend .= '</div>';

        return ['ring' => $ring, 'legend' => $legend];
    }

}
