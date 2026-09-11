<?php

namespace App\Journal;

use App\Models\Meta;

/**
 * Port of Bagholder model.py build_cashflow + cashflow_view.
 * TMX declared distributions / quotes come from Meta when synced; otherwise
 * payment-history frequency — never invent TMX rows.
 */
final class Cashflow
{
    private const SCHEDULES = [1, 2, 4, 6, 12];

    private const EPS = 1e-9;

    public static function buildRows(array $activities, array $securitiesById = []): array
    {
        $rows = [];
        foreach ($activities as $a) {
            $cat = (string) ($a['category'] ?? '');
            $raw = strtoupper(trim((string) ($a['rawType'] ?? '')));
            $at = strtoupper(trim((string) ($a['activityType'] ?? '')));
            $cash = (float) ($a['netCashAmount'] ?? 0);
            $kind = '';
            if ($cat === 'dividend') {
                $kind = 'Dividend';
            } elseif ($cat === 'interest') {
                $kind = 'Interest';
            } elseif ($raw === 'WITHHOLDINGTAX' || $at === 'WITHHOLDINGTAX') {
                $kind = 'Withholding tax';
            } elseif ($raw === 'INTERESTCHARGE' || $at === 'INTERESTCHARGE') {
                $kind = 'Interest charge';
            } else {
                continue;
            }
            if (abs($cash) < self::EPS) {
                continue;
            }
            $when = Dates::activityWhen($a);
            $day = substr((string) ($a['transactionDate'] ?? $when), 0, 10);
            $clock = '';
            if (str_contains($when, ' ')) {
                $parts = explode(' ', $when, 2);
                $clock = $parts[1] ?? '';
            }
            $symbol = trim((string) ($a['symbol'] ?? ''));
            if ($symbol === '' && in_array($kind, ['Interest', 'Interest charge'], true)) {
                $symbol = 'Cash';
            }
            $secId = (string) ($a['securityId'] ?? '');
            $name = (string) ($a['name'] ?? '');
            if ($secId !== '' && isset($securitiesById[$secId]['name']) && $securitiesById[$secId]['name'] !== '') {
                $name = (string) $securitiesById[$secId]['name'];
            }
            if ($name === $symbol) {
                $name = '';
            }
            $qty = (float) ($a['quantity'] ?? 0);
            $per = (float) ($a['unitPrice'] ?? 0);
            $rows[] = [
                'id' => (string) ($a['id'] ?? ''),
                'date' => $day,
                'time' => $clock,
                'symbol' => $symbol !== '' ? $symbol : '—',
                'name' => $name,
                'kind' => $kind,
                'account' => (string) ($a['accountType'] ?? $a['accountId'] ?? ''),
                'accountId' => (string) ($a['accountId'] ?? ''),
                'qty' => abs($qty) > self::EPS ? $qty : null,
                'per' => abs($per) > self::EPS ? $per : null,
                'amount' => $cash,
                'currency' => (string) ($a['currency'] ?? 'CAD') ?: 'CAD',
                'amountCad' => Fx::toCad($cash, (string) ($a['currency'] ?? 'CAD'), $a['transactionDate'] ?? $day),
            ];
        }
        usort($rows, function ($a, $b) {
            $c = strcmp($b['date'], $a['date']);
            if ($c !== 0) {
                return $c;
            }

            return strcmp($b['id'], $a['id']);
        });

        return $rows;
    }

    /**
     * Aggregate open FIFO lots into position-like rows for cashflow holdings
     * and the Positions 360 panel (subset of desktop build_positions).
     */
    /**
     * Desktop model.last_fill_prices — symbol => {price, date} from newest trade/option fill.
     *
     * @param  list<array<string, mixed>>  $activities
     * @return array<string, array{price: float, date: string}>
     */
    public static function lastFillPrices(array $activities): array
    {
        $out = [];
        $sorted = $activities;
        usort($sorted, function ($a, $b) {
            $da = (string) ($a['transactionDate'] ?? $a['occurredAt'] ?? '');
            $db = (string) ($b['transactionDate'] ?? $b['occurredAt'] ?? '');
            $c = strcmp($da, $db);
            if ($c !== 0) {
                return $c;
            }

            return strcmp((string) ($a['occurredAt'] ?? ''), (string) ($b['occurredAt'] ?? ''));
        });
        foreach ($sorted as $a) {
            $cat = (string) ($a['category'] ?? '');
            if ($cat !== 'trade' && $cat !== 'option_event') {
                continue;
            }
            $px = (float) ($a['unitPrice'] ?? 0);
            $sym = (string) ($a['symbol'] ?? '');
            if ($px > 0 && $sym !== '') {
                $out[$sym] = [
                    'price' => $px,
                    'date' => (string) ($a['transactionDate'] ?? ''),
                ];
            }
        }

        return $out;
    }

    public static function positionsFromOpen(array $openLots, array $notes = [], ?string $today = null, array $quotes = [], array $lastFills = []): array
    {
        $today = $today ?: gmdate('Y-m-d');
        $quotes = $quotes ?: [];
        $lastFills = $lastFills ?: [];
        $groups = [];
        $order = [];
        foreach ($openLots as $lot) {
            $k = implode("\0", [
                (string) ($lot['symbol'] ?? ''),
                (string) ($lot['accountType'] ?? ''),
                (string) ($lot['currency'] ?? ''),
                (string) ($lot['direction'] ?? 'LONG'),
            ]);
            if (! isset($groups[$k])) {
                $groups[$k] = [];
                $order[] = $k;
            }
            $groups[$k][] = $lot;
        }
        $rows = [];
        foreach ($order as $k) {
            $lots = $groups[$k];
            usort($lots, fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
            $symbol = (string) ($lots[0]['symbol'] ?? '');
            $account = (string) ($lots[0]['accountType'] ?? '');
            $currency = (string) ($lots[0]['currency'] ?? 'CAD');
            $direction = (string) ($lots[0]['direction'] ?? 'LONG');
            $mult = Symbols::optionMultiplier($symbol);
            $qty = 0.0;
            $costNative = 0.0;
            $held = 0.0;
            $lotRows = [];
            foreach ($lots as $l) {
                $q = (float) ($l['quantity'] ?? $l['qty'] ?? 0);
                $px = (float) ($l['price'] ?? 0);
                $qty += $q;
                $costNative += $q * $px * $mult;
                $opened = substr((string) ($l['date'] ?? ''), 0, 10);
                $heldDays = $opened !== '' ? Dates::daysBetween($opened, $today) : 0;
                $held += $q * $heldDays;
                $lotRows[] = [
                    'opened' => $opened,
                    'qty' => $q,
                    'price' => $px,
                    'basis' => $q * $px * $mult,
                    'held' => $heldDays,
                    'activityId' => (string) ($l['activityId'] ?? ''),
                    'currency' => (string) ($l['currency'] ?? $currency),
                ];
            }
            if ($qty <= 1e-9) {
                continue;
            }
            $avg = $costNative / ($qty * $mult);
            $costCad = Fx::toCad($costNative, $currency, $today);
            $legacyPid = 'pos:'.implode('|', [$account, $symbol, $currency]);
            $pid = (string) ($lots[0]['rt'] ?? $legacyPid);
            $entry = $notes[$pid] ?? $notes[$legacyPid] ?? [];

            // Desktop parity (model.build_positions): quote > last fill > avg cost.
            $quote = $quotes[$symbol] ?? null;
            $fill = $lastFills[$symbol] ?? null;
            $last = null;
            $lastAt = '';
            $priceSource = null;
            if (is_array($fill) && isset($fill['price']) && (float) $fill['price'] > 0) {
                $last = (float) $fill['price'];
                $lastAt = (string) ($fill['date'] ?? '');
                $priceSource = 'fill';
            } else {
                $last = $avg;
                $lastAt = '';
                $priceSource = 'avg';
            }
            if (is_array($quote) && isset($quote['price']) && (float) $quote['price'] > 0) {
                $last = (float) $quote['price'];
                $lastAt = (string) ($quote['fetchedAt'] ?? '');
                $priceSource = 'quote';
            }

            // Desktop: day $ = quote priceChange × qty × mult (SHORT sign flip); day % from quote percentChange.
            $priceChange = (is_array($quote) && isset($quote['priceChange']) && is_numeric($quote['priceChange']))
                ? (float) $quote['priceChange'] : null;
            $percentChange = (is_array($quote) && isset($quote['percentChange']) && is_numeric($quote['percentChange']))
                ? (float) $quote['percentChange'] : null;
            $dayChange = null;
            $dayPct = null;
            if ($priceChange !== null) {
                $dayNative = $priceChange * $qty * $mult;
                if ($direction === 'SHORT') {
                    $dayNative = -$dayNative;
                }
                $dayChange = Fx::toCad($dayNative, $currency, $today);
            }
            if ($percentChange !== null) {
                // Quotes store percent units (e.g. 1.25 = 1.25%); Book uses ratios for signedPct.
                $dayPct = $percentChange / 100.0;
                if ($direction === 'SHORT') {
                    $dayPct = -$dayPct;
                }
            }

            $mv = null;
            $unreal = null;
            $unrealPct = null;
            if ($last !== null) {
                $mvNative = $qty * $last * $mult;
                $mv = Fx::toCad($mvNative, $currency, $today);
                $unreal = $direction === 'SHORT' ? ($costCad - $mv) : ($mv - $costCad);
                $unrealPct = abs($costCad) > self::EPS ? ($unreal / abs($costCad)) : null;
            }

            $securityId = '';
            foreach ($lots as $l) {
                $sid = trim((string) ($l['securityId'] ?? ''));
                if ($sid !== '') {
                    $securityId = $sid;
                    break;
                }
            }
            $kind = Filters::tradeKind([
                'symbol' => $symbol,
                'currency' => $currency,
                'kind' => $lots[0]['kind'] ?? '',
            ]);
            $rows[] = [
                'id' => $pid,
                'symbol' => $symbol,
                'displaySymbol' => Symbols::listingTicker($symbol),
                'name' => (string) ($lots[0]['name'] ?? $symbol),
                'account' => $account,
                'accountId' => (string) ($lots[0]['accountId'] ?? ''),
                'currency' => $currency,
                'short' => $direction === 'SHORT',
                'direction' => $direction,
                'qty' => $qty,
                'mult' => $mult,
                'avg' => $avg,
                'cost' => $costCad,
                'costNative' => $costNative,
                'last' => $last,
                'lastAt' => $lastAt,
                'priceSource' => $priceSource,
                'priceChange' => $priceChange,
                'percentChange' => $percentChange,
                'dayChange' => $dayChange,
                'dayPct' => $dayPct,
                'mv' => $mv,
                'unreal' => $unreal,
                'unrealPct' => $unrealPct,
                'opened' => (string) ($lots[0]['date'] ?? ''),
                'held' => $qty ? (int) round($held / $qty) : 0,
                'lots' => $lotRows,
                'thesis' => (string) ($entry['thesis'] ?? ''),
                'grade' => (string) ($entry['grade'] ?? ''),
                'tag' => is_array($entry['tags'] ?? null)
                    ? implode(', ', $entry['tags'])
                    : (string) ($entry['tag'] ?? ''),
                'listingLine' => (string) ($lots[0]['listingLine'] ?? Symbols::listingTicker($symbol)),
                'securityId' => $securityId,
                'kind' => $kind,
                'underlying' => Symbols::underlyingSymbol($symbol),
            ];
        }

        // Allocation: market when quoted, else book — vs the same basis total.
        $basisTotal = 0.0;
        foreach ($rows as $r) {
            $basisTotal += abs((float) ($r['mv'] ?? $r['cost'] ?? 0));
        }
        foreach ($rows as &$r) {
            $slice = abs((float) ($r['mv'] ?? $r['cost'] ?? 0));
            $r['alloc'] = $basisTotal > self::EPS ? ($slice / $basisTotal) : 0.0;
        }
        unset($r);
        usort($rows, fn ($a, $b) => ($b['alloc'] <=> $a['alloc']));

        return $rows;
    }

    public static function view(array $cashflowRows, array $filters, array $positionsAll, ?string $today = null, array $distributions = [], array $quotes = []): array
    {
        $today = $today ?: gmdate('Y-m-d');
        $account = (string) ($filters['account'] ?? '');
        $symbolFilter = (string) ($filters['symbol'] ?? '');
        $search = strtoupper(trim((string) ($filters['q'] ?? '')));
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        $inScope = function (array $r) use ($account, $symbolFilter, $search, $from, $to): bool {
            if ($account !== '' && ($r['account'] ?? '') !== $account && ($r['accountId'] ?? '') !== $account) {
                return false;
            }
            if ($search !== '' && ! str_contains(strtoupper((string) ($r['symbol'] ?? '')), $search)) {
                return false;
            }
            if ($symbolFilter !== '' && ($r['symbol'] ?? '') !== $symbolFilter) {
                return false;
            }
            $day = substr((string) ($r['date'] ?? ''), 0, 10);
            if ($from !== '' && $day < $from) {
                return false;
            }
            if ($to !== '' && $day > $to) {
                return false;
            }

            return true;
        };

        $everything = array_values(array_filter($cashflowRows, $inScope));
        $recs = array_values(array_filter($everything, fn ($r) => ($r['kind'] ?? '') === 'Dividend'));

        $skipped = [];
        foreach (['grade', 'tag', 'kind', 'exchange', 'side', 'result'] as $k) {
            if (! empty($filters[$k])) {
                $skipped[] = $k;
            }
        }
        if (Filters::priceFilterOn($filters)) {
            $skipped[] = 'price';
        }

        $keys = [];
        $bucket = [];
        if ($recs !== []) {
            $monthsSeen = [];
            foreach ($recs as $r) {
                $monthsSeen[substr($r['date'], 0, 7)] = true;
            }
            $monthsSeen = array_keys($monthsSeen);
            sort($monthsSeen);
            $first = $monthsSeen[0];
            $last = $monthsSeen[count($monthsSeen) - 1];
            $endDay = $today;
            if ($from !== '' && $to !== '') {
                $endDay = min($to, $today);
            }
            $last = max($last, substr($endDay, 0, 7));
            [$y, $m] = array_map('intval', explode('-', $first));
            while (true) {
                $k = sprintf('%04d-%02d', $y, $m);
                if ($k > $last) {
                    break;
                }
                $keys[] = $k;
                $bucket[$k] = ['sum' => 0.0, 'n' => 0];
                $m++;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
            }
            foreach ($recs as $r) {
                $k = substr($r['date'], 0, 7);
                if (isset($bucket[$k])) {
                    $bucket[$k]['sum'] += (float) $r['amountCad'];
                    $bucket[$k]['n']++;
                }
            }
        }
        $months = [];
        foreach ($keys as $k) {
            $months[] = [
                'key' => $k,
                'label' => self::monthLabel($k),
                'value' => $bucket[$k]['sum'],
                'count' => $bucket[$k]['n'],
            ];
        }

        $payers = [];
        foreach ($cashflowRows as $r) {
            if (($r['kind'] ?? '') === 'Dividend') {
                $payers[(string) $r['symbol']] = true;
            }
        }
        $held = array_values(array_filter($positionsAll, function ($p) use ($payers, $account, $search, $symbolFilter) {
            if (empty($payers[$p['symbol'] ?? ''])) {
                return false;
            }
            if (! empty($p['short'])) {
                return false;
            }
            if ($account !== '' && ($p['account'] ?? '') !== $account && ($p['accountId'] ?? '') !== $account) {
                return false;
            }
            if ($search !== '' && ! str_contains(strtoupper((string) ($p['symbol'] ?? '')), $search)) {
                return false;
            }
            if ($symbolFilter !== '' && ($p['symbol'] ?? '') !== $symbolFilter) {
                return false;
            }

            return true;
        }));

        $forYoc = array_values(array_filter($cashflowRows, function ($r) use ($account, $search) {
            if (($r['kind'] ?? '') !== 'Dividend') {
                return false;
            }
            if ($account !== '' && ($r['account'] ?? '') !== $account && ($r['accountId'] ?? '') !== $account) {
                return false;
            }
            if ($search !== '' && ! str_contains(strtoupper((string) ($r['symbol'] ?? '')), $search)) {
                return false;
            }

            return true;
        }));

        $lastRec = $recs[0]['date'] ?? $today;
        $cutDt = strtotime(substr($lastRec, 0, 10).'T12:00:00');
        $cm = (int) gmdate('n', $cutDt) - 11;
        $cy = (int) gmdate('Y', $cutDt);
        while ($cm <= 0) {
            $cm += 12;
            $cy--;
        }
        $cut = sprintf('%04d-%02d', $cy, $cm);
        $thisYear = substr($today, 0, 4);

        $sumFor = function (string $sym, callable $pred) use ($forYoc): float {
            $s = 0.0;
            foreach ($forYoc as $r) {
                if (($r['symbol'] ?? '') === $sym && $pred($r)) {
                    $s += (float) $r['amountCad'];
                }
            }

            return $s;
        };

        $public = $distributions ?: Meta::json('distributions', []);
        $quotes = $quotes ?: Meta::json('quotes', []);

        $rateFor = function (string $sym) use ($public, $today, $forYoc): ?array {
            $declared = array_values(array_filter($public[$sym] ?? [], fn ($d) => ($d['exDate'] ?? '') <= $today));
            if ($declared !== []) {
                usort($declared, fn ($a, $b) => strcmp($b['exDate'] ?? '', $a['exDate'] ?? ''));
                $per = (float) ($declared[0]['amount'] ?? 0);
                $freq = self::paymentsPerYear(array_map(fn ($d) => $d['exDate'] ?? '', $public[$sym] ?? []));
                if ($per && $freq) {
                    return [
                        'per' => $per,
                        'freq' => $freq,
                        'annual' => $per * $freq,
                        'verified' => true,
                        'source' => 'declared',
                    ];
                }
            }
            $rs = array_values(array_filter($forYoc, fn ($r) => ($r['symbol'] ?? '') === $sym && ! empty($r['per'])));
            usort($rs, fn ($a, $b) => strcmp($b['date'], $a['date']));
            if ($rs === []) {
                return null;
            }
            $per = (float) $rs[0]['per'];
            if (! $per) {
                return null;
            }
            $freq = self::paymentsPerYear(array_map(
                fn ($r) => $r['date'],
                array_filter($forYoc, fn ($r) => ($r['symbol'] ?? '') === $sym)
            ));
            $verified = $freq !== null;
            if (! $verified) {
                $freq = 12;
            }

            return [
                'per' => $per,
                'freq' => $freq,
                'annual' => $per * $freq,
                'verified' => $verified,
                'source' => 'payments',
            ];
        };

        $distributionDates = function (string $sym) use ($public, $quotes, $today, $forYoc): array {
            $recs_ = $public[$sym] ?? [];
            usort($recs_, function ($a, $b) {
                $pa = substr((string) ($a['payDate'] ?? ''), 0, 10) ?: ($a['exDate'] ?? '');
                $pb = substr((string) ($b['payDate'] ?? ''), 0, 10) ?: ($b['exDate'] ?? '');
                $c = strcmp($pa, $pb);
                if ($c !== 0) {
                    return $c;
                }

                return strcmp($a['exDate'] ?? '', $b['exDate'] ?? '');
            });
            $unpaid = array_values(array_filter($recs_, function ($d) use ($today) {
                $pay = substr((string) ($d['payDate'] ?? ''), 0, 10) ?: ($d['exDate'] ?? '');

                return $pay >= $today;
            }));
            $pick = $unpaid[0] ?? ($recs_ ? $recs_[count($recs_) - 1] : null);
            if ($pick) {
                $ex = (string) ($pick['exDate'] ?? '');
                $pay = substr((string) ($pick['payDate'] ?? ''), 0, 10);
            } else {
                $q = $quotes[$sym] ?? [];
                $ex = substr((string) ($q['exDividendDate'] ?? ''), 0, 10);
                $paid = [];
                foreach ($forYoc as $r) {
                    if (($r['symbol'] ?? '') === $sym) {
                        $paid[] = $r['date'];
                    }
                }
                sort($paid);
                $pay = $paid ? $paid[count($paid) - 1] : '';
            }

            return [$ex, $pay, (bool) ($ex && $ex < $today), (bool) ($pay && $pay < $today)];
        };

        $holdings = [];
        foreach ($held as $p) {
            $r = $rateFor($p['symbol']);
            $avg = (float) ($p['avg'] ?? 0);
            $basis = (float) ($p['cost'] ?? 0);
            $lastPx = (float) ($p['last'] ?? $avg);
            $q = $quotes[$p['symbol']] ?? [];
            if (! empty($q['price']) && (float) $q['price'] > 0) {
                $lastPx = (float) $q['price'];
            }
            [$ex, $pay, $exPast, $payPast] = $distributionDates($p['symbol']);
            $holdings[] = [
                'id' => $p['id'],
                'symbol' => $p['symbol'],
                'account' => $p['account'],
                'qty' => $p['qty'],
                'per' => $r['per'] ?? null,
                'freq' => $r['freq'] ?? null,
                'freqVerified' => (bool) ($r['verified'] ?? false),
                'rateSource' => $r['source'] ?? '',
                'cost' => $basis,
                'avg' => $avg,
                'last' => $lastPx,
                'mv' => $p['mv'] ?? null,
                'ytd' => $sumFor($p['symbol'], fn ($x) => substr($x['date'], 0, 4) === $thisYear),
                'ttm' => $sumFor($p['symbol'], fn ($x) => substr($x['date'], 0, 7) >= $cut),
                'all' => $sumFor($p['symbol'], fn ($x) => true),
                'nextExDate' => $ex,
                'nextPayDate' => $pay,
                'exPast' => $exPast,
                'payPast' => $payPast,
                'yob' => $r ? $r['per'] * $p['qty'] : null,
                'annual' => ($r && $r['annual'] !== null) ? $r['annual'] * $p['qty'] : null,
                'yoc' => ($r && $r['annual'] !== null && $avg) ? $r['annual'] / $avg : null,
                'currentYield' => ($r && $r['annual'] !== null && $lastPx) ? $r['annual'] / $lastPx : null,
            ];
        }

        $verified = array_values(array_filter($holdings, fn ($h) => $h['annual'] !== null));
        $basisAll = array_sum(array_map(fn ($h) => $h['cost'], $verified));
        $earnedAll = array_sum(array_map(fn ($h) => $h['ttm'], $verified));
        $annualAll = array_sum(array_map(fn ($h) => $h['annual'], $verified));
        $total = array_sum(array_map(fn ($r) => (float) $r['amountCad'], $recs));
        $thisYr = (int) $thisYear;
        $tiles = [];
        foreach ([$thisYr - 2, $thisYr - 1, $thisYr] as $y) {
            $rs = array_values(array_filter($recs, fn ($r) => substr($r['date'], 0, 4) === (string) $y));
            $sm = array_sum(array_map(fn ($r) => (float) $r['amountCad'], $rs));
            $paid = count(array_filter($keys, fn ($k) => str_starts_with($k, (string) $y) && ($bucket[$k]['n'] ?? 0) > 0)) ?: 1;
            $tiles[] = [
                'label' => $y === $thisYr ? $y.' YTD' : (string) $y,
                'total' => $sm,
                'perMonth' => $sm / $paid,
                'count' => count($rs),
            ];
        }
        $monthsInScope = count(array_filter($keys, fn ($k) => ($bucket[$k]['n'] ?? 0) > 0)) ?: 1;
        $tiles[] = [
            'label' => 'All time',
            'total' => $total,
            'perMonth' => $total / $monthsInScope,
            'count' => count($recs),
        ];
        $tiles[] = [
            'label' => 'Yield on cost',
            'yield' => $basisAll ? $annualAll / $basisAll : null,
            'earned' => $earnedAll,
            'book' => $basisAll,
        ];
        $other = array_values(array_filter($everything, fn ($r) => ($r['kind'] ?? '') !== 'Dividend'));

        return [
            'tiles' => $tiles,
            'months' => $months,
            'holdings' => $holdings,
            'rows' => $recs,
            'other' => $other,
            'total' => $total,
            'count' => count($recs),
            'skippedFilters' => $skipped,
            'interest' => array_sum(array_map(fn ($r) => (float) $r['amountCad'], array_filter($other, fn ($r) => $r['kind'] === 'Interest'))),
            'withholding' => array_sum(array_map(fn ($r) => (float) $r['amountCad'], array_filter($other, fn ($r) => $r['kind'] === 'Withholding tax'))),
            'tmx' => $public !== [],
        ];
    }

    public static function paymentsPerYear(array $dates): ?int
    {
        $days = [];
        foreach ($dates as $d) {
            $day = substr((string) $d, 0, 10);
            if ($day !== '') {
                $days[$day] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        if (count($days) < 2) {
            return null;
        }
        $gaps = [];
        for ($i = 1; $i < count($days); $i++) {
            $g = Dates::daysBetween($days[$i - 1], $days[$i]);
            if ($g > 0) {
                $gaps[] = $g;
            }
        }
        $gaps = array_slice($gaps, -3);
        if ($gaps === []) {
            return null;
        }
        sort($gaps);
        $median = $gaps[(int) floor(count($gaps) / 2)];
        $perYear = 365.25 / $median;
        $best = self::SCHEDULES[0];
        $bestDist = INF;
        foreach (self::SCHEDULES as $s) {
            $dist = abs($s - $perYear);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $s;
            }
        }

        return $best;
    }

    public static function monthLabel(string $key): string
    {
        $parts = explode('-', $key);
        if (count($parts) < 2) {
            return $key;
        }
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $m = (int) $parts[1];
        $label = $months[max(1, min(12, $m)) - 1];

        return $label.' '.$parts[0];
    }
}
