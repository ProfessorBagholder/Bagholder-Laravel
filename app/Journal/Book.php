<?php

namespace App\Journal;

final class Book
{
    public static function build(array $snapshot, array $filters, array $sort = [], array $savedGroups = []): array
    {
        $activities = $snapshot['activities'] ?? [];
        $securities = $snapshot['securities'] ?? [];
        Listings::boot($securities);
        Fx::boot($snapshot['fxByDate'] ?? []);
        Spy::boot($snapshot['spyByDate'] ?? []);
        Returns::boot($snapshot['navHistory'] ?? [], $snapshot['navByAccount'] ?? [], $filters);

        $fifo = Fifo::match($activities);
        $closedFx = Fx::apply($fifo['closed']);
        $visible = Filters::filteredTrades($closedFx, $filters);
        $grouped = Grouping::applySavedTradeGroups($visible, $savedGroups ?: ($snapshot['tradeGroups'] ?? []));
        $closedForMetrics = Grouping::groupClosedByClose($visible);

        $incomeActs = Filters::filteredActivities($activities, $filters);
        $incomeCad = 0.0;
        $feesCad = 0.0;
        foreach ($incomeActs as $a) {
            $cat = $a['category'] ?? '';
            $amt = Fx::toCad((float) ($a['netCashAmount'] ?? 0), (string) ($a['currency'] ?? 'CAD'), $a['transactionDate'] ?? null);
            if ($cat === 'dividend' || $cat === 'interest') {
                $incomeCad += $amt;
            }
            if ($cat === 'fee') {
                $feesCad += $amt;
            }
        }

        $open = $fifo['open'];
        if (! empty($filters['exchange'])) {
            $open = array_values(array_filter($open, function ($l) use ($filters, $activities) {
                $row = $l;
                $row['securityId'] = $l['securityId'] ?? null;
                $row['buyActivityId'] = $l['activityId'] ?? '';

                return Listings::listingExchange($row, $activities) === $filters['exchange'];
            }));
        }
        if (! empty($filters['symbol'])) {
            $open = array_values(array_filter($open, fn ($l) => Symbols::underlyingSymbol($l['symbol'] ?? '') === $filters['symbol']));
        }

        $m = Metrics::compute($closedForMetrics, count($open), $incomeCad, $feesCad);
        $curve = Filters::listingFiltersOn($filters)
            ? Metrics::realizedPnlCurve($closedForMetrics)
            : Returns::equityCurve($filters['from'] ?? '', $filters['to'] ?? '');
        $monthly = Metrics::monthlyPnl($closedForMetrics);
        $bySymbol = Metrics::breakdown($closedForMetrics, fn ($t) => Symbols::underlyingSymbol($t['symbol'] ?? ''));

        $q = strtoupper(trim((string) ($filters['q'] ?? '')));
        $searched = $q === '' ? $grouped : array_values(array_filter($grouped, fn ($t) => Symbols::blotterTickerMatch($t, $q)));

        $closedSort = $sort['closed'] ?? ['key' => 'exitDate', 'dir' => 'desc'];
        $notes = $snapshot['notes'] ?? [];
        $closedDecorated = array_map(function ($t) use ($activities, $notes) {
            $id = (string) ($t['id'] ?? '');
            $n = $notes[$id] ?? [];
            if ($n === [] && str_starts_with($id, 'rt:')) {
                // already keyed by rt
            }
            if ($n === []) {
                foreach (($t['slices'] ?? []) as $s) {
                    $rt = (string) ($s['rt'] ?? '');
                    if ($rt !== '' && isset($notes[$rt])) {
                        $n = $notes[$rt];
                        break;
                    }
                    $buy = (string) ($s['buyActivityId'] ?? '');
                    if ($buy !== '' && isset($notes['rt:'.$buy])) {
                        $n = $notes['rt:'.$buy];
                        break;
                    }
                }
            }
            if ($n === []) {
                foreach ($notes as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    if ((string) ($entry['tradeId'] ?? '') === $id) {
                        $n = $entry;
                        break;
                    }
                }
            }
            $t['displaySide'] = Sides::displaySide($t);
            $t['displaySymbol'] = Symbols::listingTicker($t['symbol'] ?? '');
            $t['listingLine'] = Listings::listingLine($t, $activities);
            $t['exchange'] = Listings::listingExchange($t, $activities);
            $t['executions'] = Executions::forGroup($t, $activities);
            $t['executionCount'] = count($t['executions']) ?: count($t['slices'] ?? []);
            $t['tag'] = is_array($n['tags'] ?? null) ? implode(', ', $n['tags']) : (string) ($n['tag'] ?? '');
            $t['notes'] = $n['thesis'] ?? '';
            $t['thesis'] = $n['thesis'] ?? '';
            $t['grade'] = $n['grade'] ?? '';
            $t['pnlPct'] = Metrics::closedPnlPct($t);
            $t['pnlCad'] = $t['pnlCad'] ?? $t['pnl'] ?? 0;
            $t['kind'] = Filters::tradeKind($t);

            return $t;
        }, $searched);
        if (! empty($filters['grade']) || ! empty($filters['tag'])) {
            $closedDecorated = array_values(array_filter($closedDecorated, function ($t) use ($filters) {
                if (! empty($filters['grade'])) {
                    $g = (string) ($t['grade'] ?? '');
                    $want = (string) $filters['grade'];
                    if ($want === 'Ungraded') {
                        if ($g !== '') {
                            return false;
                        }
                    } elseif (strcasecmp($g, $want) !== 0) {
                        return false;
                    }
                }
                if (! empty($filters['tag'])) {
                    $want = strtoupper((string) $filters['tag']);
                    $tags = preg_split('/\s*,\s*/', (string) ($t['tag'] ?? '')) ?: [];
                    $tags = array_map('strtoupper', array_filter(array_map('trim', $tags)));
                    if ($want === 'UNTAGGED') {
                        if ($tags !== []) {
                            return false;
                        }
                    } elseif (! in_array($want, $tags, true)) {
                        return false;
                    }
                }

                return true;
            }));
        }
        $closedSorted = Filters::sortRows($closedDecorated, function ($t) use ($closedSort) {
            // Keep $t['grade'] as letter (A/B/C/F); numeric rank is sort-only.
            $key = $closedSort['key'] ?? 'exitDate';
            if ($key === 'grade') {
                return self::gradeRank($t['grade'] ?? '');
            }
            $map = [
                'entryDate' => $t['entryDate'] ?? '',
                'exitDate' => $t['exitDate'] ?? '',
                'symbol' => $t['displaySymbol'] ?? $t['symbol'] ?? '',
                'side' => $t['displaySide'] ?? Sides::displaySide($t),
                'exchange' => $t['exchange'] ?? '',
                'quantity' => $t['quantity'] ?? 0,
                'entryPrice' => $t['entryPrice'] ?? 0,
                'exitPrice' => $t['exitPrice'] ?? 0,
                'pnlCad' => $t['pnl'] ?? 0,
                'pnlPct' => $t['pnlPct'] ?? null,
                'holdDays' => $t['holdDays'] ?? 0,
                'currency' => $t['currency'] ?? '',
                'tag' => $t['tag'] ?? '',
                'notes' => $t['notes'] ?? '',
            ];

            return $map[$key] ?? null;
        }, $closedSort['dir'] ?? 'desc');

        $openSort = $sort['open'] ?? ['key' => 'date', 'dir' => 'desc'];
        $openDecorated = array_map(function ($l) use ($activities) {
            $l['displaySymbol'] = Symbols::listingTicker($l['symbol'] ?? '');
            $l['listingLine'] = Listings::listingLine($l, $activities);

            return $l;
        }, $open);
        $openSorted = Filters::sortRows($openDecorated, function ($l) use ($openSort) {
            $map = [
                'date' => $l['date'] ?? '',
                'symbol' => $l['displaySymbol'] ?? $l['symbol'] ?? '',
                'direction' => $l['direction'] ?? '',
                'quantity' => $l['quantity'] ?? 0,
                'price' => $l['price'] ?? 0,
                'currency' => $l['currency'] ?? '',
                'name' => $l['name'] ?? '',
            ];

            return $map[$openSort['key'] ?? 'date'] ?? null;
        }, $openSort['dir'] ?? 'desc');

        $actSort = $sort['activity'] ?? ['key' => 'when', 'dir' => 'desc'];
        $actRows = array_map(function ($a) use ($activities) {
            $a['when'] = Dates::activityWhen($a);
            $a['displaySide'] = Sides::activityDisplaySide($a);
            $a['displaySymbol'] = Symbols::listingTicker($a['symbol'] ?? '');
            $a['listingLine'] = Listings::listingLine($a, $activities);

            return $a;
        }, Filters::filteredActivities($activities, $filters));
        $actSorted = Filters::sortRows($actRows, function ($a) use ($actSort) {
            $map = [
                'when' => $a['when'] ?? '',
                'symbol' => $a['displaySymbol'] ?? $a['symbol'] ?? '',
                'displaySide' => $a['displaySide'] ?? '',
                'quantity' => $a['quantity'] ?? 0,
                'unitPrice' => $a['unitPrice'] ?? 0,
                'netCashAmount' => $a['netCashAmount'] ?? 0,
            ];

            return $map[$actSort['key'] ?? 'when'] ?? null;
        }, $actSort['dir'] ?? 'desc');

        $years = Filters::listingFiltersOn($filters)
            ? Returns::yearsFromClosed($closedForMetrics)
            : Returns::yearsFromActivities($activities);
        $selectedYear = Dates::yearFromRange($filters['from'] ?? '', $filters['to'] ?? '');
        $compareYears = [];
        if ($selectedYear) {
            $compareYears[] = $selectedYear;
            $prev = (string) ((int) $selectedYear - 1);
            if (in_array($prev, $years, true)) {
                $compareYears[] = $prev;
            }
        } else {
            $compareYears = $years;
        }
        $annual = Filters::listingFiltersOn($filters)
            ? Returns::closedAnnualizedReturn($closedForMetrics, $years)
            : Returns::accountAnnualizedReturn($years);

        $symbols = Filters::unique(array_map(fn ($a) => Symbols::underlyingSymbol($a['symbol'] ?? ''), array_filter($activities, fn ($a) => ! empty($a['symbol']))));
        $symbols = array_values(array_filter($symbols, fn ($x) => $x && $x !== '—'));
        $accounts = Filters::unique(array_merge(
            array_map(fn ($a) => $a['accountType'] ?? '', $activities),
            array_map(fn ($a) => $a['nickname'] ?? $a['unifiedAccountType'] ?? '', $snapshot['accounts'] ?? []),
        ));

        $secById = [];
        foreach ($securities as $s) {
            if (! empty($s['id'])) {
                $secById[(string) $s['id']] = $s;
            }
        }
        $cashflowRows = Cashflow::buildRows($activities, $secById);
        $lastFills = Cashflow::lastFillPrices($activities);
        $positionsAll = Cashflow::positionsFromOpen($openSorted, $notes, gmdate('Y-m-d'), $snapshot['quotes'] ?? [], $lastFills);
        // Prefer securityId from securities table when FIFO lot lacked one.
        $secBySymbol = [];
        foreach ($securities as $s) {
            $sym = trim((string) ($s['symbol'] ?? ''));
            if ($sym === '') {
                continue;
            }
            $secBySymbol[strtoupper($sym)][] = $s;
        }
        foreach ($positionsAll as &$pos) {
            if (trim((string) ($pos['securityId'] ?? '')) !== '') {
                continue;
            }
            $sym = strtoupper(trim((string) ($pos['symbol'] ?? '')));
            $cands = $secBySymbol[$sym] ?? [];
            if ($cands === []) {
                $under = strtoupper(trim((string) ($pos['underlying'] ?? '')));
                $cands = $secBySymbol[$under] ?? [];
            }
            if ($cands === []) {
                continue;
            }
            $ccy = strtoupper((string) ($pos['currency'] ?? ''));
            $pick = $cands[0];
            foreach ($cands as $c) {
                if (strtoupper((string) ($c['currency'] ?? '')) === $ccy) {
                    $pick = $c;
                    break;
                }
            }
            $pos['securityId'] = (string) ($pick['id'] ?? '');
        }
        unset($pos);

        $exposures = is_array($snapshot['exposures'] ?? null) ? $snapshot['exposures'] : [];
        [$sectors, $regions] = Exposure::slices($positionsAll, $exposures);

        // Portfolio Allocation slices: open positions by market value (desktop portfolio_view alloc).
        $allocation = [];
        foreach ($positionsAll as $p) {
            $mv = $p['mv'] ?? null;
            if ($mv === null) {
                continue;
            }
            $v = (float) $mv;
            if ($v <= 0) {
                continue;
            }
            $allocation[] = [
                'id' => (string) ($p['id'] ?? ''),
                'symbol' => (string) ($p['symbol'] ?? ''),
                'account' => (string) ($p['account'] ?? ''),
                'value' => $v,
            ];
        }
        usort($allocation, fn ($a, $b) => $b['value'] <=> $a['value']);
        $allocTotal = array_sum(array_map(fn ($x) => $x['value'], $allocation));
        foreach ($allocation as &$ax) {
            $ax['share'] = $allocTotal > 0 ? ($ax['value'] / $allocTotal) : 0.0;
        }
        unset($ax);

        // Portfolio tiles — desktop model.py portfolio_view (CAD aggregates over open accounts in scope).
        $today = gmdate('Y-m-d');
        $accountFilter = trim((string) ($filters['account'] ?? ''));
        $openAccounts = [];
        foreach ($snapshot['accounts'] ?? [] as $acc) {
            if (strtolower((string) ($acc['status'] ?? '')) === 'closed') {
                continue;
            }
            $nick = trim((string) ($acc['nickname'] ?? ''));
            $aid = (string) ($acc['id'] ?? '');
            $uat = (string) ($acc['unifiedAccountType'] ?? '');
            $typ = (string) ($acc['type'] ?? '');
            if ($accountFilter !== '') {
                $ok = $aid === $accountFilter
                    || $nick === $accountFilter
                    || $uat === $accountFilter
                    || $typ === $accountFilter;
                if (! $ok) {
                    continue;
                }
            }
            $openAccounts[] = $acc;
        }
        $openAccountIds = [];
        foreach ($openAccounts as $acc) {
            $openAccountIds[(string) ($acc['id'] ?? '')] = true;
        }
        $accountNameOf = [];
        foreach ($openAccounts as $acc) {
            $aid = (string) ($acc['id'] ?? '');
            $nick = trim((string) ($acc['nickname'] ?? ''));
            $accountNameOf[$aid] = $nick !== '' ? $nick : $aid;
        }

        $marketValue = 0.0;
        $costBasis = 0.0;
        $unrealized = 0.0;
        $dayChangeTotal = 0.0;
        $dayChangeAny = false;
        $quotedMv = 0.0;
        foreach ($positionsAll as $p) {
            $mv = $p['mv'] ?? null;
            if ($mv !== null && is_numeric($mv)) {
                $mvF = (float) $mv;
                // Desktop signs shorts negative for market value.
                $marketValue += ! empty($p['short']) ? -$mvF : $mvF;
            }
            $costBasis += abs((float) ($p['cost'] ?? 0));
            if (($p['unreal'] ?? null) !== null && is_numeric($p['unreal'])) {
                $unrealized += (float) $p['unreal'];
            }
            if (($p['dayChange'] ?? null) !== null && is_numeric($p['dayChange'])) {
                $dayChangeTotal += (float) $p['dayChange'];
                $dayChangeAny = true;
                if ($mv !== null && is_numeric($mv)) {
                    $mvF = (float) $mv;
                    $quotedMv += ! empty($p['short']) ? -$mvF : $mvF;
                }
            }
        }
        $dayChange = $dayChangeAny ? $dayChangeTotal : null;
        $prevValue = ($dayChangeAny) ? ($quotedMv - $dayChangeTotal) : 0.0;
        $dayChangePct = ($dayChangeAny && $prevValue) ? ($dayChangeTotal / $prevValue) : null;
        $unrealizedPct = $costBasis > 0 ? ($unrealized / $costBasis) : null;

        $navs = [];
        foreach ($openAccounts as $acc) {
            if (! isset($acc['netLiquidationValue']) || ! is_numeric($acc['netLiquidationValue'])) {
                continue;
            }
            $navs[] = Fx::toCad((float) $acc['netLiquidationValue'], (string) ($acc['currency'] ?? 'CAD'), $today);
        }
        $nav = $navs !== [] ? array_sum($navs) : null;
        $navAccounts = count($navs);

        $cashCurrencies = is_array($snapshot['cashCurrencies'] ?? null) ? $snapshot['cashCurrencies'] : [];
        $usedBy = [];
        $cashBy = [];
        foreach ($snapshot['balances'] ?? [] as $b) {
            $aid = (string) ($b['accountId'] ?? '');
            if ($aid === '' || ! isset($openAccountIds[$aid])) {
                continue;
            }
            $sid = (string) ($b['securityId'] ?? '');
            $ccy = strtoupper((string) ($cashCurrencies[$sid] ?? ''));
            if ($ccy === '') {
                continue;
            }
            $q = isset($b['quantity']) && is_numeric($b['quantity']) ? (float) $b['quantity'] : 0.0;
            if ($q < 0) {
                $usedBy[$ccy] = ($usedBy[$ccy] ?? 0.0) + (-$q);
            } elseif ($q > 0) {
                $cashBy[$ccy] = ($cashBy[$ccy] ?? 0.0) + $q;
            }
        }
        $marginUsed = 0.0;
        foreach ($usedBy as $ccy => $v) {
            $marginUsed += Fx::toCad((float) $v, (string) $ccy, $today);
        }
        $cash = 0.0;
        foreach ($cashBy as $ccy => $v) {
            $cash += Fx::toCad((float) $v, (string) $ccy, $today);
        }
        $cashPct = ($nav !== null && $nav != 0.0) ? ($cash / $nav) : null;
        $marginUsedPct = ($marketValue != 0.0) ? ($marginUsed / $marketValue) : null;
        ksort($usedBy);
        $marginUsedBy = [];
        foreach ($usedBy as $ccy => $v) {
            $marginUsedBy[$ccy] = round((float) $v, 2);
        }

        // Margin accounts: MARGIN in type or unifiedAccountType (UAT e.g. SELF_DIRECTED_NON_REGISTERED_MARGIN),
        // plus any accountId present in the margin buying-power table.
        $marginIds = [];
        foreach ($openAccounts as $acc) {
            $typeBlob = strtoupper((string) ($acc['type'] ?? '').' '.(string) ($acc['unifiedAccountType'] ?? ''));
            if (str_contains($typeBlob, 'MARGIN')) {
                $marginIds[(string) ($acc['id'] ?? '')] = true;
            }
        }
        foreach ($snapshot['margin'] ?? [] as $mrow) {
            $aid = (string) ($mrow['accountId'] ?? '');
            if ($aid !== '' && isset($openAccountIds[$aid])) {
                $marginIds[$aid] = true;
            }
        }
        $hasMargin = $marginIds !== [];
        $avail = [];
        $unavailable = [];
        foreach ($snapshot['margin'] ?? [] as $mrow) {
            $aid = (string) ($mrow['accountId'] ?? '');
            if ($aid === '' || ! isset($marginIds[$aid])) {
                continue;
            }
            if (! isset($mrow['buyingPower']) || ! is_numeric($mrow['buyingPower'])) {
                $unavailable[] = $accountNameOf[$aid] ?? $aid;
                continue;
            }
            $avail[] = Fx::toCad((float) $mrow['buyingPower'], (string) ($mrow['currency'] ?? 'CAD'), $today);
        }
        $availableMargin = $avail !== [] ? array_sum($avail) : null;
        sort($unavailable);

        $cashflow = Cashflow::view(
            $cashflowRows,
            $filters,
            $positionsAll,
            gmdate('Y-m-d'),
            $snapshot['distributions'] ?? [],
            $snapshot['quotes'] ?? [],
        );

        return [
            'closed' => $closedSorted,
            'closedForMetrics' => $closedForMetrics,
            'open' => $openSorted,
            'positions' => $positionsAll,
            'unmatched' => $fifo['unmatched'],
            'activityCount' => count($activities),
            'activities' => [], // full list omitted from book cache (use activityRows / Activity)
            'activityRows' => $actSorted,
            'metrics' => $m,
            'curve' => $curve,
            'monthly' => $monthly,
            'bySymbol' => $bySymbol,
            'grades' => Metrics::gradeBuckets($closedDecorated),
            'queue' => Metrics::reviewQueue($closedDecorated),
            'years' => $years,
            'selectedYear' => $selectedYear,
            'compare' => Returns::compareRows($compareYears, $closedForMetrics, $filters),
            'annual' => $annual,
            'symbols' => $symbols,
            'accounts' => $accounts,
            'exchanges' => Listings::knownExchanges(),
            'notes' => $notes,
            'cashflow' => $cashflow,
            'cashflowRows' => $cashflowRows,
            'portfolio' => [
                'allocation' => $allocation,
                'marketValue' => $marketValue,
                'sectors' => $sectors,
                'regions' => $regions,
                'costBasis' => $costBasis,
                'unrealized' => $unrealized,
                'unrealizedPct' => $unrealizedPct,
                'positionCount' => count($positionsAll),
                'accountCount' => count(array_unique(array_map(fn ($p) => (string) ($p['account'] ?? ''), $positionsAll))),
                'nav' => $nav,
                'navAccounts' => $navAccounts,
                'marginUsed' => $marginUsed,
                'marginUsedBy' => $marginUsedBy,
                'marginUsedPct' => $marginUsedPct,
                'availableMargin' => $availableMargin,
                'availableMarginUnavailable' => $unavailable,
                'hasMargin' => $hasMargin,
                'cash' => $cash,
                'cashPct' => $cashPct,
                'dayChange' => $dayChange,
                'dayChangePct' => $dayChangePct,
            ],
        ];
    }

    /** Sort ordinal only — never write this onto the trade grade field (UI shows A/B/C/F). */
    private static function gradeRank(string $grade): int
    {
        return match (strtoupper(trim($grade))) {
            'A' => 0,
            'B' => 1,
            'C' => 2,
            'F' => 3,
            default => 9,
        };
    }
}
