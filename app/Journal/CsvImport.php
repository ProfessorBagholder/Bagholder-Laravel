<?php

namespace App\Journal;

use App\Models\Activity;
use App\Models\Meta;
use App\Wealthsimple\ActivityMapper;
use App\Wealthsimple\SessionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wealthsimple CSV escape hatch (desktop Choose file / Import CSV).
 * Formats: canonical activities-export, legacy Date/Action/Symbol.
 */
final class CsvImport
{
    /**
     * @return array{format:string,activities:list<array>,error:?string}
     */
    public static function parse(string $text, string $sourceName = 'import.csv'): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $rows = self::parseRows($text);
        if ($rows === []) {
            return ['format' => 'unknown', 'activities' => [], 'error' => 'Empty CSV.'];
        }
        $headers = array_map(fn ($h) => self::normalizeHeader((string) $h), $rows[0]);
        $format = self::detectFormat($headers);
        if ($format === 'unknown') {
            return [
                'format' => 'unknown',
                'activities' => [],
                'error' => 'Unrecognized CSV format. Expected a Wealthsimple activities-export (canonical) or a legacy Date/Action/Symbol file.',
            ];
        }
        $map = [];
        foreach ($headers as $i => $h) {
            if ($h !== '') {
                $map[$h] = $i;
            }
        }
        $out = [];
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            if ($row === [] || self::rowEmpty($row)) {
                continue;
            }
            $act = $format === 'canonical'
                ? self::mapCanonical($row, $map, $sourceName, $i)
                : self::mapLegacy($row, $map, $sourceName, $i);
            if ($act !== null) {
                $out[] = $act;
            }
        }

        return ['format' => $format, 'activities' => $out, 'error' => null];
    }

    public const WATCH_META = 'watch_folder';

    public const WATCH_FILES_META = 'watch_files';

    public const WATCH_LAST_META = 'watch_last';

    /**
     * Merge parsed activities into the DB. Skips field-match duplicates (desktop merge_local_rows).
     *
     * @param  list<array<string,mixed>>  $activities
     * @return array{added:int,duplicates:int}
     */
    public static function mergeIntoDatabase(array $activities): array
    {
        $existingCounts = [];
        $existingIds = [];
        foreach (Activity::query()->get(['id', 'transaction_date', 'occurred_at', 'account_id', 'symbol', 'quantity', 'unit_price', 'net_cash_amount']) as $row) {
            $existingIds[(string) $row->id] = true;
            $k = self::fieldMatchKey([
                'transactionDate' => (string) $row->transaction_date,
                'occurredAt' => (string) ($row->occurred_at ?? ''),
                'accountId' => (string) ($row->account_id ?? ''),
                'symbol' => (string) ($row->symbol ?? ''),
                'quantity' => $row->quantity,
                'unitPrice' => $row->unit_price,
                'netCashAmount' => $row->net_cash_amount,
            ]);
            $existingCounts[$k] = ($existingCounts[$k] ?? 0) + 1;
        }
        $incomingSeen = [];
        $added = 0;
        $duplicates = 0;
        foreach ($activities as $row) {
            if (! is_array($row)) {
                continue;
            }
            $db = ActivityMapper::toDatabase($row);
            if ($db['id'] === '' || $db['transaction_date'] === '') {
                continue;
            }
            $db['source'] = $db['source'] ?: 'csv';
            if (isset($existingIds[$db['id']])) {
                $duplicates++;

                continue;
            }
            $k = self::fieldMatchKey($row);
            $n = ($incomingSeen[$k] ?? 0) + 1;
            $incomingSeen[$k] = $n;
            if ($n <= ($existingCounts[$k] ?? 0)) {
                $duplicates++;

                continue;
            }
            Activity::query()->create($db);
            $existingIds[$db['id']] = true;
            $existingCounts[$k] = ($existingCounts[$k] ?? 0) + 1;
            $added++;
        }
        if ($added > 0) {
            BookCache::flush();
        }

        return ['added' => $added, 'duplicates' => $duplicates];
    }

    /**
     * Parse one CSV and merge. Desktop /api/import report shape.
     *
     * @return array{ok:bool,file:string,format:string,rows:int,added:int,duplicates:int,error:?string}
     */
    public static function importText(string $name, string $text): array
    {
        $file = basename(str_replace('\\', '/', $name)) ?: 'import.csv';
        $parsed = self::parse($text, $file);
        if (($parsed['error'] ?? null) !== null) {
            return [
                'ok' => false,
                'file' => $file,
                'format' => (string) ($parsed['format'] ?? 'unknown'),
                'rows' => 0,
                'added' => 0,
                'duplicates' => 0,
                'error' => (string) $parsed['error'],
            ];
        }
        $acts = $parsed['activities'] ?? [];
        $merged = $acts === [] ? ['added' => 0, 'duplicates' => 0] : self::mergeIntoDatabase($acts);

        return [
            'ok' => true,
            'file' => $file,
            'format' => (string) ($parsed['format'] ?? 'unknown'),
            'rows' => count($acts),
            'added' => (int) ($merged['added'] ?? 0),
            'duplicates' => (int) ($merged['duplicates'] ?? 0),
            'error' => null,
        ];
    }

    /**
     * Hand-entered trade (desktop /api/book/append).
     *
     * @param  array<string,mixed>  $fields
     * @return array{ok:bool,added:int,duplicates:int,error:?string}
     */
    public static function appendManual(array $fields): array
    {
        $side = strtoupper(trim((string) ($fields['side'] ?? 'BUY')));
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            $side = 'BUY';
        }
        $symbol = strtoupper(trim((string) ($fields['symbol'] ?? '')));
        $date = self::parseDate((string) ($fields['date'] ?? $fields['transactionDate'] ?? ''));
        $qty = abs(self::parseNumber((string) ($fields['qty'] ?? $fields['quantity'] ?? '')));
        $px = abs(self::parseNumber((string) ($fields['price'] ?? $fields['unitPrice'] ?? '')));
        $fees = abs(self::parseNumber((string) ($fields['fees'] ?? $fields['commission'] ?? '0')));
        $ccy = strtoupper(trim((string) ($fields['currency'] ?? 'CAD'))) ?: 'CAD';
        if (! in_array($ccy, ['CAD', 'USD'], true)) {
            $ccy = 'CAD';
        }
        if ($date === '' || $symbol === '' || $qty <= 0.0 || $px < 0.0) {
            return ['ok' => false, 'added' => 0, 'duplicates' => 0, 'error' => 'Date, symbol, a positive quantity and a price are required.'];
        }
        $accountId = trim((string) ($fields['accountId'] ?? $fields['account'] ?? 'manual')) ?: 'manual';
        $accountType = trim((string) ($fields['accountType'] ?? ($accountId === 'manual' ? 'Manual' : '')));
        $signedQty = $side === 'BUY' ? $qty : -$qty;
        $cash = $side === 'BUY' ? -($qty * $px) : ($qty * $px);
        $idSeed = implode('|', [$date, $side, $symbol, (string) $qty, (string) $px, (string) $fees, $accountId, $ccy]);
        $id = 'manual:'.substr(hash('xxh128', $idSeed), 0, 24);
        $row = [
            'id' => $id,
            'canonicalId' => $id,
            'occurredAt' => $date.'T12:00:00Z',
            'transactionDate' => $date,
            'settlementDate' => $date,
            'accountId' => $accountId,
            'bookId' => $accountId,
            'fifoId' => $accountId,
            'accountType' => $accountType,
            'activityType' => 'Trade',
            'activitySubType' => $side,
            'description' => ($side === 'BUY' ? 'Buy' : 'Sell').' '.$qty.' '.$symbol.' @ '.$px,
            'direction' => $side === 'BUY' ? 'DEBIT' : 'CREDIT',
            'symbol' => $symbol,
            'name' => $symbol,
            'currency' => $ccy,
            'quantity' => $signedQty,
            'unitPrice' => $px,
            'commission' => $fees,
            'netCashAmount' => $cash,
            'category' => 'trade',
            'source' => 'manual',
            'rawType' => $side,
        ];
        $merged = self::mergeIntoDatabase([$row]);

        return [
            'ok' => true,
            'added' => (int) ($merged['added'] ?? 0),
            'duplicates' => (int) ($merged['duplicates'] ?? 0),
            'error' => null,
        ];
    }

    /**
     * Desktop Export trades CSV columns from decorated closed trades.
     *
     * @param  list<array<string,mixed>>  $trades
     */
    public static function exportTradesCsv(array $trades): string
    {
        $cols = ['Open', 'Close', 'Symbol', 'Name', 'Account', 'Kind', 'Side', 'Status', 'Qty', 'Entry', 'Exit', 'Currency', 'P&L', 'P&L CAD', 'Fees', 'Hold days', 'Grade', 'Tags', 'Thesis'];
        $lines = [self::csvLine($cols)];
        foreach ($trades as $t) {
            if (! is_array($t)) {
                continue;
            }
            $pnl = (float) ($t['pnl'] ?? 0);
            $pnlCad = (float) ($t['pnlCad'] ?? $pnl);
            $fees = (float) ($t['commission'] ?? $t['fees'] ?? 0);
            $qty = (float) ($t['quantity'] ?? $t['qty'] ?? 0);
            $entry = (float) ($t['entryPrice'] ?? $t['entry'] ?? 0);
            $exit = (float) ($t['exitPrice'] ?? $t['exit'] ?? 0);
            $tags = $t['tag'] ?? $t['tags'] ?? '';
            if (is_array($tags)) {
                $tags = implode('; ', $tags);
            }
            $lines[] = self::csvLine([
                substr((string) ($t['entryDate'] ?? ''), 0, 10),
                substr((string) ($t['exitDate'] ?? ''), 0, 10),
                (string) ($t['displaySymbol'] ?? $t['symbol'] ?? ''),
                (string) ($t['name'] ?? ''),
                (string) ($t['accountType'] ?? $t['account'] ?? ''),
                (string) ($t['kind'] ?? ''),
                (string) ($t['displaySide'] ?? $t['side'] ?? ''),
                (string) ($t['status'] ?? 'closed'),
                self::fmtNum($qty),
                self::fmtNum($entry),
                self::fmtNum($exit),
                (string) ($t['currency'] ?? ''),
                number_format($pnl, 2, '.', ''),
                number_format($pnlCad, 2, '.', ''),
                number_format($fees, 2, '.', ''),
                (string) (int) ($t['holdDays'] ?? 0),
                (string) ($t['grade'] ?? ''),
                (string) $tags,
                (string) ($t['thesis'] ?? $t['notes'] ?? ''),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Wipe journal (+ optional session) like desktop Clear data.
     *
     * @return array{ok:bool,activities:int}
     */
    public static function clearData(bool $session = true, bool $journal = true): array
    {
        $n = (int) Activity::query()->count();
        Activity::query()->delete();
        foreach (['accounts', 'balances', 'nav_history', 'securities'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        $metaKeys = [
            'synced_at', 'ws_synced_at', 'ws_sync_step', 'ws_sync_error', 'ws_sync_phase',
            'trade_groups', 'trade_notes',
            self::WATCH_META, self::WATCH_FILES_META, self::WATCH_LAST_META,
        ];
        if ($journal) {
            $metaKeys[] = 'journal_v2';
        }
        foreach ($metaKeys as $key) {
            DB::table('meta')->where('key', $key)->delete();
        }
        if ($session) {
            SessionStore::forget();
        }
        BookCache::flush();

        return ['ok' => true, 'activities' => $n];
    }

    /**
     * @return array{ok:bool,path:string,watching:bool,lastScan:string,files:list<array<string,mixed>>,error:?string}
     */
    public static function watchStatus(): array
    {
        $path = (string) Meta::getValue(self::WATCH_META, '');
        $seen = Meta::json(self::WATCH_FILES_META, []);
        $files = [];
        if (is_array($seen)) {
            foreach ($seen as $k => $v) {
                $rec = is_array($v) ? $v : [];
                $files[] = [
                    'file' => basename((string) $k),
                    'added' => (int) ($rec['added'] ?? 0),
                    'duplicates' => (int) ($rec['duplicates'] ?? 0),
                    'format' => (string) ($rec['format'] ?? ''),
                    'scannedAt' => (string) ($rec['scannedAt'] ?? ''),
                ];
            }
        }

        return [
            'ok' => true,
            'path' => $path,
            'watching' => $path !== '',
            'lastScan' => (string) Meta::getValue(self::WATCH_LAST_META, ''),
            'files' => $files,
            'error' => null,
        ];
    }

    /**
     * @return array{ok:bool,path:string,error:?string}
     */
    public static function setWatchFolder(string $path): array
    {
        $p = self::expandPath($path);
        if ($p === '') {
            return ['ok' => false, 'path' => '', 'error' => 'Folder path required'];
        }
        if (! is_dir($p)) {
            return ['ok' => false, 'path' => $p, 'error' => 'Not a folder: '.$p];
        }
        Meta::putValue(self::WATCH_META, $p);

        return ['ok' => true, 'path' => $p, 'error' => null];
    }

    public static function clearWatchFolder(): void
    {
        Meta::putValue(self::WATCH_META, '');
        Meta::putValue(self::WATCH_FILES_META, '');
        Meta::putValue(self::WATCH_LAST_META, '');
    }

    /**
     * Import every top-level CSV in the watched folder. Unchanged files skipped unless $force.
     *
     * @return array{ok:bool,path:string,added:int,duplicates:int,files:list<array<string,mixed>>,error:?string}
     */
    public static function scanWatchFolder(bool $force = false, ?string $folder = null): array
    {
        $path = self::expandPath($folder ?? (string) Meta::getValue(self::WATCH_META, ''));
        if ($path === '') {
            return ['ok' => false, 'path' => '', 'added' => 0, 'duplicates' => 0, 'files' => [], 'error' => 'No folder is being watched'];
        }
        if (! is_dir($path)) {
            return ['ok' => false, 'path' => $path, 'added' => 0, 'duplicates' => 0, 'files' => [], 'error' => 'Folder not found: '.$path];
        }
        $seen = Meta::json(self::WATCH_FILES_META, []);
        if (! is_array($seen)) {
            $seen = [];
        }
        $files = [];
        $added = 0;
        $duplicates = 0;
        foreach (self::listCsvFiles($path) as $f) {
            $prev = $seen[$f['path']] ?? [];
            if (! is_array($prev)) {
                $prev = [];
            }
            if (! $force && (int) ($prev['size'] ?? -1) === $f['size'] && (int) ($prev['mtime'] ?? -1) === $f['mtime']) {
                $files[] = [
                    'file' => $f['name'],
                    'unchanged' => true,
                    'added' => (int) ($prev['added'] ?? 0),
                    'duplicates' => (int) ($prev['duplicates'] ?? 0),
                    'format' => (string) ($prev['format'] ?? ''),
                ];

                continue;
            }
            $text = @file_get_contents($f['path']);
            if ($text === false) {
                $files[] = ['file' => $f['name'], 'error' => 'Could not read file'];

                continue;
            }
            $rep = self::importText($f['name'], $text);
            $added += (int) ($rep['added'] ?? 0);
            $duplicates += (int) ($rep['duplicates'] ?? 0);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $seen[$f['path']] = [
                'size' => $f['size'],
                'mtime' => $f['mtime'],
                'added' => (int) ($rep['added'] ?? 0),
                'duplicates' => (int) ($rep['duplicates'] ?? 0),
                'format' => (string) ($rep['format'] ?? ''),
                'scannedAt' => $now,
            ];
            $files[] = [
                'file' => $f['name'],
                'unchanged' => false,
                'added' => (int) ($rep['added'] ?? 0),
                'duplicates' => (int) ($rep['duplicates'] ?? 0),
                'format' => (string) ($rep['format'] ?? ''),
                'rows' => (int) ($rep['rows'] ?? 0),
                'error' => $rep['error'] ?? null,
            ];
        }
        $keep = [];
        foreach ($seen as $k => $v) {
            if (is_string($k) && is_file($k)) {
                $keep[$k] = $v;
            }
        }
        Meta::putValue(self::WATCH_FILES_META, json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Meta::putValue(self::WATCH_LAST_META, gmdate('Y-m-d\TH:i:s\Z'));

        return ['ok' => true, 'path' => $path, 'added' => $added, 'duplicates' => $duplicates, 'files' => $files, 'error' => null];
    }

    /**
     * @return list<array{path:string,name:string,size:int,mtime:int}>
     */
    public static function listCsvFiles(string $folder): array
    {
        $out = [];
        $entries = @scandir($folder);
        if (! is_array($entries)) {
            return $out;
        }
        sort($entries);
        foreach ($entries as $n) {
            if ($n === '.' || $n === '..' || str_starts_with($n, '._') || ! str_ends_with(strtolower($n), '.csv')) {
                continue;
            }
            $p = $folder.DIRECTORY_SEPARATOR.$n;
            if (! is_file($p)) {
                continue;
            }
            $st = @stat($p);
            if (! is_array($st) || (int) $st['size'] === 0) {
                continue;
            }
            $out[] = ['path' => $p, 'name' => $n, 'size' => (int) $st['size'], 'mtime' => (int) $st['mtime']];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $act
     */
    public static function fieldMatchKey(array $act): string
    {
        $date = substr((string) ($act['transactionDate'] ?? $act['transaction_date'] ?? ''), 0, 10);
        if ($date === '') {
            $date = substr((string) ($act['occurredAt'] ?? $act['occurred_at'] ?? ''), 0, 10);
        }
        $aid = trim((string) ($act['accountId'] ?? $act['account_id'] ?? ''));
        $account = self::isRealAccount($aid) ? $aid : '';

        return implode('|', [
            $date,
            $account,
            strtoupper(trim((string) ($act['symbol'] ?? ''))),
            self::roundQty($act['quantity'] ?? 0),
            self::roundQty($act['unitPrice'] ?? $act['unit_price'] ?? 0),
            self::roundQty($act['netCashAmount'] ?? $act['net_cash_amount'] ?? 0),
        ]);
    }

    public static function isRealAccount(string $accountId): bool
    {
        $s = trim($accountId);
        if ($s === '' || str_starts_with($s, '~')) {
            return false;
        }

        return ! in_array(strtolower($s), ['manual', 'legacy', 'statement', 'canonical', 'cad', 'usd', 'csv'], true);
    }

    private static function expandPath(string $path): string
    {
        $p = trim($path);
        if ($p === '') {
            return '';
        }
        if (str_starts_with($p, '~')) {
            $home = getenv('HOME') ?: '/home/box';
            $p = $home.substr($p, 1);
        }
        $real = realpath($p);

        return is_string($real) ? $real : $p;
    }

    private static function roundQty(mixed $n): string
    {
        return number_format((float) $n, 8, '.', '');
    }

    private static function fmtNum(float $n): string
    {
        if (abs($n - round($n)) < 0.0000001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 8, '.', ''), '0'), '.');
    }

    /**
     * @param  list<string>  $cols
     */
    private static function csvLine(array $cols): string
    {
        return implode(',', array_map(function ($v) {
            $s = (string) $v;
            if (str_contains($s, '"') || str_contains($s, ',') || str_contains($s, "\n")) {
                return '"'.str_replace('"', '""', $s).'"';
            }

            return $s;
        }, $cols));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private static function parseRows(string $text): array
    {
        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($inQuotes) {
                if ($c === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $c;
                }
            } elseif ($c === '"') {
                $inQuotes = true;
            } elseif ($c === ',') {
                $row[] = $field;
                $field = '';
            } elseif ($c === "\n") {
                $row[] = $field;
                $field = '';
                $rows[] = $row;
                $row = [];
            } elseif ($c === "\r") {
                if ($i + 1 < $len && $text[$i + 1] === "\n") {
                    continue;
                }
                $row[] = $field;
                $field = '';
                $rows[] = $row;
                $row = [];
            } else {
                $field .= $c;
            }
        }
        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }
        if ($rows !== [] && count(array_filter($rows[count($rows) - 1], fn ($v) => trim((string) $v) !== '')) === 0) {
            array_pop($rows);
        }

        return $rows;
    }

    private static function normalizeHeader(string $h): string
    {
        $s = trim(str_replace("\u{FEFF}", '', $h));
        $s = trim($s, "\"' \t");
        $s = strtolower($s);

        return preg_replace('/[\s\-]+/', '_', $s) ?? $s;
    }

    /**
     * @param  list<string>  $headers
     */
    private static function detectFormat(array $headers): string
    {
        $norms = array_fill_keys($headers, true);
        $canonHints = ['transaction_date', 'activity_type', 'activity_sub_type', 'net_cash_amount', 'unit_price'];
        $legacyHints = ['date', 'action', 'symbol', 'quantity', 'price', 'amount'];
        $canonHits = count(array_filter($canonHints, fn ($h) => isset($norms[$h])));
        $legacyHits = count(array_filter($legacyHints, fn ($h) => isset($norms[$h])));
        if ($canonHits >= 3 || isset($norms['transaction_date']) || isset($norms['activity_type'])) {
            return 'canonical';
        }
        if ($legacyHits >= 5 || (isset($norms['action']) && isset($norms['date']))) {
            return 'legacy';
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $row
     * @param  array<string,int>  $map
     */
    private static function mapCanonical(array $row, array $map, string $sourceName, int $line): ?array
    {
        $get = fn (string ...$keys): string => self::pick($row, $map, ...$keys);
        $date = self::parseDate($get('transaction_date', 'date'));
        if ($date === '') {
            return null;
        }
        $type = $get('activity_type', 'type');
        $sub = strtoupper($get('activity_sub_type', 'sub_type', 'action'));
        $symbol = strtoupper(trim($get('symbol')));
        $qty = self::parseNumber($get('quantity'));
        $px = self::parseNumber($get('unit_price', 'price'));
        $cash = self::parseNumber($get('net_cash_amount', 'amount'));
        $ccy = strtoupper($get('currency') ?: 'CAD');
        $accountId = $get('account_id') ?: 'csv';
        $accountType = $get('account_type') ?: '';
        $desc = $get('description');
        $idSeed = implode('|', [$date, $type, $sub, $symbol, (string) $qty, (string) $px, (string) $cash, $accountId, $sourceName, (string) $line]);
        $id = 'csv:'.substr(hash('xxh128', $idSeed), 0, 24);
        $category = self::categorize($type, $sub);

        return [
            'id' => $id,
            'canonicalId' => $id,
            'occurredAt' => $date.'T12:00:00Z',
            'transactionDate' => $date,
            'settlementDate' => self::parseDate($get('settlement_date')) ?: $date,
            'accountId' => $accountId,
            'bookId' => $accountId,
            'fifoId' => $accountId,
            'accountType' => $accountType,
            'activityType' => $type !== '' ? $type : 'Trade',
            'activitySubType' => $sub !== '' ? $sub : null,
            'description' => $desc,
            'direction' => $get('direction') ?: ($cash < 0 ? 'DEBIT' : 'CREDIT'),
            'symbol' => $symbol,
            'name' => $get('name') ?: $symbol,
            'currency' => $ccy,
            'quantity' => $qty,
            'unitPrice' => $px,
            'commission' => self::parseNumber($get('commission')),
            'netCashAmount' => $cash,
            'category' => $category,
            'source' => 'csv',
            'rawType' => $sub ?: $type,
        ];
    }

    /**
     * @param  list<string>  $row
     * @param  array<string,int>  $map
     */
    private static function mapLegacy(array $row, array $map, string $sourceName, int $line): ?array
    {
        $get = fn (string ...$keys): string => self::pick($row, $map, ...$keys);
        $date = self::parseDate($get('date'));
        if ($date === '') {
            return null;
        }
        $action = strtoupper(trim($get('action')));
        $symbol = strtoupper(trim($get('symbol')));
        $qty = self::parseNumber($get('quantity'));
        $px = self::parseNumber($get('price'));
        $cash = self::parseNumber($get('amount'));
        $ccy = strtoupper($get('currency') ?: 'CAD');
        $desc = $get('description');
        $side = match (true) {
            in_array($action, ['BUY', 'B'], true) => 'BUY',
            in_array($action, ['SELL', 'S'], true) => 'SELL',
            default => $action,
        };
        if (in_array($side, ['BUY', 'SELL'], true) && $qty != 0.0) {
            $qty = $side === 'SELL' ? -abs($qty) : abs($qty);
        }
        if (in_array($side, ['BUY', 'SELL'], true) && $cash == 0.0 && $px != 0.0) {
            $cash = $side === 'SELL' ? abs($qty * $px) : -abs($qty * $px);
        }
        $category = match (true) {
            in_array($side, ['BUY', 'SELL', 'SELLTOOPEN', 'BUYTOCLOSE', 'BUYTOOPEN', 'SELLTOCLOSE'], true) => 'trade',
            str_contains(strtolower($action), 'dividend') => 'dividend',
            str_contains(strtolower($action), 'interest') => 'interest',
            str_contains(strtolower($action), 'fee') => 'fee',
            default => 'other',
        };
        $idSeed = implode('|', [$date, $side, $symbol, (string) $qty, (string) $px, (string) $cash, $sourceName, (string) $line]);
        $id = 'csv:'.substr(hash('xxh128', $idSeed), 0, 24);
        $accountId = 'manual';

        return [
            'id' => $id,
            'canonicalId' => $id,
            'occurredAt' => $date.'T12:00:00Z',
            'transactionDate' => $date,
            'settlementDate' => $date,
            'accountId' => $accountId,
            'bookId' => $accountId,
            'fifoId' => $accountId,
            'accountType' => 'Manual',
            'activityType' => $category === 'trade' ? 'Trade' : $action,
            'activitySubType' => $side,
            'description' => $desc !== '' ? $desc : ($side.' '.$symbol),
            'direction' => $cash < 0 ? 'DEBIT' : 'CREDIT',
            'symbol' => $symbol,
            'name' => $desc !== '' ? $desc : $symbol,
            'currency' => $ccy,
            'quantity' => $qty,
            'unitPrice' => $px,
            'commission' => 0,
            'netCashAmount' => $cash,
            'category' => $category,
            'source' => 'csv',
            'rawType' => $side,
        ];
    }

    /**
     * @param  list<string>  $row
     * @param  array<string,int>  $map
     */
    private static function pick(array $row, array $map, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($map[$key]) && array_key_exists($map[$key], $row)) {
                $v = trim((string) $row[$map[$key]]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    private static function parseNumber(string $raw): float
    {
        $s = trim($raw);
        if ($s === '' || $s === '-' || $s === '—' || strtolower($s) === 'n/a') {
            return 0.0;
        }
        $paren = (bool) preg_match('/^\(.*\)$/', $s);
        $s = preg_replace('/[()]/', '', $s) ?? $s;
        $s = preg_replace('/[$£€CADUSDcadusd,\s]/', '', $s) ?? $s;
        if ($s === '' || ! is_numeric($s)) {
            return 0.0;
        }
        $n = (float) $s;

        return $paren ? -abs($n) : $n;
    }

    private static function parseDate(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/', $s, $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $s, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = $m[3];
            if ($a > 12 && $b <= 12) {
                return sprintf('%s-%02d-%02d', $y, $b, $a);
            }

            return sprintf('%s-%02d-%02d', $y, $a, $b);
        }

        return '';
    }

    private static function categorize(string $type, string $sub): string
    {
        $t = strtolower(preg_replace('/[\s_\-]/', '', $type) ?? $type);
        $s = strtolower(preg_replace('/[\s_\-]/', '', $sub) ?? $sub);
        if (str_contains($t, 'dividend') || str_contains($s, 'dividend')) {
            return 'dividend';
        }
        if (str_contains($t, 'interest') || str_contains($s, 'interest')) {
            return 'interest';
        }
        if ($t === 'fee' || str_contains($t, 'fee') || $s === 'fee') {
            return 'fee';
        }
        if ($t === 'trade' || $s === 'buy' || $s === 'sell' || str_contains($s, 'buy') || str_contains($s, 'sell')) {
            return 'trade';
        }
        if (str_contains($t, 'deposit') || str_contains($s, 'deposit')) {
            return 'deposit';
        }
        if (str_contains($t, 'withdraw') || str_contains($s, 'withdraw')) {
            return 'withdrawal';
        }

        return 'other';
    }

    /**
     * @param  list<string>  $row
     */
    private static function rowEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
