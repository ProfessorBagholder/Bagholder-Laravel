<?php

namespace App\Journal;

use App\Models\Meta;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Read-only import from the desktop Bagholder sqlite (~/.bagholder/bagholder.db)
 * into Laravel market tables + Meta JSON mirrors used by Snapshot/Cashflow/Charts.
 * Never invents prices.
 */
final class MarketImport
{
    public static function defaultPath(): string
    {
        $home = getenv('HOME') ?: (($_SERVER['HOME'] ?? '') ?: '');
        if ($home === '') {
            $home = '/Users/md';
        }

        return rtrim($home, '/').'/.bagholder/bagholder.db';
    }

    /**
     * @return array{
     *   path: string,
     *   quotes: int,
     *   distributions: int,
     *   price_bars: int,
     *   benchmark_prices: int,
     *   spy: int,
     *   trade_notes: int,
     *   exposures: int,
     *   orders: int,
     *   brackets: int,
     *   ok: bool,
     *   error?: string
     * }
     */
    public static function import(?string $path = null, bool $bars = true): array
    {
        $path = $path ?: self::defaultPath();
        $out = [
            'path' => $path,
            'quotes' => 0,
            'distributions' => 0,
            'price_bars' => 0,
            'benchmark_prices' => 0,
            'spy' => 0,
            'trade_notes' => 0,
            'exposures' => 0,
            'orders' => 0,
            'brackets' => 0,
            'ok' => false,
        ];
        if (! is_file($path)) {
            $out['error'] = 'Desktop DB not found: '.$path;

            return $out;
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();

            return $out;
        }

        self::ensureTables();

        $quotes = [];
        foreach ($pdo->query('SELECT * FROM quotes') as $r) {
            $sym = (string) ($r['symbol'] ?? '');
            if ($sym === '') {
                continue;
            }
            $rec = [
                'price' => isset($r['price']) ? (float) $r['price'] : null,
                'priceChange' => isset($r['price_change']) ? (float) $r['price_change'] : null,
                'percentChange' => isset($r['percent_change']) ? (float) $r['percent_change'] : null,
                'prevClose' => isset($r['prev_close']) ? (float) $r['prev_close'] : null,
                'dividendAmount' => isset($r['dividend_amount']) ? (float) $r['dividend_amount'] : null,
                'dividendFrequency' => (string) ($r['dividend_frequency'] ?? ''),
                'exDividendDate' => (string) ($r['ex_dividend_date'] ?? ''),
                'source' => (string) ($r['source'] ?? ''),
                'fetchedAt' => (string) ($r['fetched_at'] ?? ''),
            ];
            $quotes[$sym] = $rec;
            DB::table('quotes')->updateOrInsert(
                ['symbol' => $sym],
                [
                    'price' => $rec['price'],
                    'price_change' => $rec['priceChange'],
                    'percent_change' => $rec['percentChange'],
                    'prev_close' => $rec['prevClose'],
                    'dividend_amount' => $rec['dividendAmount'],
                    'dividend_frequency' => $rec['dividendFrequency'],
                    'ex_dividend_date' => $rec['exDividendDate'],
                    'source' => $rec['source'],
                    'fetched_at' => $rec['fetchedAt'],
                ],
            );
        }
        Meta::putValue('quotes', json_encode($quotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $out['quotes'] = count($quotes);

        $dists = [];
        DB::table('distributions')->delete();
        $distRows = [];
        foreach ($pdo->query('SELECT * FROM distributions ORDER BY symbol, ex_date DESC') as $r) {
            $sym = (string) ($r['symbol'] ?? '');
            if ($sym === '') {
                continue;
            }
            $ex = (string) ($r['ex_date'] ?? '');
            $amt = isset($r['amount']) ? (float) $r['amount'] : 0.0;
            if ($ex === '' || $amt <= 0) {
                continue;
            }
            $item = [
                'exDate' => $ex,
                'payDate' => (string) ($r['pay_date'] ?? ''),
                'amount' => $amt,
                'currency' => (string) ($r['currency'] ?? ''),
            ];
            $dists[$sym][] = $item;
            $distRows[] = [
                'symbol' => $sym,
                'ex_date' => $ex,
                'pay_date' => $item['payDate'] !== '' ? $item['payDate'] : null,
                'amount' => $amt,
                'currency' => $item['currency'] !== '' ? $item['currency'] : null,
                'source' => (string) ($r['source'] ?? 'tmx') ?: 'tmx',
            ];
        }
        foreach (array_chunk($distRows, 500) as $chunk) {
            DB::table('distributions')->insertOrIgnore($chunk);
        }
        Meta::putValue('distributions', json_encode($dists, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $out['distributions'] = array_sum(array_map('count', $dists));

        $benchCount = 0;
        $spy = [];
        $benchmarks = [];
        DB::table('benchmark_prices')->delete();
        $benchRows = [];
        foreach ($pdo->query('SELECT symbol, date, close FROM benchmark_prices ORDER BY symbol, date') as $r) {
            $sym = (string) ($r['symbol'] ?? '');
            $date = (string) ($r['date'] ?? '');
            $close = isset($r['close']) ? (float) $r['close'] : 0.0;
            if ($sym === '' || $date === '' || ! ($close > 0)) {
                continue;
            }
            $benchmarks[$sym][$date] = $close;
            if ($sym === 'SP500' || $sym === 'SPY') {
                $spy[$date] = $close;
            }
            $benchRows[] = ['symbol' => $sym, 'date' => $date, 'close' => $close];
            $benchCount++;
        }
        foreach (array_chunk($benchRows, 1000) as $chunk) {
            DB::table('benchmark_prices')->insertOrIgnore($chunk);
        }
        if ($spy !== []) {
            Meta::putValue('spy_by_date', json_encode($spy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        Meta::putValue('benchmarks', json_encode([
            'SP500' => $benchmarks['SP500'] ?? $spy,
            'TSX' => $benchmarks['TSX'] ?? [],
            'TSX60' => $benchmarks['TSX60'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $out['benchmark_prices'] = $benchCount;
        $out['spy'] = count($spy);

        if ($bars) {
            DB::table('price_bars')->delete();
            $barN = 0;
            $stmt = $pdo->query('SELECT symbol, tf, ts, open, high, low, close, volume, source FROM price_bars');
            $chunk = [];
            while ($r = $stmt->fetch()) {
                $sym = (string) ($r['symbol'] ?? '');
                $tf = (string) ($r['tf'] ?? '');
                $ts = (int) ($r['ts'] ?? 0);
                if ($sym === '' || $tf === '' || $ts <= 0) {
                    continue;
                }
                $chunk[] = [
                    'symbol' => $sym,
                    'tf' => $tf,
                    'ts' => $ts,
                    'open' => $r['open'] !== null ? (float) $r['open'] : null,
                    'high' => $r['high'] !== null ? (float) $r['high'] : null,
                    'low' => $r['low'] !== null ? (float) $r['low'] : null,
                    'close' => (float) $r['close'],
                    'volume' => $r['volume'] !== null ? (float) $r['volume'] : null,
                    'source' => (string) ($r['source'] ?? ''),
                ];
                if (count($chunk) >= 1000) {
                    DB::table('price_bars')->insertOrIgnore($chunk);
                    $barN += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table('price_bars')->insertOrIgnore($chunk);
                $barN += count($chunk);
            }
            $out['price_bars'] = $barN;
            // Lightweight presence flag — full bars live in the table, not Meta JSON.
            Meta::putValue('price_bars_imported_at', gmdate('c'));
            Meta::putValue('price_bars_count', (string) $barN);
        }

        // Sector / country exposure records (Portfolio Sectors + Regions).
        $exposures = [];
        try {
            foreach ($pdo->query('SELECT key, sectors, countries, coverage, source, as_of, industry, error, fetched_at FROM exposures') as $r) {
                $key = (string) ($r['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $sectors = json_decode((string) ($r['sectors'] ?? '{}'), true);
                $countries = json_decode((string) ($r['countries'] ?? '{}'), true);
                $exposures[$key] = [
                    'sectors' => is_array($sectors) ? $sectors : [],
                    'countries' => is_array($countries) ? $countries : [],
                    'coverage' => isset($r['coverage']) ? (float) $r['coverage'] : 0.0,
                    'source' => (string) ($r['source'] ?? ''),
                    'asOf' => (string) ($r['as_of'] ?? ''),
                    'industry' => (string) ($r['industry'] ?? ''),
                    'error' => (string) ($r['error'] ?? ''),
                    'fetchedAt' => (string) ($r['fetched_at'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // exposures table optional on older desktop DBs
        }
        Meta::putValue('exposures', json_encode($exposures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $out['exposures'] = count($exposures);

        // Orders + brackets (Orders panel). Read-only mirror of desktop store.
        self::ensureOrdersTables();
        $orderN = 0;
        $bracketN = 0;
        try {
            DB::table('orders')->delete();
            $chunk = [];
            foreach ($pdo->query('SELECT * FROM orders') as $r) {
                $chunk[] = [
                    'id' => (string) ($r['id'] ?? ''),
                    'created_at' => (string) ($r['created_at'] ?? ''),
                    'account_id' => (string) ($r['account_id'] ?? ''),
                    'account' => (string) ($r['account'] ?? ''),
                    'security_id' => (string) ($r['security_id'] ?? ''),
                    'symbol' => (string) ($r['symbol'] ?? ''),
                    'currency' => (string) ($r['currency'] ?? ''),
                    'side' => (string) ($r['side'] ?? ''),
                    'type' => (string) ($r['type'] ?? ''),
                    'quantity' => isset($r['quantity']) ? (float) $r['quantity'] : 0.0,
                    'limit_price' => $r['limit_price'] !== null ? (float) $r['limit_price'] : null,
                    'stop_price' => $r['stop_price'] !== null ? (float) $r['stop_price'] : null,
                    'tif' => (string) ($r['tif'] ?? ''),
                    'stop_loss' => $r['stop_loss'] ?? null,
                    'take_profit' => $r['take_profit'] ?? null,
                    'status' => (string) ($r['status'] ?? ''),
                    'ws_order_id' => (string) ($r['ws_order_id'] ?? ''),
                    'error' => (string) ($r['error'] ?? ''),
                    'request' => $r['request'] ?? null,
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                    'source' => (string) ($r['source'] ?? 'bagholder'),
                    'ws_status' => (string) ($r['ws_status'] ?? ''),
                    'filled_qty' => $r['filled_qty'] !== null ? (float) $r['filled_qty'] : null,
                    'avg_fill' => $r['avg_fill'] !== null ? (float) $r['avg_fill'] : null,
                    'submitted_at' => (string) ($r['submitted_at'] ?? ''),
                    'expires_at' => (string) ($r['expires_at'] ?? ''),
                    'parent_id' => (string) ($r['parent_id'] ?? ''),
                    'role' => (string) ($r['role'] ?? 'entry') ?: 'entry',
                    'exchange' => null,
                ];
                if (count($chunk) >= 200) {
                    DB::table('orders')->insert($chunk);
                    $orderN += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table('orders')->insert($chunk);
                $orderN += count($chunk);
            }
            // Enrich exchange from securities
            $ex = [];
            try {
                foreach ($pdo->query('SELECT id, primary_exchange FROM securities') as $s) {
                    $ex[(string) $s['id']] = (string) ($s['primary_exchange'] ?? '');
                }
            } catch (\Throwable $e) {
            }
            if ($ex !== []) {
                foreach (DB::table('orders')->select('id', 'security_id')->get() as $row) {
                    $exc = $ex[(string) $row->security_id] ?? '';
                    if ($exc !== '') {
                        DB::table('orders')->where('id', $row->id)->update(['exchange' => $exc]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // orders optional on older DBs
        }
        $out['orders'] = $orderN;

        try {
            DB::table('brackets')->delete();
            $chunk = [];
            foreach ($pdo->query('SELECT * FROM brackets') as $r) {
                $chunk[] = [
                    'id' => (string) ($r['id'] ?? ''),
                    'order_id' => (string) ($r['order_id'] ?? ''),
                    'created_at' => (string) ($r['created_at'] ?? ''),
                    'account_id' => (string) ($r['account_id'] ?? ''),
                    'security_id' => (string) ($r['security_id'] ?? ''),
                    'symbol' => (string) ($r['symbol'] ?? ''),
                    'currency' => (string) ($r['currency'] ?? ''),
                    'quantity' => $r['quantity'] !== null ? (float) $r['quantity'] : null,
                    'tif' => (string) ($r['tif'] ?? ''),
                    'sl_kind' => (string) ($r['sl_kind'] ?? ''),
                    'sl_price' => $r['sl_price'] !== null ? (float) $r['sl_price'] : null,
                    'sl_trail' => $r['sl_trail'] !== null ? (float) $r['sl_trail'] : null,
                    'sl_trail_unit' => (string) ($r['sl_trail_unit'] ?? ''),
                    'sl_order_id' => (string) ($r['sl_order_id'] ?? ''),
                    'sl_native' => isset($r['sl_native']) ? (int) $r['sl_native'] : null,
                    'sl_mode' => (string) ($r['sl_mode'] ?? ''),
                    'high_water' => $r['high_water'] !== null ? (float) $r['high_water'] : null,
                    'tp_price' => $r['tp_price'] !== null ? (float) $r['tp_price'] : null,
                    'tp_order_id' => (string) ($r['tp_order_id'] ?? ''),
                    'status' => (string) ($r['status'] ?? ''),
                    'outcome' => (string) ($r['outcome'] ?? ''),
                    'error' => (string) ($r['error'] ?? ''),
                    'attempts' => isset($r['attempts']) ? (int) $r['attempts'] : null,
                    'moved_at' => (string) ($r['moved_at'] ?? ''),
                    'armed_at' => (string) ($r['armed_at'] ?? ''),
                    'seen_held' => isset($r['seen_held']) ? (int) $r['seen_held'] : null,
                    'missed_at' => (string) ($r['missed_at'] ?? ''),
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                ];
                if (count($chunk) >= 200) {
                    DB::table('brackets')->insert($chunk);
                    $bracketN += count($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table('brackets')->insert($chunk);
                $bracketN += count($chunk);
            }
        } catch (\Throwable $e) {
            // brackets optional
        }
        $out['brackets'] = $bracketN;

        // Optional journal notes from desktop meta.

        // Desktop activity ids are UUIDs; Laravel sync uses Wealthsimple order-* ids.
        // Remap rt:<desktopId> → rt:<laravelId> via opening-fill fingerprint so grades attach.
        try {
            $incoming = [];
            $row = $pdo->query("SELECT value FROM meta WHERE key = 'trade_notes'")->fetch();
            if ($row && ! empty($row['value'])) {
                $decoded = json_decode((string) $row['value'], true);
                if (is_array($decoded)) {
                    $incoming = array_merge($incoming, $decoded);
                }
            }
            $j2 = $pdo->query("SELECT value FROM meta WHERE key = 'journal_v2'")->fetch();
            if ($j2 && ! empty($j2['value'])) {
                $decoded = json_decode((string) $j2['value'], true);
                if (is_array($decoded)) {
                    $incoming = array_merge($incoming, $decoded);
                }
            }
            if ($incoming !== []) {
                $remapped = self::remapJournalKeys($pdo, $incoming);
                $existing = Meta::json('trade_notes', []);
                $merged = array_merge($existing, $remapped);
                // Drop desktop UUID rt keys that remapped onto order-* (or other) ids.
                foreach ($incoming as $oldKey => $_val) {
                    $oldKey = (string) $oldKey;
                    if (! str_starts_with($oldKey, 'rt:')) {
                        continue;
                    }
                    if (isset($remapped[$oldKey])) {
                        continue; // key unchanged
                    }
                    // Remapped under a different key — remove the orphan UUID entry.
                    if (isset($merged[$oldKey])) {
                        unset($merged[$oldKey]);
                    }
                }
                Meta::putValue('trade_notes', json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $out['trade_notes'] = count($merged);
            }
        } catch (\Throwable $e) {
            // meta table optional
        }

        Meta::putValue('market_imported_at', gmdate('c'));
        Meta::putValue('market_import_path', $path);
        BookCache::flush();
        $out['ok'] = true;

        return $out;
    }

    /**
     * Map desktop journal keys onto Laravel trade ids.
     * Prefer remapping rt:<uuid> → rt:<order-…> when the opening fill fingerprints match.
     *
     * @param  array<string, mixed>  $notes
     * @return array<string, array<string, mixed>>
     */
    public static function remapJournalKeys(\PDO $desktopPdo, array $notes): array
    {
        $desktopById = [];
        try {
            foreach ($desktopPdo->query('SELECT id, account_id, symbol, transaction_date, quantity, unit_price, currency FROM activities') as $r) {
                $id = (string) ($r['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $desktopById[$id] = $r;
            }
        } catch (\Throwable $e) {
            // older schema column names
            try {
                foreach ($desktopPdo->query('SELECT id, accountId as account_id, symbol, transactionDate as transaction_date, quantity, unitPrice as unit_price, currency FROM activities') as $r) {
                    $id = (string) ($r['id'] ?? '');
                    if ($id !== '') {
                        $desktopById[$id] = $r;
                    }
                }
            } catch (\Throwable $e2) {
                $desktopById = [];
            }
        }

        $laravelByFp = [];
        foreach (\App\Models\Activity::query()->get(['id', 'account_id', 'symbol', 'transaction_date', 'quantity', 'unit_price', 'currency']) as $a) {
            $fp = self::activityFingerprint(
                (string) $a->account_id,
                (string) $a->symbol,
                (string) $a->transaction_date,
                (float) $a->quantity,
                (float) $a->unit_price,
                (string) ($a->currency ?? ''),
            );
            // First wins; collisions are rare for this key.
            $laravelByFp[$fp] ??= (string) $a->id;
        }

        $out = [];
        foreach ($notes as $key => $val) {
            if (! is_array($val)) {
                continue;
            }
            $key = (string) $key;
            $entry = [
                'thesis' => (string) ($val['thesis'] ?? ''),
                'grade' => (string) ($val['grade'] ?? ''),
                'tags' => is_array($val['tags'] ?? null) ? array_values($val['tags']) : [],
                'tag' => (string) ($val['tag'] ?? ''),
            ];
            if ($entry['tags'] === [] && $entry['tag'] !== '') {
                $entry['tags'] = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $entry['tag']) ?: [])));
            }
            if ($entry['tag'] === '' && $entry['tags'] !== []) {
                $entry['tag'] = implode(', ', $entry['tags']);
            }
            $newKey = $key;
            if (str_starts_with($key, 'rt:')) {
                $desktopId = substr($key, 3);
                if ($desktopId !== '' && isset($desktopById[$desktopId])) {
                    $d = $desktopById[$desktopId];
                    $fp = self::activityFingerprint(
                        (string) ($d['account_id'] ?? ''),
                        (string) ($d['symbol'] ?? ''),
                        (string) ($d['transaction_date'] ?? ''),
                        (float) ($d['quantity'] ?? 0),
                        (float) ($d['unit_price'] ?? 0),
                        (string) ($d['currency'] ?? ''),
                    );
                    $laravelId = $laravelByFp[$fp] ?? null;
                    if (is_string($laravelId) && $laravelId !== '') {
                        $newKey = 'rt:'.$laravelId;
                    }
                }
            }
            $entry['tradeId'] = $newKey;
            $out[$newKey] = $entry;
        }

        return $out;
    }

    public static function activityFingerprint(
        string $accountId,
        string $symbol,
        string $date,
        float $qty,
        float $price,
        string $currency,
    ): string {
        return implode('|', [
            trim($accountId),
            strtoupper(trim($symbol)),
            substr(trim($date), 0, 10),
            number_format(abs($qty), 8, '.', ''),
            number_format(abs($price), 8, '.', ''),
            strtoupper(trim($currency)),
        ]);
    }

    public static function ensureTables(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('quotes')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        }
    }

    public static function ensureOrdersTables(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('orders')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        }
    }

    /**
     * Price bars for Charts::price — from imported Laravel table.
     *
     * @return list<array{date:string,time:int,open:?float,high:?float,low:?float,close:float}>
     */
    public static function barsFor(string $symbol, string $tf, ?int $startTs = null, ?int $endTs = null, int $limit = 2000): array
    {
        if ($symbol === '' || ! \Illuminate\Support\Facades\Schema::hasTable('price_bars')) {
            return [];
        }
        $q = DB::table('price_bars')
            ->where('symbol', $symbol)
            ->where('tf', $tf)
            ->orderBy('ts');
        if ($startTs !== null) {
            $q->where('ts', '>=', $startTs);
        }
        if ($endTs !== null) {
            $q->where('ts', '<=', $endTs);
        }
        $rows = $q->limit($limit)->get();
        if ($rows->isEmpty()) {
            $under = Symbols::underlyingSymbol($symbol);
            if ($under !== $symbol && $under !== '') {
                $q = DB::table('price_bars')->where('symbol', $under)->where('tf', $tf)->orderBy('ts');
                if ($startTs !== null) {
                    $q->where('ts', '>=', $startTs);
                }
                if ($endTs !== null) {
                    $q->where('ts', '<=', $endTs);
                }
                $rows = $q->limit($limit)->get();
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $ts = (int) $r->ts;
            $out[] = [
                'date' => gmdate('Y-m-d', $ts),
                'time' => $ts,
                'open' => $r->open !== null ? (float) $r->open : null,
                'high' => $r->high !== null ? (float) $r->high : null,
                'low' => $r->low !== null ? (float) $r->low : null,
                'close' => (float) $r->close,
            ];
        }

        return $out;
    }

    /** @return array<string, float> */
    public static function benchmarkSeries(string $symbol): array
    {
        $all = Meta::json('benchmarks', []);
        if (isset($all[$symbol]) && is_array($all[$symbol])) {
            return $all[$symbol];
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('benchmark_prices')) {
            return [];
        }
        $map = [];
        foreach (DB::table('benchmark_prices')->where('symbol', $symbol)->orderBy('date')->get() as $r) {
            $map[(string) $r->date] = (float) $r->close;
        }

        return $map;
    }

    /**
     * Import once if Meta quotes empty and desktop DB exists.
     */
    public static function importIfEmpty(): ?array
    {
        $quotes = Meta::json('quotes', []);
        if (is_array($quotes) && $quotes !== []) {
            return null;
        }
        if (! is_file(self::defaultPath())) {
            return null;
        }

        return self::import();
    }
}
