<?php

namespace App\Wealthsimple;

use App\Journal\MarketImport;
use App\Journal\Orders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Order/bracket persistence: desktop bagholder.db when present (writable), else Laravel tables.
 */
final class OrderStore
{
    public static function dbPath(): string
    {
        return MarketImport::defaultPath();
    }

    public static function usesDesktop(): bool
    {
        $path = self::dbPath();

        return is_file($path) && is_writable($path);
    }

    private static function pdo(): ?PDO
    {
        $path = self::dbPath();
        if (! is_file($path)) {
            return null;
        }
        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }

    /** @return array<string,mixed>|null */
    public static function getOrder(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if ($pdo = self::pdo()) {
            $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if ($r) {
                return Orders::orderFromRow($r);
            }
        }
        if (Schema::hasTable('orders')) {
            $r = DB::table('orders')->where('id', $id)->first();
            if ($r) {
                return Orders::orderFromRow((array) $r);
            }
        }

        return null;
    }

    /** @param  array<string,mixed>  $row */
    public static function insertOrder(array $row): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $created = (string) ($row['createdAt'] ?? '') ?: $now;
        $vals = [
            'id' => (string) ($row['id'] ?? ''),
            'created_at' => $created,
            'account_id' => (string) ($row['accountId'] ?? ''),
            'account' => (string) ($row['account'] ?? ''),
            'security_id' => (string) ($row['securityId'] ?? ''),
            'symbol' => (string) ($row['symbol'] ?? ''),
            'currency' => (string) ($row['currency'] ?? ''),
            'side' => (string) ($row['side'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : 0.0,
            'limit_price' => $row['limitPrice'] ?? null,
            'stop_price' => $row['stopPrice'] ?? null,
            'tif' => (string) ($row['tif'] ?? 'DAY'),
            'stop_loss' => isset($row['stopLoss']) ? json_encode($row['stopLoss']) : null,
            'take_profit' => isset($row['takeProfit']) ? json_encode($row['takeProfit']) : null,
            'status' => (string) ($row['status'] ?? ''),
            'ws_order_id' => (string) ($row['wsOrderId'] ?? ''),
            'error' => (string) ($row['error'] ?? ''),
            'request' => isset($row['request']) ? json_encode($row['request']) : null,
            'updated_at' => $now,
            'source' => (string) ($row['source'] ?? 'bagholder'),
            'ws_status' => (string) ($row['wsStatus'] ?? ''),
            'filled_qty' => $row['filledQty'] ?? null,
            'avg_fill' => $row['avgFill'] ?? null,
            'submitted_at' => (string) ($row['submittedAt'] ?? ''),
            'expires_at' => (string) ($row['expiresAt'] ?? ''),
            'parent_id' => (string) ($row['parentId'] ?? ''),
            'role' => (string) ($row['role'] ?? 'entry') ?: 'entry',
        ];

        if ($pdo = self::pdo()) {
            $cols = array_keys($vals);
            $sql = 'INSERT INTO orders ('.implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')';
            $pdo->prepare($sql)->execute(array_values($vals));

            return;
        }
        if (Schema::hasTable('orders')) {
            DB::table('orders')->insert($vals);
        }
    }

    /** @param  array<string,mixed>  $patch camelCase keys like desktop update_order */
    public static function updateOrder(string $id, array $patch): void
    {
        $text = [
            'status' => 'status',
            'wsOrderId' => 'ws_order_id',
            'error' => 'error',
            'wsStatus' => 'ws_status',
            'submittedAt' => 'submitted_at',
            'expiresAt' => 'expires_at',
            'tif' => 'tif',
            'currency' => 'currency',
            'symbol' => 'symbol',
        ];
        $nums = [
            'filledQty' => 'filled_qty',
            'avgFill' => 'avg_fill',
            'quantity' => 'quantity',
            'limitPrice' => 'limit_price',
            'stopPrice' => 'stop_price',
        ];
        $sets = [];
        $vals = [];
        foreach ($text as $k => $col) {
            if (array_key_exists($k, $patch)) {
                $sets[] = $col;
                $vals[$col] = (string) ($patch[$k] ?? '');
            }
        }
        foreach ($nums as $k => $col) {
            if (array_key_exists($k, $patch)) {
                $sets[] = $col;
                $v = $patch[$k];
                $vals[$col] = $v === null || $v === '' ? null : (float) $v;
            }
        }
        if ($sets === []) {
            return;
        }
        $vals['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $sets[] = 'updated_at';

        if ($pdo = self::pdo()) {
            $assign = implode(', ', array_map(fn ($c) => $c.' = ?', $sets));
            $params = [];
            foreach ($sets as $c) {
                $params[] = $vals[$c];
            }
            $params[] = $id;
            $pdo->prepare('UPDATE orders SET '.$assign.' WHERE id = ?')->execute($params);

            return;
        }
        if (Schema::hasTable('orders')) {
            $row = [];
            foreach ($sets as $c) {
                $row[$c] = $vals[$c];
            }
            DB::table('orders')->where('id', $id)->update($row);
        }
    }

    /**
     * Tradable accounts for the ticket (SELF_DIRECTED*, not crypto/predictions/managed).
     *
     * @return list<array{id:string,name:string,type:string,margin:bool,currency:string,marginAccountId:string}>
     */
    public static function orderAccounts(): array
    {
        $rows = [];
        if ($pdo = self::pdo()) {
            try {
                foreach ($pdo->query('SELECT id, nickname, unified_account_type, status, currency, margin_account_id FROM accounts') as $a) {
                    $rows[] = $a;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        if ($rows === [] && Schema::hasTable('accounts')) {
            foreach (DB::table('accounts')->get() as $a) {
                $rows[] = (array) $a;
            }
        }
        $out = [];
        foreach ($rows as $a) {
            $typ = strtoupper(trim((string) ($a['unified_account_type'] ?? $a['unifiedAccountType'] ?? '')));
            $status = strtolower(trim((string) ($a['status'] ?? '')));
            $id = trim((string) ($a['id'] ?? ''));
            if ($id === '' || $status === 'closed' || ! str_starts_with($typ, 'SELF_DIRECTED')) {
                continue;
            }
            foreach (['CRYPTO', 'PREDICTIONS', 'MANAGED'] as $m) {
                if (str_contains($typ, $m)) {
                    continue 2;
                }
            }
            $nick = trim((string) ($a['nickname'] ?? ''));
            if ($nick === '') {
                $nick = $typ;
            }
            $margin = str_contains($typ, 'MARGIN');
            $out[] = [
                'id' => $id,
                'name' => $nick,
                'type' => $typ,
                'margin' => $margin,
                'currency' => (string) ($a['currency'] ?? ''),
                'marginAccountId' => $margin ? $id : (string) ($a['margin_account_id'] ?? $a['marginAccountId'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function resolveSecurity(?string $symbol = '', ?string $securityId = ''): ?array
    {
        $sid = trim((string) $securityId);
        $sym = strtoupper(trim((string) $symbol));
        $rows = [];
        if ($pdo = self::pdo()) {
            try {
                foreach ($pdo->query('SELECT id, symbol, name, primary_exchange, primary_mic, currency, underlying_id FROM securities') as $r) {
                    $rows[] = $r;
                }
            } catch (\Throwable) {
                try {
                    foreach ($pdo->query('SELECT id, symbol, name, primary_exchange, currency FROM securities') as $r) {
                        $rows[] = $r;
                    }
                } catch (\Throwable) {
                }
            }
        }
        if ($rows === [] && Schema::hasTable('securities')) {
            foreach (DB::table('securities')->get() as $r) {
                $rows[] = (array) $r;
            }
        }
        $norm = function (array $r): array {
            return [
                'id' => (string) ($r['id'] ?? ''),
                'symbol' => (string) ($r['symbol'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'primaryExchange' => (string) ($r['primary_exchange'] ?? $r['primaryExchange'] ?? ''),
                'primaryMic' => (string) ($r['primary_mic'] ?? $r['primaryMic'] ?? ''),
                'currency' => (string) ($r['currency'] ?? ''),
                'underlyingId' => $r['underlying_id'] ?? $r['underlyingId'] ?? null,
            ];
        };
        if ($sid !== '') {
            foreach ($rows as $r) {
                if ((string) ($r['id'] ?? '') === $sid) {
                    return $norm($r);
                }
            }

            return [
                'id' => $sid,
                'symbol' => $sym,
                'name' => '',
                'primaryExchange' => '',
                'primaryMic' => '',
                'currency' => '',
                'underlyingId' => null,
            ];
        }
        if ($sym === '') {
            return null;
        }
        $same = [];
        foreach ($rows as $r) {
            if (strtoupper(trim((string) ($r['symbol'] ?? ''))) === $sym) {
                $same[] = $norm($r);
            }
        }
        usort($same, function ($a, $b) {
            $aa = str_starts_with($a['id'], 'sec-s-') ? 0 : 1;
            $bb = str_starts_with($b['id'], 'sec-s-') ? 0 : 1;

            return $aa <=> $bb ?: strcmp($a['id'], $b['id']);
        });

        return $same[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $b  camelCase like desktop insert_bracket
     */
    public static function insertBracket(array $b): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $vals = [
            'id' => (string) ($b['id'] ?? ''),
            'order_id' => (string) ($b['orderId'] ?? ''),
            'created_at' => (string) ($b['createdAt'] ?? '') ?: $now,
            'account_id' => (string) ($b['accountId'] ?? ''),
            'security_id' => (string) ($b['securityId'] ?? ''),
            'symbol' => (string) ($b['symbol'] ?? ''),
            'currency' => (string) ($b['currency'] ?? ''),
            'quantity' => isset($b['quantity']) && $b['quantity'] !== '' && $b['quantity'] !== null ? (float) $b['quantity'] : null,
            'tif' => (string) ($b['tif'] ?? 'DAY') ?: 'DAY',
            'sl_kind' => (string) ($b['slKind'] ?? ''),
            'sl_price' => self::nullableFloat($b['slPrice'] ?? null),
            'sl_trail' => self::nullableFloat($b['slTrail'] ?? null),
            'sl_trail_unit' => (string) ($b['slTrailUnit'] ?? 'pct') ?: 'pct',
            'sl_order_id' => (string) ($b['slOrderId'] ?? ''),
            'sl_native' => ! empty($b['slNative']) ? 1 : 0,
            'sl_mode' => (string) ($b['slMode'] ?? ''),
            'high_water' => self::nullableFloat($b['highWater'] ?? null),
            'tp_price' => self::nullableFloat($b['tpPrice'] ?? null),
            'tp_order_id' => (string) ($b['tpOrderId'] ?? ''),
            'status' => (string) ($b['status'] ?? 'waiting') ?: 'waiting',
            'outcome' => (string) ($b['outcome'] ?? ''),
            'error' => (string) ($b['error'] ?? ''),
            'attempts' => (int) ($b['attempts'] ?? 0),
            'moved_at' => (string) ($b['movedAt'] ?? ''),
            'armed_at' => (string) ($b['armedAt'] ?? ''),
            'updated_at' => $now,
            'seen_held' => ! empty($b['seenHeld']) ? 1 : 0,
            'missed_at' => (string) ($b['missedAt'] ?? ''),
        ];
        self::insertRow('brackets', $vals);
    }

    /**
     * @param  array<string,mixed>  $patch  camelCase like desktop update_bracket
     */
    public static function updateBracket(string $id, array $patch): void
    {
        $text = [
            'symbol' => 'symbol',
            'currency' => 'currency',
            'tif' => 'tif',
            'slKind' => 'sl_kind',
            'slTrailUnit' => 'sl_trail_unit',
            'slOrderId' => 'sl_order_id',
            'tpOrderId' => 'tp_order_id',
            'status' => 'status',
            'outcome' => 'outcome',
            'error' => 'error',
            'movedAt' => 'moved_at',
            'armedAt' => 'armed_at',
            'slMode' => 'sl_mode',
            'missedAt' => 'missed_at',
        ];
        $nums = [
            'quantity' => 'quantity',
            'slPrice' => 'sl_price',
            'slTrail' => 'sl_trail',
            'highWater' => 'high_water',
            'tpPrice' => 'tp_price',
            'attempts' => 'attempts',
            'slNative' => 'sl_native',
            'seenHeld' => 'seen_held',
        ];
        $row = [];
        foreach ($text as $k => $col) {
            if (array_key_exists($k, $patch)) {
                $row[$col] = (string) ($patch[$k] ?? '');
            }
        }
        foreach ($nums as $k => $col) {
            if (array_key_exists($k, $patch)) {
                $v = $patch[$k];
                if ($k === 'slNative' || $k === 'seenHeld') {
                    $row[$col] = $v ? 1 : 0;
                } elseif ($k === 'attempts') {
                    $row[$col] = (int) ($v ?? 0);
                } else {
                    $row[$col] = ($v === null || $v === '') ? null : (float) $v;
                }
            }
        }
        if ($row === []) {
            return;
        }
        $row['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        self::updateRow('brackets', $id, $row);
    }

    /** @return array<string,mixed>|null */
    public static function getBracket(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $raw = self::fetchById('brackets', $id);
        if (! $raw) {
            return null;
        }

        return Orders::bracketFromRow($raw);
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<array<string,mixed>>
     */
    public static function listBrackets(?array $statuses = null): array
    {
        $rows = self::fetchAll('brackets', $statuses);
        $out = [];
        foreach ($rows as $r) {
            $out[] = Orders::bracketFromRow($r);
        }

        return $out;
    }

    /**
     * Exit orders the bracket placed, newest first (desktop _own_exit_rows).
     *
     * @return list<array<string,mixed>>
     */
    public static function listExitOrders(string $parentId): array
    {
        $parentId = trim($parentId);
        if ($parentId === '') {
            return [];
        }
        $out = [];
        if ($pdo = self::pdo()) {
            try {
                $st = $pdo->prepare("SELECT * FROM orders WHERE parent_id = ? AND role IN ('stop','target') ORDER BY created_at DESC, rowid DESC");
                $st->execute([$parentId]);
                foreach ($st as $r) {
                    $out[] = Orders::orderFromRow($r);
                }

                return $out;
            } catch (\Throwable) {
                // fall through
            }
        }
        if (Schema::hasTable('orders')) {
            foreach (DB::table('orders')->where('parent_id', $parentId)->whereIn('role', ['stop', 'target'])->orderByDesc('created_at')->get() as $r) {
                $out[] = Orders::orderFromRow((array) $r);
            }
        }

        return $out;
    }

    private static function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /** @param  array<string,mixed>  $vals */
    private static function insertRow(string $table, array $vals): void
    {
        if ($pdo = self::pdo()) {
            $cols = array_keys($vals);
            $sql = 'INSERT INTO '.$table.' ('.implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')';
            try {
                $pdo->prepare($sql)->execute(array_values($vals));

                return;
            } catch (\Throwable $e) {
                // Desktop schema may lack later columns (seen_held / missed_at).
                unset($vals['seen_held'], $vals['missed_at']);
                $cols = array_keys($vals);
                $sql = 'INSERT INTO '.$table.' ('.implode(', ', $cols).') VALUES ('.implode(', ', array_fill(0, count($cols), '?')).')';
                $pdo->prepare($sql)->execute(array_values($vals));

                return;
            }
        }
        if (Schema::hasTable($table)) {
            DB::table($table)->insert($vals);
        }
    }

    /** @param  array<string,mixed>  $row */
    private static function updateRow(string $table, string $id, array $row): void
    {
        if ($pdo = self::pdo()) {
            $sets = array_keys($row);
            $assign = implode(', ', array_map(fn ($c) => $c.' = ?', $sets));
            $params = [];
            foreach ($sets as $c) {
                $params[] = $row[$c];
            }
            $params[] = $id;
            $pdo->prepare('UPDATE '.$table.' SET '.$assign.' WHERE id = ?')->execute($params);

            return;
        }
        if (Schema::hasTable($table)) {
            DB::table($table)->where('id', $id)->update($row);
        }
    }

    /** @return array<string,mixed>|null */
    private static function fetchById(string $table, string $id): ?array
    {
        if ($pdo = self::pdo()) {
            try {
                $st = $pdo->prepare('SELECT * FROM '.$table.' WHERE id = ?');
                $st->execute([$id]);
                $r = $st->fetch();
                if ($r) {
                    return $r;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        if (Schema::hasTable($table)) {
            $r = DB::table($table)->where('id', $id)->first();
            if ($r) {
                return (array) $r;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<array<string,mixed>>
     */
    private static function fetchAll(string $table, ?array $statuses = null): array
    {
        $out = [];
        if ($pdo = self::pdo()) {
            try {
                if ($statuses) {
                    $marks = implode(',', array_fill(0, count($statuses), '?'));
                    $st = $pdo->prepare('SELECT * FROM '.$table.' WHERE status IN ('.$marks.') ORDER BY created_at, rowid');
                    $st->execute(array_values($statuses));
                    foreach ($st as $r) {
                        $out[] = $r;
                    }
                } else {
                    foreach ($pdo->query('SELECT * FROM '.$table.' ORDER BY created_at, rowid') as $r) {
                        $out[] = $r;
                    }
                }

                return $out;
            } catch (\Throwable) {
                $out = [];
            }
        }
        if (Schema::hasTable($table)) {
            $q = DB::table($table)->orderBy('created_at');
            if ($statuses) {
                $q->whereIn('status', $statuses);
            }
            foreach ($q->get() as $r) {
                $out[] = (array) $r;
            }
        }

        return $out;
    }
    /** @return list<array<string,mixed>> */
    public static function listOrders(?array $statuses = null): array
    {
        $rows = self::fetchAll('orders', $statuses);
        return array_map(fn ($r) => \App\Journal\Orders::orderFromRow($r), $rows);
    }

    public static function bracketForOrder(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }
        foreach (self::listBrackets() as $b) {
            if (($b['orderId'] ?? '') === $orderId) {
                return $b;
            }
        }

        return null;
    }


    /** Desktop symbol_for_security — activity symbol (options keep contract name). */
    public static function symbolForSecurity(string $securityId): string
    {
        $securityId = trim($securityId);
        if ($securityId === '') {
            return '';
        }
        if ($pdo = self::pdo()) {
            try {
                $st = $pdo->prepare("SELECT symbol FROM activities WHERE security_id = ? AND symbol IS NOT NULL AND symbol != '' ORDER BY occurred_at DESC LIMIT 1");
                $st->execute([$securityId]);
                $r = $st->fetch();
                if ($r && trim((string) ($r['symbol'] ?? '')) !== '') {
                    return trim((string) $r['symbol']);
                }
            } catch (\Throwable) {
            }
        }
        if (Schema::hasTable('activities')) {
            $r = DB::table('activities')->where('security_id', $securityId)->whereNotNull('symbol')->where('symbol', '!=', '')
                ->orderByDesc('occurred_at')->value('symbol');
            if ($r) {
                return trim((string) $r);
            }
        }

        return '';
    }

    /** Desktop sold_since — Trade/SELL qty since armedAt. */
    public static function soldSince(string $accountId, string $securityId, string $sinceIso, string $symbol = ''): float
    {
        $accountId = trim($accountId);
        $securityId = trim($securityId);
        $sinceIso = trim($sinceIso);
        if ($accountId === '' || $sinceIso === '') {
            return 0.0;
        }
        if ($pdo = self::pdo()) {
            try {
                if ($securityId !== '') {
                    $st = $pdo->prepare("SELECT SUM(quantity) AS q FROM activities WHERE account_id = ? AND security_id = ? AND activity_type = 'Trade' AND activity_sub_type = 'SELL' AND occurred_at > ?");
                    $st->execute([$accountId, $securityId, $sinceIso]);
                } else {
                    $st = $pdo->prepare("SELECT SUM(quantity) AS q FROM activities WHERE account_id = ? AND symbol = ? AND activity_type = 'Trade' AND activity_sub_type = 'SELL' AND occurred_at > ?");
                    $st->execute([$accountId, $symbol, $sinceIso]);
                }
                $r = $st->fetch();
                if ($r && $r['q'] !== null) {
                    return (float) $r['q'];
                }
            } catch (\Throwable) {
            }
        }
        if (Schema::hasTable('activities')) {
            $q = DB::table('activities')->where('account_id', $accountId)
                ->where('activity_type', 'Trade')->where('activity_sub_type', 'SELL')
                ->where('occurred_at', '>', $sinceIso);
            if ($securityId !== '') {
                $q->where('security_id', $securityId);
            } else {
                $q->where('symbol', $symbol);
            }
            $sum = $q->sum('quantity');

            return $sum !== null ? (float) $sum : 0.0;
        }

        return 0.0;
    }

    /** Desktop position_quantity — balances sum; null when unknown. */
    public static function positionQuantity(string $accountId, string $securityId): ?float
    {
        $accountId = trim($accountId);
        $securityId = trim($securityId);
        if ($accountId === '' || $securityId === '') {
            return null;
        }
        if ($pdo = self::pdo()) {
            try {
                $st = $pdo->prepare('SELECT SUM(quantity) AS q FROM balances WHERE account_id = ? AND security_id = ?');
                $st->execute([$accountId, $securityId]);
                $r = $st->fetch();
                if ($r === false) {
                    return null;
                }
                if ($r['q'] === null) {
                    return null;
                }

                return (float) $r['q'];
            } catch (\Throwable) {
            }
        }
        if (Schema::hasTable('balances')) {
            $sum = DB::table('balances')->where('account_id', $accountId)->where('security_id', $securityId)->sum('quantity');

            return $sum !== null ? (float) $sum : null;
        }

        return null;
    }

    /**
     * Cash qty for account+currency from balances + cash securities (sec-c-*).
     */
    public static function cashBalance(string $accountId, string $currency = 'CAD'): ?float
    {
        $accountId = trim($accountId);
        $currency = strtoupper(trim($currency) ?: 'CAD');
        if ($accountId === '') {
            return null;
        }
        $cashIds = [];
        if ($pdo = self::pdo()) {
            try {
                foreach ($pdo->query("SELECT id, symbol, currency FROM securities WHERE id LIKE 'sec-c-%' OR lower(symbol) IN ('cad','usd')") as $r) {
                    $ccy = strtoupper(trim((string) ($r['currency'] ?? ''))) ?: strtoupper(trim((string) ($r['symbol'] ?? '')));
                    if ($ccy === $currency) {
                        $cashIds[] = (string) $r['id'];
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($cashIds === [] && Schema::hasTable('securities')) {
            foreach (DB::table('securities')->where('id', 'like', 'sec-c-%')->orWhereIn('symbol', ['CAD', 'USD', 'cad', 'usd'])->get() as $r) {
                $ccy = strtoupper(trim((string) ($r->currency ?? ''))) ?: strtoupper(trim((string) ($r->symbol ?? '')));
                if ($ccy === $currency) {
                    $cashIds[] = (string) $r->id;
                }
            }
        }
        if ($cashIds === []) {
            // Common Wealthsimple cash security ids.
            $cashIds = $currency === 'USD' ? ['sec-c-usd'] : ['sec-c-cad'];
        }
        $total = null;
        if ($pdo = self::pdo()) {
            try {
                $marks = implode(',', array_fill(0, count($cashIds), '?'));
                $st = $pdo->prepare("SELECT SUM(quantity) AS q FROM balances WHERE account_id = ? AND security_id IN ($marks)");
                $st->execute(array_merge([$accountId], $cashIds));
                $r = $st->fetch();
                if ($r && $r['q'] !== null) {
                    $total = (float) $r['q'];
                }
            } catch (\Throwable) {
            }
        }
        if ($total === null && Schema::hasTable('balances')) {
            $sum = DB::table('balances')->where('account_id', $accountId)->whereIn('security_id', $cashIds)->sum('quantity');
            if ($sum !== null) {
                $total = (float) $sum;
            }
        }

        return $total;
    }

}
