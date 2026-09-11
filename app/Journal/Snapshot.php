<?php

namespace App\Journal;

use App\Models\Activity;
use App\Models\Meta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

final class Snapshot
{
    public static function load(): array
    {
        $activities = Activity::query()
            ->orderByRaw("COALESCE(occurred_at, transaction_date) ASC")
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $a) => $a->toJournal())
            ->all();

        $accounts = DB::table('accounts')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'nickname' => $r->nickname ?: '',
            'unifiedAccountType' => $r->unified_account_type ?: '',
            'currency' => $r->currency ?: '',
            'status' => $r->status ?: '',
            'type' => $r->type ?: '',
            'netLiquidationValue' => $r->net_liquidation_value,
        ])->all();

        $nav = [];
        $navByAccount = [];
        foreach (DB::table('nav_history')->orderBy('account_id')->orderBy('date')->get() as $r) {
            $rec = [
                'date' => $r->date,
                'equity' => $r->equity,
                'currency' => $r->currency ?: 'CAD',
            ];
            if ($r->net_deposits !== null) {
                $rec['netDeposits'] = $r->net_deposits;
            }
            $aid = $r->account_id ?: '';
            if ($aid === '') {
                $nav[] = $rec;
            } else {
                $navByAccount[$aid][] = $rec;
            }
        }

        $securities = DB::table('securities')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'symbol' => $r->symbol ?: '',
            'name' => $r->name ?: '',
            'primaryExchange' => $r->primary_exchange ?: '',
            'primaryMic' => $r->primary_mic ?: '',
            'currency' => $r->currency ?: '',
            'underlyingId' => $r->underlying_id,
        ])->all();

        $spy = Meta::json('spy_by_date', []);
        $seedPath = resource_path('data/spy-seed.txt');
        if (is_file($seedPath)) {
            $spy = array_merge(Spy::parseSeed((string) file_get_contents($seedPath)), $spy);
        }

        // Active benchmark override (TSX / TSX60) replaces Spy series for compare pills.
        $bench = (string) Meta::getValue('active_benchmark', 'SP500');
        if ($bench !== '' && $bench !== 'SP500' && $bench !== 'SPY') {
            $series = MarketImport::benchmarkSeries($bench);
            if ($series !== []) {
                $spy = $series;
            }
        }

        $margin = [];
        if (Schema::hasTable('margin')) {
            foreach (DB::table('margin')->get() as $r) {
                $margin[] = [
                    'accountId' => (string) ($r->account_id ?? ''),
                    'buyingPower' => $r->buying_power !== null ? (float) $r->buying_power : null,
                    'currency' => (string) ($r->currency ?? 'CAD'),
                    'unavailable' => (string) ($r->unavailable ?? ''),
                    'fetchedAt' => (string) ($r->fetched_at ?? ''),
                ];
            }
        }

        $balances = [];
        if (Schema::hasTable('balances')) {
            foreach (DB::table('balances')->get() as $r) {
                $balances[] = [
                    'accountId' => (string) ($r->account_id ?? ''),
                    'custodianAccountId' => (string) ($r->custodian_account_id ?? ''),
                    'securityId' => (string) ($r->security_id ?? ''),
                    'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
                ];
            }
        }

        // When Laravel journal is thin (demo / unsynced), merge portfolio inputs from desktop bagholder.db.
        $desktop = self::desktopPortfolioExtras($accounts, $balances, $securities, $margin);
        if ($desktop !== null) {
            $accounts = $desktop['accounts'];
            $balances = $desktop['balances'];
            $securities = $desktop['securities'];
            $margin = $desktop['margin'];
        }

        // Cash securities Wealthsimple lists as CAD/USD (sec-c-*) — desktop SecuritiesIndex.cash_currencies.
        $cashCurrencies = [];
        foreach ($securities as $sec) {
            $sid = (string) ($sec['id'] ?? '');
            $sym = strtoupper(trim((string) ($sec['symbol'] ?? '')));
            if ($sym === 'CAD' || $sym === 'USD' || str_starts_with($sid, 'sec-c-')) {
                $ccy = strtoupper(trim((string) ($sec['currency'] ?? ''))) ?: $sym;
                if ($sid !== '' && $ccy !== '') {
                    $cashCurrencies[$sid] = $ccy;
                }
            }
        }

        return [
            'activities' => $activities,
            'accounts' => $accounts,
            'navHistory' => $nav,
            'navByAccount' => $navByAccount,
            'securities' => $securities,
            'balances' => $balances,
            'cashCurrencies' => $cashCurrencies,
            'margin' => $margin,
            'tradeGroups' => Meta::json('trade_groups', []),
            'notes' => Meta::json('trade_notes', []),
            'spyByDate' => $spy,
            'fxByDate' => Meta::json('fx_by_date', []),
            'syncedAt' => (string) Meta::getValue('synced_at', ''),
            'distributions' => self::distributions(),
            'quotes' => self::quotes(),
            'benchmarks' => Meta::json('benchmarks', []),
            'activeBenchmark' => $bench !== '' ? $bench : 'SP500',
            'exposures' => Meta::json('exposures', []),
        ];
    }

    /**
     * Merge accounts / balances / cash securities / margin from ~/.bagholder/bagholder.db
     * when the Laravel journal copy is thin (few accounts or no balances).
     *
     * @param  list<array<string,mixed>>  $accounts
     * @param  list<array<string,mixed>>  $balances
     * @param  list<array<string,mixed>>  $securities
     * @param  list<array<string,mixed>>  $margin
     * @return array{accounts:list<array>,balances:list<array>,securities:list<array>,margin:list<array>}|null
     */
    private static function desktopPortfolioExtras(array $accounts, array $balances, array $securities, array $margin): ?array
    {
        $thin = count($accounts) <= 2 || $balances === [];
        if (! $thin) {
            return null;
        }
        $path = MarketImport::defaultPath();
        if (! is_file($path)) {
            return null;
        }
        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
        } catch (\Throwable) {
            return null;
        }

        $has = fn (string $t): bool => (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($t)
        )->fetchColumn() > 0;

        if ($has('accounts')) {
            $merged = [];
            foreach ($pdo->query('SELECT * FROM accounts ORDER BY id') as $r) {
                $merged[] = [
                    'id' => (string) ($r['id'] ?? ''),
                    'nickname' => (string) ($r['nickname'] ?? ''),
                    'unifiedAccountType' => (string) ($r['unified_account_type'] ?? ''),
                    'currency' => (string) ($r['currency'] ?? ''),
                    'status' => (string) ($r['status'] ?? ''),
                    'type' => (string) ($r['type'] ?? ''),
                    'netLiquidationValue' => isset($r['net_liquidation_value']) && $r['net_liquidation_value'] !== null
                        ? (float) $r['net_liquidation_value']
                        : null,
                ];
            }
            if ($merged !== []) {
                $accounts = $merged;
            }
        }

        if ($has('balances') && $balances === []) {
            foreach ($pdo->query('SELECT * FROM balances') as $r) {
                $balances[] = [
                    'accountId' => (string) ($r['account_id'] ?? ''),
                    'custodianAccountId' => (string) ($r['custodian_account_id'] ?? ''),
                    'securityId' => (string) ($r['security_id'] ?? ''),
                    'quantity' => isset($r['quantity']) && $r['quantity'] !== null ? (float) $r['quantity'] : null,
                ];
            }
        }

        if ($has('securities')) {
            $byId = [];
            foreach ($securities as $sec) {
                $sid = (string) ($sec['id'] ?? '');
                if ($sid !== '') {
                    $byId[$sid] = $sec;
                }
            }
            foreach ($pdo->query('SELECT id, symbol, name, primary_exchange, primary_mic, currency, underlying_id FROM securities') as $r) {
                $sid = (string) ($r['id'] ?? '');
                $sym = strtoupper(trim((string) ($r['symbol'] ?? '')));
                if ($sid === '') {
                    continue;
                }
                // Prefer cash / currency securities for margin cashCurrencies; keep other local rows.
                if (! isset($byId[$sid]) && (str_starts_with($sid, 'sec-c-') || $sym === 'CAD' || $sym === 'USD')) {
                    $byId[$sid] = [
                        'id' => $sid,
                        'symbol' => (string) ($r['symbol'] ?? ''),
                        'name' => (string) ($r['name'] ?? ''),
                        'primaryExchange' => (string) ($r['primary_exchange'] ?? ''),
                        'primaryMic' => (string) ($r['primary_mic'] ?? ''),
                        'currency' => (string) ($r['currency'] ?? ''),
                        'underlyingId' => $r['underlying_id'] ?? null,
                    ];
                }
            }
            $securities = array_values($byId);
        }

        if ($margin === [] && $has('margin')) {
            foreach ($pdo->query('SELECT * FROM margin') as $r) {
                $margin[] = [
                    'accountId' => (string) ($r['account_id'] ?? ''),
                    'buyingPower' => isset($r['buying_power']) && $r['buying_power'] !== null ? (float) $r['buying_power'] : null,
                    'currency' => (string) ($r['currency'] ?? 'CAD'),
                    'unavailable' => (string) ($r['unavailable'] ?? ''),
                    'fetchedAt' => (string) ($r['fetched_at'] ?? ''),
                ];
            }
        }

        return [
            'accounts' => $accounts,
            'balances' => $balances,
            'securities' => $securities,
            'margin' => $margin,
        ];
    }

    public static function book(array $filters, array $sort = []): array
    {
        return BookCache::remember($filters, $sort, function () use ($filters, $sort) {
            $snap = self::load();

            return Book::build($snap, $filters, $sort, $snap['tradeGroups'] ?? []);
        });
    }

    /** @return array<string, array<string, mixed>> */
    public static function quotes(): array
    {
        $meta = Meta::json('quotes', []);
        if (is_array($meta) && $meta !== []) {
            return $meta;
        }
        if (! Schema::hasTable('quotes')) {
            return [];
        }
        $out = [];
        foreach (DB::table('quotes')->get() as $r) {
            $out[(string) $r->symbol] = [
                'price' => $r->price !== null ? (float) $r->price : null,
                'priceChange' => $r->price_change !== null ? (float) $r->price_change : null,
                'percentChange' => $r->percent_change !== null ? (float) $r->percent_change : null,
                'prevClose' => $r->prev_close !== null ? (float) $r->prev_close : null,
                'dividendAmount' => $r->dividend_amount !== null ? (float) $r->dividend_amount : null,
                'dividendFrequency' => (string) ($r->dividend_frequency ?? ''),
                'exDividendDate' => (string) ($r->ex_dividend_date ?? ''),
                'source' => (string) ($r->source ?? ''),
                'fetchedAt' => (string) ($r->fetched_at ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function distributions(): array
    {
        $meta = Meta::json('distributions', []);
        if (is_array($meta) && $meta !== []) {
            return $meta;
        }
        if (! Schema::hasTable('distributions')) {
            return [];
        }
        $out = [];
        foreach (DB::table('distributions')->orderByDesc('ex_date')->get() as $r) {
            $out[(string) $r->symbol][] = [
                'exDate' => (string) $r->ex_date,
                'payDate' => (string) ($r->pay_date ?? ''),
                'amount' => (float) $r->amount,
                'currency' => (string) ($r->currency ?? ''),
            ];
        }

        return $out;
    }
}
