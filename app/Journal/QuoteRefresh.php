<?php

namespace App\Journal;

use App\Models\Meta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh live-ish quotes: pull desktop bagholder.db quotes, Yahoo for held
 * equities/ETFs still missing or stale, and Cboe delayed option chains for
 * held USD options (desktop market.refresh_quotes parity — public CDN, no keys).
 */
final class QuoteRefresh
{
    public const MAX_AGE_SECONDS = 120;

    /**
     * @param  list<array<string, mixed>>  $positions  Optional open positions (avoids rebuilding the book).
     * @return array{imported:int, fetched:int, cboe:int, total:int, changed:bool}
     */
    public static function run(bool $yahoo = true, array $positions = []): array
    {
        $before = (string) Meta::getValue('quotes', '');
        $imported = 0;
        if (is_file(MarketImport::defaultPath())) {
            $imported = self::importDesktopQuotes();
        }

        $fetched = 0;
        if ($yahoo) {
            $fetched = self::fetchYahooForHeld($positions);
        }

        $cboe = self::fetchCboeOptionsForHeld($positions);

        $after = (string) Meta::getValue('quotes', '');
        $changed = $after !== $before;
        if ($changed) {
            BookCache::flush();
        }

        $quotes = Meta::json('quotes', []);

        return [
            'imported' => $imported,
            'fetched' => $fetched,
            'cboe' => $cboe,
            'total' => is_array($quotes) ? count($quotes) : 0,
            'changed' => $changed,
        ];
    }

    public static function importDesktopQuotes(): int
    {
        $path = MarketImport::defaultPath();
        if (! is_file($path)) {
            return 0;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        } catch (\Throwable) {
            return 0;
        }

        self::ensureQuotesTable();
        $existing = Meta::json('quotes', []);
        if (! is_array($existing)) {
            $existing = [];
        }
        $n = 0;
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
            if ($rec['price'] === null || $rec['price'] <= 0) {
                continue;
            }
            $existing[$sym] = $rec;
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
            $n++;
        }
        Meta::putValue('quotes', json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $n;
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     */
    public static function fetchYahooForHeld(array $positions = []): int
    {
        $open = $positions;
        $quotes = Meta::json('quotes', []);
        if (! is_array($quotes)) {
            $quotes = [];
        }

        $todo = [];
        foreach ($open as $p) {
            $sym = (string) ($p['symbol'] ?? '');
            if ($sym === '' || str_contains($sym, ' ') || Symbols::isOptionSymbol($sym)) {
                // Options: Cboe delayed chain (fetchCboeOptionsForHeld), not Yahoo equity.
                continue;
            }
            $ccy = strtoupper((string) ($p['currency'] ?? 'CAD'));
            if (! self::isStale($quotes[$sym] ?? null)) {
                continue;
            }
            $todo[$sym] = $ccy;
        }

        $done = 0;
        foreach ($todo as $sym => $ccy) {
            $rec = self::yahooQuote($sym, $ccy);
            if ($rec === null) {
                continue;
            }
            $quotes[$sym] = $rec;
            self::ensureQuotesTable();
            DB::table('quotes')->updateOrInsert(
                ['symbol' => $sym],
                [
                    'price' => $rec['price'],
                    'price_change' => $rec['priceChange'],
                    'percent_change' => $rec['percentChange'],
                    'prev_close' => $rec['prevClose'],
                    'dividend_amount' => null,
                    'dividend_frequency' => '',
                    'ex_dividend_date' => '',
                    'source' => 'yahoo',
                    'fetched_at' => $rec['fetchedAt'],
                ],
            );
            $done++;
        }
        if ($done > 0) {
            Meta::putValue('quotes', json_encode($quotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Meta::putValue('quotes_refreshed_at', gmdate('c'));
        }

        return $done;
    }

    /** @param  array<string, mixed>|null  $rec */
    public static function isStale(?array $rec): bool
    {
        if ($rec === null || ! isset($rec['price']) || (float) $rec['price'] <= 0) {
            return true;
        }
        $at = (string) ($rec['fetchedAt'] ?? '');
        if ($at === '') {
            return true;
        }
        try {
            $ts = strtotime($at);
        } catch (\Throwable) {
            return true;
        }
        if ($ts === false) {
            return true;
        }

        return (time() - $ts) > self::MAX_AGE_SECONDS;
    }

    /**
     * @return array{price:float,priceChange:?float,percentChange:?float,prevClose:?float,fetchedAt:string,source:string}|null
     */
    public static function yahooQuote(string $symbol, string $currency = 'USD'): ?array
    {
        foreach (self::yahooForms($symbol, $currency) as $form) {
            try {
                $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($form).'?range=1d&interval=1m';
                $res = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'BagholderLaravel/0.1'])
                    ->get($url);
                if (! $res->successful()) {
                    continue;
                }
                $meta = $res->json('chart.result.0.meta');
                if (! is_array($meta)) {
                    continue;
                }
                $price = isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : 0.0;
                if ($price <= 0) {
                    continue;
                }
                $prev = isset($meta['chartPreviousClose']) ? (float) $meta['chartPreviousClose'] : (isset($meta['previousClose']) ? (float) $meta['previousClose'] : null);
                $chg = null;
                $pct = isset($meta['regularMarketChangePercent']) ? ((float) $meta['regularMarketChangePercent']) / 100.0 : null;
                if ($prev !== null && $prev > 0) {
                    $chg = $price - $prev;
                    if ($pct === null) {
                        $pct = $chg / $prev;
                    }
                }

                return [
                    'price' => $price,
                    'priceChange' => $chg,
                    'percentChange' => $pct,
                    'prevClose' => $prev,
                    'fetchedAt' => gmdate('c'),
                    'source' => 'yahoo',
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function yahooForms(string $symbol, string $currency): array
    {
        $root = str_replace('.', '-', strtoupper(trim($symbol)));
        if ($root === '' || str_contains($root, ' ')) {
            return [];
        }
        $ccy = strtoupper($currency);
        if ($ccy === 'USD') {
            return [$root];
        }

        return [$root.'.TO', $root.'.V', $root.'.CN', $root];
    }

    /**
     * Desktop market.refresh_quotes cboe_options branch: public delayed chains.
     * USD options only (CAD options have no public Cboe chain in desktop either).
     *
     * @param  list<array<string, mixed>>  $positions
     */
    public static function fetchCboeOptionsForHeld(array $positions = []): int
    {
        $quotes = Meta::json('quotes', []);
        if (! is_array($quotes)) {
            $quotes = [];
        }

        /** @var array<string, list<array{symbol:string,code:string}>> $byRoot */
        $byRoot = [];
        foreach ($positions as $p) {
            $sym = (string) ($p['symbol'] ?? '');
            if ($sym === '' || ! Symbols::isOptionSymbol($sym)) {
                continue;
            }
            $ccy = strtoupper((string) ($p['currency'] ?? 'CAD'));
            if ($ccy !== 'USD') {
                continue;
            }
            if (! self::isStale($quotes[$sym] ?? null)) {
                continue;
            }
            $code = self::occCode($sym);
            $root = self::occRoot($code);
            if ($code === '' || $root === '') {
                continue;
            }
            $byRoot[$root][] = ['symbol' => $sym, 'code' => $code];
        }

        if ($byRoot === []) {
            return 0;
        }

        $done = 0;
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        foreach ($byRoot as $root => $items) {
            $chain = self::fetchCboeOptionChain($root, $ua);
            if ($chain === []) {
                continue;
            }
            foreach ($items as $item) {
                $rec = self::optionMark($chain[$item['code']] ?? null);
                if ($rec === null) {
                    continue;
                }
                $rec['source'] = 'cboe_options';
                $quotes[$item['symbol']] = $rec;
                self::ensureQuotesTable();
                DB::table('quotes')->updateOrInsert(
                    ['symbol' => $item['symbol']],
                    [
                        'price' => $rec['price'],
                        'price_change' => $rec['priceChange'],
                        'percent_change' => $rec['percentChange'],
                        'prev_close' => $rec['prevClose'],
                        'dividend_amount' => null,
                        'dividend_frequency' => '',
                        'ex_dividend_date' => '',
                        'source' => 'cboe_options',
                        'fetched_at' => $rec['fetchedAt'],
                    ],
                );
                $done++;
            }
        }

        if ($done > 0) {
            Meta::putValue('quotes', json_encode($quotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Meta::putValue('quotes_refreshed_at', gmdate('c'));
        }

        return $done;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fetchCboeOptionChain(string $root, ?string $ua = null): array
    {
        $root = strtoupper(trim($root));
        if ($root === '') {
            return [];
        }
        $ua = $ua ?: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        try {
            $url = 'https://cdn.cboe.com/api/global/delayed_quotes/options/'.rawurlencode($root).'.json';
            $res = Http::timeout(20)->withHeaders(['User-Agent' => $ua, 'Accept' => 'application/json'])->get($url);
            if (! $res->successful()) {
                return [];
            }
            $opts = $res->json('data.options');
            if (! is_array($opts)) {
                return [];
            }
            $out = [];
            foreach ($opts as $o) {
                if (! is_array($o)) {
                    continue;
                }
                $key = (string) ($o['option'] ?? '');
                if ($key !== '') {
                    $out[$key] = $o;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Desktop market.option_mark — bid/ask midpoint, else last trade, else prev close.
     *
     * @param  array<string, mixed>|null  $row
     * @return array{price:float,priceChange:?float,percentChange:?float,prevClose:?float,fetchedAt:string,source:string}|null
     */
    public static function optionMark(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $bid = isset($row['bid']) ? (float) $row['bid'] : 0.0;
        $ask = isset($row['ask']) ? (float) $row['ask'] : 0.0;
        $prev = isset($row['prev_day_close']) ? (float) $row['prev_day_close'] : null;
        if ($bid > 0 && $ask > 0) {
            $px = ($bid + $ask) / 2.0;
        } else {
            $last = isset($row['last_trade_price']) ? (float) $row['last_trade_price'] : 0.0;
            $px = $last > 0 ? $last : ($prev !== null && $prev > 0 ? $prev : 0.0);
        }
        if ($px <= 0) {
            return null;
        }
        $chg = ($prev !== null && $prev > 0) ? ($px - $prev) : null;
        // Desktop stores percent points (not a fraction) for Cboe option marks.
        $pct = ($prev !== null && $prev > 0) ? (($px / $prev - 1.0) * 100.0) : null;

        return [
            'price' => $px,
            'priceChange' => $chg,
            'percentChange' => $pct,
            'prevClose' => $prev,
            'fetchedAt' => gmdate('c'),
            'source' => 'cboe_options',
        ];
    }

    /** 'QNC 20NOV26 3.00 CALL' -> 'QNC261120C00003000' (desktop market.occ_code). */
    public static function occCode(string $symbol): string
    {
        $u = strtoupper(trim(preg_replace('/\s+/', ' ', $symbol) ?? $symbol));
        if (preg_match('/^([A-Z][A-Z0-9.]{0,9}) (\d{6}[CP]\d{8})$/', $u, $m)) {
            return $m[1].$m[2];
        }
        if (! preg_match('/^([A-Z][A-Z0-9.]{0,9}) (\d{1,2})([A-Z]{3})(\d{2}) (\d+(?:\.\d+)?) (CALL|PUT|C|P)$/', $u, $m)) {
            return '';
        }
        $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        $mon = $m[3];
        $idx = array_search($mon, $months, true);
        if ($idx === false) {
            return '';
        }
        $root = $m[1];
        $day = (int) $m[2];
        $yy = $m[4];
        $strike = (int) round(((float) $m[5]) * 1000);
        $right = $m[6][0];

        return sprintf('%s%s%02d%02d%s%08d', $root, $yy, $idx + 1, $day, $right, $strike);
    }

    public static function occRoot(string $code): string
    {
        if (preg_match('/^([A-Z][A-Z0-9.]{0,9})\d{6}[CP]\d{8}$/', $code, $m)) {
            return $m[1];
        }

        return '';
    }

    private static function ensureQuotesTable(): void
    {
        if (Schema::hasTable('quotes')) {
            return;
        }
        Schema::create('quotes', function ($t) {
            $t->string('symbol')->primary();
            $t->double('price')->nullable();
            $t->double('price_change')->nullable();
            $t->double('percent_change')->nullable();
            $t->double('prev_close')->nullable();
            $t->double('dividend_amount')->nullable();
            $t->string('dividend_frequency')->nullable();
            $t->string('ex_dividend_date')->nullable();
            $t->string('source')->nullable();
            $t->string('fetched_at')->nullable();
        });
    }
}
