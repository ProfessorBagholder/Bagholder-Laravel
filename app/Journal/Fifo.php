<?php

namespace App\Journal;

final class Fifo
{
    public static function match(array $activities): array
    {
        $folded = self::foldStkdis($activities);
        $fills = [];
        foreach ($folded as $a) {
            $cat = $a['category'] ?? '';
            if (($cat !== 'trade' && $cat !== 'option_event') || empty($a['symbol'])) {
                continue;
            }
            $side = Sides::tradeSide($a);
            $qty = abs((float) ($a['quantity'] ?? 0));
            if (! $side || $qty <= 0) {
                continue;
            }
            $fills[] = ['activity' => $a, 'side' => $side, 'qty' => $qty];
        }
        usort($fills, function ($a, $b) {
            $d = strcmp($a['activity']['transactionDate'] ?? '', $b['activity']['transactionDate'] ?? '');
            if ($d !== 0) {
                return $d;
            }
            $ra = self::fillRank($a);
            $rb = self::fillRank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcasecmp((string) ($a['activity']['id'] ?? ''), (string) ($b['activity']['id'] ?? ''));
        });

        $books = [];
        $rtOpen = [];
        $closed = [];
        $unmatched = [];

        $getBook = function (array $a) use (&$books): string {
            return Sides::fifoAccount($a).'::'.($a['symbol'] ?? '').'::'.($a['currency'] ?? '');
        };

        foreach ($fills as $fill) {
            $a = $fill['activity'];
            $key = $getBook($a);
            $books[$key] ??= [];
            $closingDir = $fill['side'] === 'BUY' ? 'SHORT' : 'LONG';
            $remaining = $fill['qty'];

            while ($remaining > 0 && $books[$key] !== [] && ($books[$key][0]['direction'] ?? '') === $closingDir) {
                $lot = &$books[$key][0];
                $matched = min($lot['qty'], $remaining);
                $closed[] = self::closeSlice($lot, $a, $fill, $matched);
                $lot['commission'] *= ($lot['qty'] - $matched) / $lot['qty'];
                $lot['qty'] -= $matched;
                $remaining -= $matched;
                unset($lot);
                if ($books[$key][0]['qty'] <= 1e-10) {
                    array_shift($books[$key]);
                }
            }
            if ($books[$key] === []) {
                $rtOpen[$key] = null;
            }

            if ($remaining > 1e-10 && $fill['side'] === 'SELL') {
                foreach ($books as $dk => &$dbook) {
                    if ($dbook === [] || $dk === $key) {
                        continue;
                    }
                    $bits = explode('::', $dk);
                    if (($bits[0] ?? '') !== Sides::fifoAccount($a) || ($bits[2] ?? '') !== ($a['currency'] ?? '')) {
                        continue;
                    }
                    if (! self::tickerWasReplaced($activities, $bits[0], $bits[1], $bits[2], $a['transactionDate'] ?? '')) {
                        continue;
                    }
                    while ($remaining > 1e-10 && $dbook !== [] && ($dbook[0]['direction'] ?? '') === $closingDir) {
                        $lot = &$dbook[0];
                        $matched = min($lot['qty'], $remaining);
                        $closed[] = self::closeSlice($lot, $a, $fill, $matched, useFillSymbol: true);
                        $lot['commission'] *= ($lot['qty'] - $matched) / $lot['qty'];
                        $lot['qty'] -= $matched;
                        $remaining -= $matched;
                        unset($lot);
                        if ($dbook[0]['qty'] <= 1e-10) {
                            array_shift($dbook);
                        }
                    }
                    if ($dbook === []) {
                        $rtOpen[$dk] = null;
                    }
                    if ($remaining <= 1e-10) {
                        break;
                    }
                }
                unset($dbook);
            }

            if ($remaining > 1e-10) {
                $opening = Sides::openingDirection($a, $fill['side']);
                if ($opening) {
                    if ($books[$key] === [] || empty($rtOpen[$key])) {
                        $rtOpen[$key] = 'rt:'.(string) ($a['id'] ?? '');
                    }
                    $books[$key][] = [
                        'qty' => $remaining,
                        'price' => (float) ($a['unitPrice'] ?? 0),
                        'date' => $a['transactionDate'] ?? '',
                        'commission' => $fill['qty'] > 0 ? ((float) ($a['commission'] ?? 0)) * ($remaining / $fill['qty']) : 0,
                        'direction' => $opening,
                        'accountId' => $a['accountId'] ?? '',
                        'accountType' => $a['accountType'] ?? '',
                        'symbol' => $a['symbol'] ?? '',
                        'name' => $a['name'] ?? '',
                        'currency' => $a['currency'] ?? '',
                        'activityId' => $a['id'] ?? '',
                        'securityId' => (string) ($a['securityId'] ?? ''),
                        'rt' => (string) ($rtOpen[$key] ?? ('rt:'.(string) ($a['id'] ?? ''))),
                    ];
                } else {
                    $unmatched[] = [
                        'symbol' => $a['symbol'] ?? '',
                        'currency' => $a['currency'] ?? '',
                        'side' => $fill['side'],
                        'quantity' => $remaining,
                        'price' => (float) ($a['unitPrice'] ?? 0),
                        'date' => $a['transactionDate'] ?? '',
                        'description' => $a['description'] ?? null,
                        'accountId' => $a['accountId'] ?? '',
                    ];
                }
            }
        }

        $open = [];
        foreach ($books as $book) {
            foreach ($book as $lot) {
                if ($lot['qty'] <= 1e-10) {
                    continue;
                }
                $open[] = [
                    'accountId' => $lot['accountId'],
                    'accountType' => $lot['accountType'],
                    'symbol' => $lot['symbol'],
                    'name' => $lot['name'],
                    'currency' => $lot['currency'],
                    'quantity' => $lot['qty'],
                    'price' => $lot['price'],
                    'date' => $lot['date'],
                    'commission' => $lot['commission'],
                    'direction' => $lot['direction'],
                    'activityId' => $lot['activityId'] ?? '',
                    'securityId' => (string) ($lot['securityId'] ?? ''),
                    'rt' => (string) ($lot['rt'] ?? (! empty($lot['activityId']) ? 'rt:'.$lot['activityId'] : '')),
                ];
            }
        }

        usort($closed, fn ($a, $b) => strcmp($a['exitDate'], $b['exitDate']) ?: strcasecmp((string) $a['id'], (string) $b['id']));

        return ['closed' => $closed, 'open' => $open, 'unmatched' => $unmatched];
    }

    private static function closeSlice(array $lot, array $a, array $fill, float $matched, bool $useFillSymbol = false): array
    {
        $exitCommission = $fill['qty'] > 0 ? ((float) ($a['commission'] ?? 0)) * ($matched / $fill['qty']) : 0;
        $entryCommission = $lot['qty'] > 0 ? $lot['commission'] * ($matched / $lot['qty']) : 0;
        $commission = $entryCommission + $exitCommission;
        $mult = Symbols::optionMultiplier($useFillSymbol ? ($a['symbol'] ?? '') : $lot['symbol']);
        $rawPnl = (($lot['direction'] === 'LONG')
            ? (((float) ($a['unitPrice'] ?? 0)) - $lot['price']) * $matched
            : ($lot['price'] - ((float) ($a['unitPrice'] ?? 0))) * $matched) * $mult;
        $rt = (string) ($lot['rt'] ?? '');
        if ($rt === '' && ! empty($lot['activityId'])) {
            $rt = 'rt:'.$lot['activityId'];
        }
        $trade = [
            'id' => '',
            'rt' => $rt,
            'accountId' => $lot['accountId'],
            'accountType' => $lot['accountType'],
            'symbol' => $useFillSymbol ? ($a['symbol'] ?? $lot['symbol']) : $lot['symbol'],
            'name' => $useFillSymbol ? (($a['name'] ?? '') ?: $lot['name']) : $lot['name'],
            'currency' => $lot['currency'],
            'side' => $fill['side'],
            'quantity' => $matched,
            'entryPrice' => $lot['price'],
            'exitPrice' => (float) ($a['unitPrice'] ?? 0),
            'entryDate' => $lot['date'],
            'exitDate' => $a['transactionDate'] ?? '',
            'holdDays' => Dates::daysBetween($lot['date'], $a['transactionDate'] ?? ''),
            'commission' => $commission,
            'entryCommission' => $entryCommission,
            'exitCommission' => $exitCommission,
            'pnl' => $rawPnl - $commission,
            'pnlCad' => $rawPnl - $commission,
            'openDirection' => $lot['direction'],
            'buyActivityId' => $lot['activityId'] ?? '',
            'sellActivityId' => $a['id'] ?? '',
        ];
        $trade['id'] = self::stableTradeId($trade);

        return $trade;
    }

    public static function stableTradeId(array $t): string
    {
        return implode('|', [
            $t['accountId'] ?? '',
            $t['symbol'] ?? '',
            $t['currency'] ?? '',
            $t['entryDate'] ?? '',
            $t['exitDate'] ?? '',
            number_format((float) ($t['quantity'] ?? 0), 8, '.', ''),
            number_format((float) ($t['entryPrice'] ?? 0), 8, '.', ''),
            number_format((float) ($t['exitPrice'] ?? 0), 8, '.', ''),
            $t['side'] ?? '',
        ]);
    }

    public static function foldStkdis(array $activities): array
    {
        $rest = [];
        $groups = [];
        foreach ($activities as $a) {
            if (Symbols::compactType($a['activityType'] ?? '') !== 'STKDIS') {
                $rest[] = $a;
                continue;
            }
            $k = ($a['symbol'] ?? '').'|'.($a['transactionDate'] ?? '').'|'.($a['currency'] ?? '');
            $groups[$k] ??= ['pos' => 0.0, 'neg' => 0.0, 'sample' => $a];
            $q = (float) ($a['quantity'] ?? 0);
            if (($a['activitySubType'] ?? '') === 'SELL' || $q < 0) {
                $groups[$k]['neg'] += abs($q);
            } else {
                $groups[$k]['pos'] += abs($q);
            }
        }
        foreach ($groups as $g) {
            $net = $g['pos'] - $g['neg'];
            if ($net > 1e-10) {
                $a = $g['sample'];
                $a['quantity'] = $net;
                $a['activitySubType'] = 'BUY';
                $a['unitPrice'] = 0;
                $a['netCashAmount'] = 0;
                $a['category'] = 'trade';
                $rest[] = $a;
            }
        }

        return $rest;
    }

    private static function fillRank(array $f): int
    {
        $t = Symbols::compactType($f['activity']['activityType'] ?? '');
        $s = Symbols::compactType($f['activity']['activitySubType'] ?? '');
        $blob = $t.$s;
        if ((str_contains($blob, 'TOOPEN') || $t === 'STO' || $s === 'STO') && $f['side'] === 'SELL') {
            return 0;
        }
        if (Sides::isCloseOnly($f['activity']) && $f['side'] === 'BUY') {
            return 1;
        }
        if ($f['side'] === 'BUY') {
            return 2;
        }
        if (str_contains($blob, 'TOCLOSE') || $t === 'STC' || $s === 'STC') {
            return 3;
        }

        return 4;
    }

    private static function tickerWasReplaced(array $activities, string $accountId, string $symbol, string $currency, string $byDate): bool
    {
        $removedOn = '';
        foreach ($activities as $a) {
            if (Sides::fifoAccount($a) !== $accountId) {
                continue;
            }
            if (($a['symbol'] ?? '') !== $symbol || ($a['currency'] ?? '') !== $currency) {
                continue;
            }
            $t = Symbols::compactType($a['activityType'] ?? '');
            $raw = Symbols::compactType($a['rawType'] ?? '').Symbols::compactType($a['aftType'] ?? '');
            $sub = Symbols::compactType($a['activitySubType'] ?? '');
            $q = (float) ($a['quantity'] ?? 0);
            $removal = ($t === 'STKDIS' && ($sub === 'SELL' || $q < 0))
                || preg_match('/CODECHANGE|SYMBOLCHANGE|TICKERCHANGE|LISTINGSTATUS|SECURITYSWAP/', $raw);
            $day = $a['transactionDate'] ?? '';
            if ($removal && $day && ($removedOn === '' || $day < $removedOn)) {
                $removedOn = $day;
            }
        }
        if ($removedOn === '' || $removedOn > $byDate) {
            return false;
        }
        foreach ($activities as $a) {
            if (Sides::fifoAccount($a) !== $accountId) {
                continue;
            }
            if (($a['symbol'] ?? '') !== $symbol || ($a['currency'] ?? '') !== $currency) {
                continue;
            }
            if (($a['transactionDate'] ?? '') <= $removedOn) {
                continue;
            }
            $cat = $a['category'] ?? '';
            if (($cat === 'trade' || $cat === 'option_event')
                && Symbols::compactType($a['activityType'] ?? '') !== 'STKDIS'
                && Sides::tradeSide($a)) {
                return false;
            }
        }

        return true;
    }
}
