<?php

namespace App\Wealthsimple;

final class ActivityMapper
{
    private const KEEP_STATUS = [
        'POSTED',
        'COMPLETED',
        'SETTLED',
        'COMPLETE',
        'FILLED',
        'EXECUTED',
        'PROCESSED',
        'CONFIRMED',
        'BOOKED',
        'SUCCEEDED',
        'SUCCESS',
    ];

    private const SKIP_TYPE_MARKERS = [
        'SHARE_LENDING',
        'SHARELENDING',
        'STOCK_LENDING',
        'STOCKLENDING',
    ];

    private const CORP_BLOBS = [
        'STKDIS',
        'STOCKDISTRIBUTION',
        'STOCKDIV',
        'SPINOFF',
        'SPIN',
        'DIVIDENDINKIND',
        'INKIND',
        'CORPORATEACTION',
        'CODECHANGE',
        'SYMBOLCHANGE',
        'TICKERCHANGE',
        'LISTINGSTATUS',
        'SECURITYSWAP',
        'MANDATORYEXCHANGE',
        'NAMECHANGE',
    ];

    /** True when this GraphQL row should not be stored. Same rules as bagholder.py skip_activity. */
    public static function skip(array $item): bool
    {
        if ($item === []) {
            return true;
        }
        if (trim(self::str($item['occurredAt'] ?? '')) === '') {
            return true;
        }
        $status = self::compact($item['status'] ?? '');
        [$typ, $sub, $blob] = self::typeBlob($item);
        if (self::isCorpShareMove($item)) {
            foreach (['REJECT', 'CANCEL', 'FAIL', 'VOID'] as $x) {
                if (str_contains($status, $x)) {
                    return true;
                }
            }
        } elseif ($status === '' || ! in_array($status, self::KEEP_STATUS, true)) {
            return true;
        }
        if (in_array($typ, ['LOAN', 'RECALL'], true) || in_array($sub, ['LOAN', 'RECALL'], true)) {
            return true;
        }
        if (str_ends_with($typ, '_LOAN') || str_ends_with($sub, '_LOAN')) {
            return true;
        }
        if (str_ends_with($typ, '_RECALL') || str_ends_with($sub, '_RECALL')) {
            return true;
        }
        foreach (self::SKIP_TYPE_MARKERS as $marker) {
            if (str_contains($blob, $marker)) {
                return true;
            }
        }
        if (str_contains($blob, 'SHARE_LENDING') || str_contains($blob, 'SHARELENDING')) {
            return true;
        }

        return false;
    }

    public static function rows(array $item, array $accounts = []): array
    {
        if ($item === []) {
            return [];
        }
        $src = self::assetSymbol($item);
        $dst = self::counterSymbol($item);
        $qty = abs(self::num($item['assetQuantity'] ?? 0));
        if ($src !== '' && $dst !== '' && $src !== $dst && $qty && self::isCorpShareMove($item)) {
            $cid = trim(self::str($item['canonicalId'] ?? '')) ?: 'swap';
            $outgoing = $item;
            $outgoing['assetSymbol'] = $src;
            $outgoing['counterAssetSymbol'] = '';
            $outgoing['type'] = 'STKDIS';
            $outgoing['subType'] = 'STKDIS';
            $outgoing['assetQuantity'] = -$qty;
            $outgoing['amount'] = 0;
            $outgoing['amountSign'] = 'negative';
            $outgoing['canonicalId'] = $cid.':out';
            $incoming = $item;
            $incoming['assetSymbol'] = $dst;
            $incoming['counterAssetSymbol'] = '';
            $incoming['type'] = 'STKDIS';
            $incoming['subType'] = 'STKDIS';
            $incoming['assetQuantity'] = $qty;
            $incoming['amount'] = 0;
            $incoming['amountSign'] = 'positive';
            $incoming['canonicalId'] = $cid.':in';
            $out = [];
            foreach ([$outgoing, $incoming] as $part) {
                $row = self::map($part, $accounts);
                if ($row) {
                    $out[] = $row;
                }
            }

            return $out;
        }
        $row = self::map($item, $accounts);

        return $row ? [$row] : [];
    }

    public static function map(array $item, array $accounts = []): ?array
    {
        if (self::skip($item)) {
            return null;
        }
        $occurred = trim(self::str($item['occurredAt'] ?? ''));
        $transactionDate = self::dateOnly($occurred);
        if ($transactionDate === '') {
            return null;
        }
        $accountId = self::str($item['accountId'] ?? '');
        $typ = strtoupper(str_replace('-', '_', self::str($item['type'] ?? '')));
        $sub = strtoupper(str_replace('-', '_', self::str($item['subType'] ?? '')));
        $qtyRaw = self::num($item['assetQuantity'] ?? 0);
        $qtyAbs = abs($qtyRaw);
        $cash = self::signedCash($item);
        $amountAbs = abs(self::num($item['amount'] ?? 0));
        $fees = abs(self::num($item['fees'] ?? 0));
        $isOpt = self::isOption($item);
        $symbol = $isOpt ? self::optionSymbol($item) : self::assetSymbol($item);

        $cur = strtoupper(self::str($item['currency'] ?? ''));
        if (! in_array($cur, ['CAD', 'USD'], true)) {
            $cur = $isOpt ? 'USD' : 'CAD';
        }

        $unitPrice = 0.0;
        if ($qtyAbs) {
            $unitPrice = $amountAbs / $qtyAbs;
            if ($isOpt && $unitPrice > 20) {
                $unitPrice = $unitPrice / 100.0;
            }
        }

        $activityType = 'Other';
        $activitySub = $sub ?: $typ;
        $category = 'other';
        $quantity = $qtyAbs;

        if ($typ === 'DIY_BUY') {
            $category = 'trade';
            if ($isOpt) {
                $activityType = 'Trade';
                $activitySub = self::isToClose($sub) ? 'BUYTOCLOSE' : 'BUYTOOPEN';
            } else {
                $activityType = 'Trade';
                $activitySub = 'BUY';
            }
            $quantity = abs($qtyAbs);
        } elseif ($typ === 'DIY_SELL') {
            $category = 'trade';
            if ($isOpt) {
                $activityType = 'Trade';
                $activitySub = self::isToClose($sub) ? 'SELLTOCLOSE' : 'SELLTOOPEN';
            } else {
                $activityType = 'Trade';
                $activitySub = 'SELL';
            }
            $quantity = -abs($qtyAbs);
        } elseif ($typ === 'OPTIONS_BUY') {
            $category = 'trade';
            $activityType = 'OPTIONS_BUY';
            $activitySub = self::isToClose($sub) ? 'BUYTOCLOSE' : 'BUYTOOPEN';
            $quantity = abs($qtyAbs);
        } elseif ($typ === 'OPTIONS_SELL') {
            $category = 'trade';
            $activityType = 'OPTIONS_SELL';
            $activitySub = self::isToClose($sub) ? 'SELLTOCLOSE' : 'SELLTOOPEN';
            $quantity = -abs($qtyAbs);
        } elseif (in_array($typ, ['EXPIR', 'EXPIRY', 'EXPIRE', 'ASSIGN', 'ASSIGNMENT', 'EXERCISE'], true)) {
            $category = 'option_event';
            $activityType = str_contains($typ, 'ASSIGN') ? 'ASSIGN' : (str_contains($typ, 'EXERCISE') ? 'EXERCISE' : 'EXPIR');
            $covering = str_contains($typ, 'ASSIGN') || str_contains(self::compact($sub), 'COVER') || self::isToClose($sub);
            $activitySub = $covering ? 'BUY' : 'SELL';
            $quantity = $activitySub === 'SELL' ? -abs($qtyAbs) : abs($qtyAbs);
        } elseif (in_array($typ, ['DEPOSIT', 'CONTRIBUTION'], true)) {
            $activityType = 'Deposit';
            $activitySub = 'deposit';
            $category = 'deposit';
        } elseif ($typ === 'WITHDRAWAL') {
            $activityType = 'Withdrawal';
            $activitySub = 'withdrawal';
            $category = 'withdrawal';
        } elseif ($typ === 'INTERNAL_TRANSFER' || in_array(self::compact($typ), ['TRFIN', 'TRFOUT', 'TRANSFERIN', 'TRANSFEROUT', 'INTERNALTRANSFER'], true)) {
            $activityType = 'Transfer';
            $activitySub = 'transfer';
            $category = 'transfer';
        } elseif ($typ === 'DIVIDEND' && ! self::isCorpShareMove($item)) {
            $activityType = 'Dividend';
            $activitySub = 'dividend';
            $category = 'dividend';
        } elseif ($typ === 'INTEREST' || str_contains($sub, 'FPL_INTEREST') || self::compact($typ) === 'FPLINTEREST') {
            $activityType = 'Interest';
            $activitySub = 'interest';
            $category = 'interest';
        } elseif ($typ === 'FUNDS_CONVERSION') {
            $activityType = 'FxExchange';
            $activitySub = 'fx';
            $category = 'fx';
        } elseif (in_array($typ, ['FEE', 'REFUND'], true)) {
            $activityType = $typ === 'REFUND' ? 'Refund' : 'Fee';
            $activitySub = 'fee';
            $category = 'fee';
        } elseif (self::isCorpShareMove($item) || in_array($typ, ['STOCK_DISTRIBUTION', 'STKDIS', 'SPIN', 'SPINOFF', 'STK_DIS'], true)
            || str_contains(self::compact($typ), 'STKDIS')
            || str_contains(self::compact($typ), 'STOCKDISTRIBUTION')
            || str_contains(self::compact($sub), 'STOCKDISTRIBUTION')) {
            $activityType = 'STKDIS';
            $category = 'trade';
            $unitPrice = 0.0;
            $sign = strtolower(trim(self::str($item['amountSign'] ?? '')));
            $outgoing = $qtyRaw < 0 || in_array($sign, ['negative', 'debit', '-', 'neg'], true);
            if (! $outgoing && self::isCodeChange($item) && self::counterSymbol($item) === '' && ! str_contains(self::compact($item['type'] ?? ''), 'STKDIS')) {
                $outgoing = true;
            }
            if ($outgoing) {
                $activitySub = 'SELL';
                $quantity = -$qtyAbs;
            } else {
                $activitySub = 'BUY';
                $quantity = $qtyAbs;
            }
        } else {
            $activityType = self::str($item['type'] ?? 'Other');
            $activitySub = self::str($item['subType'] ?? 'other');
            $category = 'other';
        }

        if (in_array($activitySub, ['SELL', 'SELLTOOPEN', 'SELLTOCLOSE'], true)) {
            $quantity = $qtyAbs ? -abs($qtyAbs) : $quantity;
        }

        $sign = strtolower(trim(self::str($item['amountSign'] ?? '')));
        $direction = '';
        if (in_array($sign, ['negative', 'debit', '-', 'neg'], true) || $cash < 0) {
            $direction = 'DEBIT';
        } elseif (in_array($sign, ['positive', 'credit', '+', 'pos'], true) || $cash > 0) {
            $direction = 'CREDIT';
        }

        $cid = trim(self::str($item['canonicalId'] ?? ''));
        if ($cid === '') {
            $cid = trim(self::str($item['id'] ?? ''));
        }

        $accountType = '';
        if ($accountId && isset($accounts[$accountId]) && is_array($accounts[$accountId])) {
            $accountType = self::str($accounts[$accountId]['nickname'] ?? $accounts[$accountId]['unifiedAccountType'] ?? $accounts[$accountId]['type'] ?? '');
        }

        return [
            'id' => $cid !== '' ? $cid : ($transactionDate.'-'.$typ.'-'.$symbol),
            'canonicalId' => $cid !== '' ? $cid : null,
            'occurredAt' => $occurred,
            'transactionDate' => $transactionDate,
            'settlementDate' => $transactionDate,
            'accountId' => $accountId,
            'bookId' => $accountId,
            'fifoId' => (self::fifoPoolIds($accounts)[$accountId] ?? $accountId),
            'accountType' => $accountType,
            'activityType' => $activityType,
            'activitySubType' => $activitySub,
            'description' => self::str($item['description'] ?? $item['canonicalDescription'] ?? ''),
            'direction' => $direction,
            'symbol' => $symbol,
            'name' => self::str($item['aftOriginatorName'] ?? $item['institutionName'] ?? $item['securityName'] ?? $symbol),
            'currency' => $cur,
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'commission' => $fees,
            'netCashAmount' => $cash,
            'category' => $category,
            'source' => 'wealthsimple',
            'rawType' => self::str($item['type'] ?? ''),
            'aftType' => self::str($item['aftTransactionType'] ?? ''),
            'counterSymbol' => self::counterSymbol($item),
            'securityId' => trim(self::str($item['securityId'] ?? '')) ?: null,
        ];
    }


    /** CAD+USD sides of the same Wealthsimple account share one FIFO book. Same as bagholder.py fifo_pool_ids. */
    public static function fifoPoolIds(array $accounts): array
    {
        $recs = [];
        $isList = array_is_list($accounts);
        foreach ($accounts as $k => $a) {
            if (! is_array($a)) {
                continue;
            }
            if (! $isList && empty($a["id"])) {
                $a["id"] = $k;
            }
            $recs[] = $a;
        }
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] = $parent[$x] ?? $x;
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            if ($a === "" || $b === "") {
                return;
            }
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };
        $byNick = [];
        foreach ($recs as $a) {
            $aid = trim((string) ($a["id"] ?? ""));
            if ($aid === "") {
                continue;
            }
            $find($aid);
            $linked = is_array($a["linkedAccount"] ?? null) ? $a["linkedAccount"] : [];
            $lid = trim((string) ($linked["id"] ?? ""));
            if ($lid !== "") {
                $union($aid, $lid);
            }
            $nick = trim((string) ($a["nickname"] ?? ""));
            if ($nick !== "") {
                $byNick[$nick][] = $aid;
            }
        }
        foreach ($byNick as $ids) {
            $root = $ids[0];
            foreach (array_slice($ids, 1) as $other) {
                $union($root, $other);
            }
        }

        $out = [];
        foreach (array_keys($parent) as $aid) {
            $out[$aid] = $find($aid);
        }

        return $out;
    }

    /** @param  list<array<string,mixed>>  $rows */
    public static function applyFifoPools(array $rows, array $accounts): array
    {
        $pools = self::fifoPoolIds($accounts);
        foreach ($rows as &$row) {
            $aid = (string) ($row["accountId"] ?? "");
            $row["fifoId"] = $pools[$aid] ?? $aid;
        }
        unset($row);

        return $rows;
    }
    public static function toDatabase(array $row): array
    {
        return [
            'id' => $row['id'] ?? $row['canonicalId'] ?? '',
            'canonical_id' => $row['canonicalId'] ?? $row['id'] ?? '',
            'occurred_at' => $row['occurredAt'] ?? null,
            'transaction_date' => $row['transactionDate'] ?? '',
            'settlement_date' => $row['settlementDate'] ?? $row['transactionDate'] ?? null,
            'account_id' => $row['accountId'] ?? null,
            'book_id' => $row['bookId'] ?? $row['accountId'] ?? null,
            'fifo_id' => $row['fifoId'] ?? $row['accountId'] ?? null,
            'account_type' => $row['accountType'] ?? null,
            'activity_type' => $row['activityType'] ?? null,
            'activity_sub_type' => $row['activitySubType'] ?? null,
            'description' => $row['description'] ?? null,
            'direction' => $row['direction'] ?? null,
            'symbol' => $row['symbol'] ?? null,
            'name' => $row['name'] ?? null,
            'currency' => $row['currency'] ?? null,
            'quantity' => $row['quantity'] ?? null,
            'unit_price' => $row['unitPrice'] ?? null,
            'commission' => $row['commission'] ?? null,
            'net_cash_amount' => $row['netCashAmount'] ?? null,
            'category' => $row['category'] ?? null,
            'balance' => $row['balance'] ?? null,
            'source' => $row['source'] ?? 'wealthsimple',
            'raw_type' => $row['rawType'] ?? null,
            'aft_type' => $row['aftType'] ?? null,
            'counter_symbol' => $row['counterSymbol'] ?? null,
            'security_id' => $row['securityId'] ?? null,
        ];
    }

    public static function signedCash(array $item): float
    {
        $amount = abs(self::num($item['amount'] ?? 0));
        $typ = strtoupper(str_replace('-', '_', self::str($item['type'] ?? '')));
        $sub = strtoupper(str_replace('-', '_', self::str($item['subType'] ?? '')));
        if (in_array($typ, ['DIY_BUY', 'OPTIONS_BUY', 'WITHDRAWAL'], true)
            || ($typ === 'INTERNAL_TRANSFER' && str_contains($sub, 'SOURCE'))) {
            return -$amount;
        }
        if (in_array($typ, ['DIY_SELL', 'OPTIONS_SELL', 'DEPOSIT', 'CONTRIBUTION', 'DIVIDEND', 'INTEREST'], true)
            || ($typ === 'INTERNAL_TRANSFER' && str_contains($sub, 'DESTINATION'))) {
            return $amount;
        }
        $sign = strtolower(trim(self::str($item['amountSign'] ?? '')));
        if (in_array($sign, ['negative', 'debit', '-', 'neg'], true)) {
            return -$amount;
        }
        if (in_array($sign, ['positive', 'credit', '+', 'pos'], true)) {
            return $amount;
        }
        $raw = $item['amount'] ?? null;
        if ($raw === null || $raw === '') {
            return 0.0;
        }

        return self::num($raw);
    }

    private static function isOption(array $item): bool
    {
        return isset($item['contractType']) && $item['contractType'] !== '' && $item['contractType'] !== null;
    }

    private static function isToClose(string $sub): bool
    {
        $c = self::compact($sub);

        return str_contains($c, 'TOCLOSE') || in_array($c, ['BTC', 'STC', 'BUYTOCLOSE', 'SELLTOCLOSE'], true);
    }

    private static function assetSymbol(array $item): string
    {
        $raw = trim(self::str($item['assetSymbol'] ?? $item['symbol'] ?? ''));
        if (str_starts_with(strtoupper($raw), 'EXCHANGE:')) {
            $raw = explode(':', $raw, 2)[1] ?? $raw;
        }

        return strtoupper(trim($raw));
    }

    private static function counterSymbol(array $item): string
    {
        $raw = trim(self::str($item['counterAssetSymbol'] ?? ''));
        if (str_starts_with(strtoupper($raw), 'EXCHANGE:')) {
            $raw = explode(':', $raw, 2)[1] ?? $raw;
        }

        return strtoupper(trim($raw));
    }

    private static function optionSymbol(array $item): string
    {
        $under = self::assetSymbol($item);
        $contract = $item['contractType'] ?? null;
        $strike = $item['strikePrice'] ?? null;
        $expiry = $item['expiryDate'] ?? null;
        if (! ($contract && $strike !== null && $expiry && $under !== '')) {
            return $under;
        }
        $ds = trim(self::str($expiry));
        if (str_contains($ds, 'T')) {
            $ds = explode('T', $ds, 2)[0];
        }
        $parts = explode('-', str_replace('/', '-', substr($ds, 0, 10)));
        if (count($parts) !== 3) {
            return $under;
        }
        $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        $day = (int) $parts[2];
        if ($month < 1 || $month > 12) {
            return $under;
        }
        $mon = $months[$month - 1];
        $yy = sprintf('%02d', $year % 100);
        $dd = sprintf('%02d', $day);
        $strikeS = number_format((float) $strike, 2, '.', '');
        $cp = strtoupper(self::str($contract));
        if (in_array($cp, ['C', 'CALL'], true)) {
            $cp = 'CALL';
        } elseif (in_array($cp, ['P', 'PUT'], true)) {
            $cp = 'PUT';
        }

        return $under.' '.$dd.$mon.$yy.' '.$strikeS.' '.$cp;
    }

    private static function typeBlob(array $item): array
    {
        $typ = strtoupper(str_replace('-', '_', self::str($item['type'] ?? '')));
        $sub = strtoupper(str_replace('-', '_', self::str($item['subType'] ?? '')));
        $parts = [];
        foreach ([$typ, $sub, $item['aftTransactionType'] ?? null, $item['aftTransactionCategory'] ?? null] as $x) {
            if ($x) {
                $parts[] = self::compact($x);
            }
        }

        return [$typ, $sub, implode('_', $parts)];
    }

    private static function isCorpShareMove(array $item): bool
    {
        [, , $blob] = self::typeBlob($item);
        foreach (self::CORP_BLOBS as $k) {
            if (str_contains($blob, $k)) {
                return true;
            }
        }
        $qty = abs(self::num($item['assetQuantity'] ?? 0));
        $cash = abs(self::num($item['amount'] ?? 0));
        $typ = strtoupper(str_replace('-', '_', self::str($item['type'] ?? '')));
        if ($qty && self::assetSymbol($item) !== '' && $cash == 0.0 && (str_contains(self::compact($typ), 'DIVIDEND') || str_contains($blob, 'DISTRIBUT'))) {
            return true;
        }

        return false;
    }

    private static function isCodeChange(array $item): bool
    {
        [, , $blob] = self::typeBlob($item);
        foreach (['CODECHANGE', 'SYMBOLCHANGE', 'TICKERCHANGE', 'LISTINGSTATUS', 'SECURITYSWAP', 'MANDATORYEXCHANGE', 'NAMECHANGE'] as $k) {
            if (str_contains($blob, $k)) {
                return true;
            }
        }

        return false;
    }

    private static function dateOnly(string $occurred): string
    {
        $s = trim($occurred);
        if ($s === '') {
            return '';
        }
        if (str_contains($s, 'T')) {
            $s = explode('T', $s, 2)[0];
        }

        return substr($s, 0, 10);
    }

    private static function compact(mixed $v): string
    {
        return preg_replace('/[\s_\-]+/', '', strtoupper(self::str($v))) ?? '';
    }

    private static function str(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        return (string) $v;
    }

    private static function num(mixed $v, float $default = 0.0): float
    {
        if ($v === null || $v === '') {
            return $default;
        }

        return (float) $v;
    }
}
