<?php

namespace App\Journal;

use App\Wealthsimple\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Read-only Orders panel data: desktop bagholder.db first, Laravel tables as fallback.
 * Place/cancel/modify via App\Wealthsimple\OrderService (BAGHOLDER_DRY_ORDERS).
 */
final class Orders
{
    public const LIVE = ['sent' => true, 'pending' => true, 'cancelling' => true];

    public const BRACKET_LIVE = [
        'waiting' => true,
        'armed' => true,
        'firing' => true,
        'target_placed' => true,
        'stopping' => true,
        'closing' => true,
    ];

    public const TABS = ['pending', 'filled', 'cancelled'];

    /**
     * @return array{ok:bool,orders:list<array<string,mixed>>,brackets:list<array<string,mixed>>,live:bool,source:string,error?:string}
     */
    public static function payload(?string $path = null): array
    {
        $path = $path ?: MarketImport::defaultPath();
        if (is_file($path)) {
            try {
                $pdo = new PDO('sqlite:'.$path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec('PRAGMA query_only = ON');
                $exchanges = [];
                try {
                    foreach ($pdo->query('SELECT id, primary_exchange FROM securities') as $s) {
                        $exchanges[(string) $s['id']] = (string) ($s['primary_exchange'] ?? '');
                    }
                } catch (\Throwable $e) {
                    // older DBs
                }
                $orders = [];
                foreach ($pdo->query('SELECT * FROM orders ORDER BY created_at DESC, rowid DESC LIMIT 200') as $r) {
                    $o = self::orderFromRow($r);
                    $o['exchange'] = $exchanges[$o['securityId'] ?? ''] ?? '';
                    $orders[] = $o;
                }
                $brackets = [];
                try {
                    foreach ($pdo->query('SELECT * FROM brackets ORDER BY created_at DESC, rowid DESC LIMIT 100') as $r) {
                        $brackets[] = self::bracketFromRow($r);
                    }
                } catch (\Throwable $e) {
                    // brackets optional
                }

                return [
                    'ok' => true,
                    'orders' => $orders,
                    'brackets' => $brackets,
                    'live' => OrderService::ordersLive(),
                    'source' => 'desktop',
                    'refreshedAt' => null,
                ];
            } catch (\Throwable $e) {
                // fall through to Laravel tables
                $err = $e->getMessage();
            }
        }

        if (! Schema::hasTable('orders')) {
            return [
                'ok' => false,
                'orders' => [],
                'brackets' => [],
                'live' => OrderService::ordersLive(),
                'source' => 'none',
                'error' => $err ?? 'No orders source (desktop DB missing, tables empty).',
            ];
        }

        $exchanges = [];
        if (Schema::hasTable('securities')) {
            foreach (DB::table('securities')->select('id', 'primary_exchange')->get() as $s) {
                $exchanges[(string) $s->id] = (string) ($s->primary_exchange ?? '');
            }
        }
        $orders = [];
        foreach (DB::table('orders')->orderByDesc('created_at')->limit(200)->get() as $r) {
            $o = self::orderFromRow((array) $r);
            if (($o['exchange'] ?? '') === '') {
                $o['exchange'] = $exchanges[$o['securityId'] ?? ''] ?? '';
            }
            $orders[] = $o;
        }
        $brackets = [];
        if (Schema::hasTable('brackets')) {
            foreach (DB::table('brackets')->orderByDesc('created_at')->limit(100)->get() as $r) {
                $brackets[] = self::bracketFromRow((array) $r);
            }
        }

        return [
            'ok' => true,
            'orders' => $orders,
            'brackets' => $brackets,
            'live' => OrderService::ordersLive(),
            'source' => 'laravel',
            'refreshedAt' => null,
        ];
    }

    /** Open entry orders + armed brackets (badge count). */
    public static function openCount(?array $payload = null): int
    {
        $payload ??= self::payload();
        $n = 0;
        foreach ($payload['orders'] ?? [] as $o) {
            $role = $o['role'] ?? 'entry';
            if ($role === 'stop' || $role === 'target') {
                continue;
            }
            if (isset(self::LIVE[$o['status'] ?? ''])) {
                $n++;
            }
        }
        foreach ($payload['brackets'] ?? [] as $b) {
            $st = $b['status'] ?? '';
            if (isset(self::BRACKET_LIVE[$st]) && $st !== 'waiting') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Cards for one tab. Account scope: empty = all.
     *
     * @param  list<string>  $accountScope
     * @return list<array{kind:string,at:string,order?:array,bracket?:array}>
     */
    public static function cardsForTab(string $tab, array $payload, array $accountScope = []): array
    {
        $ordersById = [];
        foreach ($payload['orders'] ?? [] as $o) {
            $ordersById[$o['id']] = $o;
        }
        $inScope = function (string $account) use ($accountScope): bool {
            return $accountScope === [] || in_array($account, $accountScope, true);
        };
        $entries = array_values(array_filter($payload['orders'] ?? [], function ($o) use ($inScope) {
            $role = $o['role'] ?? 'entry';
            if ($role === 'stop' || $role === 'target') {
                return false;
            }

            return $inScope((string) ($o['account'] ?? ''));
        }));

        if ($tab === 'filled') {
            $rows = array_values(array_filter($entries, fn ($o) => ($o['status'] ?? '') === 'filled'));
            usort($rows, fn ($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

            return array_map(fn ($o) => ['kind' => 'order', 'at' => $o['createdAt'] ?? '', 'order' => $o], $rows);
        }
        if ($tab === 'cancelled') {
            $ended = ['cancelled', 'expired', 'rejected', 'failed', 'dry'];
            $rows = array_values(array_filter($entries, fn ($o) => in_array($o['status'] ?? '', $ended, true)));
            usort($rows, fn ($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

            return array_map(fn ($o) => ['kind' => 'order', 'at' => $o['createdAt'] ?? '', 'order' => $o], $rows);
        }

        // pending
        $cards = [];
        foreach ($entries as $o) {
            if (isset(self::LIVE[$o['status'] ?? ''])) {
                $cards[] = ['kind' => 'order', 'at' => $o['createdAt'] ?? '', 'order' => $o];
            }
        }
        foreach ($payload['brackets'] ?? [] as $b) {
            $st = $b['status'] ?? '';
            if (! isset(self::BRACKET_LIVE[$st]) || $st === 'waiting') {
                continue;
            }
            $entry = $ordersById[$b['orderId'] ?? ''] ?? null;
            $acct = $entry['account'] ?? '';
            if ($entry && ! $inScope((string) $acct)) {
                continue;
            }
            $cards[] = ['kind' => 'bracket', 'at' => $b['armedAt'] ?: ($b['createdAt'] ?? ''), 'bracket' => $b, 'order' => $entry];
        }
        usort($cards, fn ($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));

        return $cards;
    }

    /** @param  array<string,mixed>  $r */
    public static function orderFromRow(array $r): array
    {
        $js = function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            if (is_array($v)) {
                return $v;
            }
            $d = json_decode((string) $v, true);

            return is_array($d) ? $d : null;
        };

        return [
            'id' => (string) ($r['id'] ?? ''),
            'createdAt' => (string) ($r['created_at'] ?? $r['createdAt'] ?? ''),
            'accountId' => (string) ($r['account_id'] ?? $r['accountId'] ?? ''),
            'account' => (string) ($r['account'] ?? ''),
            'securityId' => (string) ($r['security_id'] ?? $r['securityId'] ?? ''),
            'symbol' => (string) ($r['symbol'] ?? ''),
            'currency' => (string) ($r['currency'] ?? ''),
            'side' => (string) ($r['side'] ?? ''),
            'type' => (string) ($r['type'] ?? ''),
            'quantity' => isset($r['quantity']) ? (float) $r['quantity'] : 0.0,
            'limitPrice' => isset($r['limit_price']) ? ($r['limit_price'] !== null ? (float) $r['limit_price'] : null) : ($r['limitPrice'] ?? null),
            'stopPrice' => isset($r['stop_price']) ? ($r['stop_price'] !== null ? (float) $r['stop_price'] : null) : ($r['stopPrice'] ?? null),
            'tif' => (string) ($r['tif'] ?? ''),
            'stopLoss' => $js($r['stop_loss'] ?? $r['stopLoss'] ?? null),
            'takeProfit' => $js($r['take_profit'] ?? $r['takeProfit'] ?? null),
            'status' => (string) ($r['status'] ?? ''),
            'wsOrderId' => (string) ($r['ws_order_id'] ?? $r['wsOrderId'] ?? ''),
            'error' => (string) ($r['error'] ?? ''),
            'updatedAt' => (string) ($r['updated_at'] ?? $r['updatedAt'] ?? ''),
            'source' => (string) ($r['source'] ?? 'bagholder'),
            'wsStatus' => (string) ($r['ws_status'] ?? $r['wsStatus'] ?? ''),
            'filledQty' => isset($r['filled_qty']) ? ($r['filled_qty'] !== null ? (float) $r['filled_qty'] : null) : ($r['filledQty'] ?? null),
            'avgFill' => isset($r['avg_fill']) ? ($r['avg_fill'] !== null ? (float) $r['avg_fill'] : null) : ($r['avgFill'] ?? null),
            'submittedAt' => (string) ($r['submitted_at'] ?? $r['submittedAt'] ?? ''),
            'expiresAt' => (string) ($r['expires_at'] ?? $r['expiresAt'] ?? ''),
            'parentId' => (string) ($r['parent_id'] ?? $r['parentId'] ?? ''),
            'role' => (string) ($r['role'] ?? 'entry') ?: 'entry',
            'exchange' => (string) ($r['exchange'] ?? ''),
        ];
    }

    /** @param  array<string,mixed>  $r */
    public static function bracketFromRow(array $r): array
    {
        return [
            'id' => (string) ($r['id'] ?? ''),
            'orderId' => (string) ($r['order_id'] ?? $r['orderId'] ?? ''),
            'createdAt' => (string) ($r['created_at'] ?? $r['createdAt'] ?? ''),
            'accountId' => (string) ($r['account_id'] ?? $r['accountId'] ?? ''),
            'securityId' => (string) ($r['security_id'] ?? $r['securityId'] ?? ''),
            'symbol' => (string) ($r['symbol'] ?? ''),
            'currency' => (string) ($r['currency'] ?? ''),
            'quantity' => isset($r['quantity']) ? (float) $r['quantity'] : 0.0,
            'tif' => (string) ($r['tif'] ?? 'DAY'),
            'slKind' => (string) ($r['sl_kind'] ?? $r['slKind'] ?? ''),
            'slPrice' => isset($r['sl_price']) ? ($r['sl_price'] !== null ? (float) $r['sl_price'] : null) : ($r['slPrice'] ?? null),
            'slTrail' => isset($r['sl_trail']) ? ($r['sl_trail'] !== null ? (float) $r['sl_trail'] : null) : ($r['slTrail'] ?? null),
            'slTrailUnit' => (string) ($r['sl_trail_unit'] ?? $r['slTrailUnit'] ?? 'pct'),
            'slOrderId' => (string) ($r['sl_order_id'] ?? $r['slOrderId'] ?? ''),
            'slNative' => (bool) ($r['sl_native'] ?? $r['slNative'] ?? false),
            'slMode' => (string) ($r['sl_mode'] ?? $r['slMode'] ?? ''),
            'highWater' => isset($r['high_water']) ? ($r['high_water'] !== null ? (float) $r['high_water'] : null) : ($r['highWater'] ?? null),
            'tpPrice' => isset($r['tp_price']) ? ($r['tp_price'] !== null ? (float) $r['tp_price'] : null) : ($r['tpPrice'] ?? null),
            'tpOrderId' => (string) ($r['tp_order_id'] ?? $r['tpOrderId'] ?? ''),
            'status' => (string) ($r['status'] ?? ''),
            'outcome' => (string) ($r['outcome'] ?? ''),
            'error' => (string) ($r['error'] ?? ''),
            'attempts' => (int) ($r['attempts'] ?? 0),
            'movedAt' => (string) ($r['moved_at'] ?? $r['movedAt'] ?? ''),
            'armedAt' => (string) ($r['armed_at'] ?? $r['armedAt'] ?? ''),
            'seenHeld' => (bool) ($r['seen_held'] ?? $r['seenHeld'] ?? false),
            'missedAt' => (string) ($r['missed_at'] ?? $r['missedAt'] ?? ''),
            'updatedAt' => (string) ($r['updated_at'] ?? $r['updatedAt'] ?? ''),
        ];
    }

    public static function typeWord(string $t): string
    {
        return match ($t) {
            'MARKET' => 'Market',
            'LIMIT' => 'Limit',
            'STOP' => 'Stop',
            'STOP_LIMIT' => 'Stop limit',
            default => $t !== '' ? ucfirst(strtolower(str_replace('_', ' ', $t))) : '—',
        };
    }

    public static function px(?float $n): string
    {
        if ($n === null || ! is_finite($n)) {
            return '—';
        }
        $digits = abs($n) >= 1 ? 2 : 4;

        return number_format($n, $digits, '.', ',');
    }

    public static function qty(float $n): string
    {
        if (abs($n - round($n)) < 0.0001) {
            return number_format((int) round($n), 0, '.', ',');
        }

        return rtrim(rtrim(number_format($n, 4, '.', ','), '0'), '.');
    }

    public static function orderPrice(array $o): string
    {
        $type = $o['type'] ?? '';
        if ($type === 'MARKET') {
            return '—';
        }
        if ($type === 'STOP') {
            return self::px(isset($o['stopPrice']) ? (float) $o['stopPrice'] : null);
        }
        if ($type === 'STOP_LIMIT') {
            return 'Stop '.self::px(isset($o['stopPrice']) ? (float) $o['stopPrice'] : null)
                .' · '.self::px(isset($o['limitPrice']) ? (float) $o['limitPrice'] : null);
        }

        return self::px(isset($o['limitPrice']) ? (float) $o['limitPrice'] : null);
    }

    public static function detailLine(array $o): string
    {
        if (($o['status'] ?? '') === 'filled') {
            $fq = $o['filledQty'] ?? $o['quantity'] ?? 0;

            return 'Filled '.self::qty((float) $fq).(! empty($o['avgFill']) ? ' at '.self::px((float) $o['avgFill']) : '');
        }
        $tif = ($o['tif'] ?? '') === 'DAY' ? 'Day' : (($o['tif'] ?? '') === 'UNTIL_CANCEL' ? 'GTC' : '');
        $type = $o['type'] ?? '';
        if ($type === 'MARKET') {
            $how = 'at market';
        } elseif ($type === 'STOP_LIMIT') {
            $how = 'stop '.self::px(isset($o['stopPrice']) ? (float) $o['stopPrice'] : null)
                .' · limit '.self::px(isset($o['limitPrice']) ? (float) $o['limitPrice'] : null);
        } else {
            $how = 'at '.self::orderPrice($o).' '.strtolower(self::typeWord($type));
        }

        return self::qty((float) ($o['quantity'] ?? 0)).' '.$how.($tif !== '' ? ' · '.$tif : '');
    }

    public static function fillLine(array $o): string
    {
        $filled = (float) ($o['filledQty'] ?? 0);
        $status = $o['status'] ?? '';
        if ($status === 'rejected' || $status === 'failed') {
            return (string) ($o['error'] ?? '');
        }
        if ($status !== 'filled' && $filled > 0 && $filled < (float) ($o['quantity'] ?? 0)) {
            return self::qty($filled).' of '.self::qty((float) $o['quantity']).' filled'
                .(! empty($o['avgFill']) ? ' at '.self::px((float) $o['avgFill']) : '');
        }

        return '';
    }

    /** @return array{0:string,1:string} label, tone */
    public static function pill(array $o): array
    {
        $filled = (float) ($o['filledQty'] ?? 0);
        $qty = (float) ($o['quantity'] ?? 0);

        return match ($o['status'] ?? '') {
            'pending' => ($filled > 0 && $filled < $qty) ? ['Partially filled', 'accent'] : ['Pending', 'accent'],
            'sent' => ['Pending', 'accent'],
            'cancelling' => ['Cancelling', 'accent'],
            'filled' => ['Filled', 'pos'],
            'cancelled' => ['Cancelled', ''],
            'expired' => ['Expired', ''],
            'rejected' => ['Rejected', 'neg'],
            'failed' => ['Failed', 'neg'],
            'dry' => ['Not sent', ''],
            default => [($o['status'] ?? '—') ?: '—', ''],
        };
    }

    public static function whenWord(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        $t = strtotime($iso);
        if ($t === false) {
            return '—';
        }
        // Display in America/Edmonton (user zone)
        $tz = new \DateTimeZone('America/Edmonton');
        $d = (new \DateTimeImmutable('@'.$t))->setTimezone($tz);
        $now = new \DateTimeImmutable('now', $tz);
        $h = (int) $d->format('g');
        $m = $d->format('i');
        $ap = $d->format('A');
        $time = $h.':'.$m.' '.$ap;
        if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Today '.$time;
        }
        $mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $label = $mon[(int) $d->format('n') - 1].' '.(int) $d->format('j');
        if ($d->format('Y') !== $now->format('Y')) {
            $label .= ' '.$d->format('Y');
        }

        return $label.', '.$time;
    }

    public static function isOptionSymbol(string $symbol): bool
    {
        return (bool) preg_match('/\s\d{2}[A-Z]{3}\d{2}\s[\d.]+\s(CALL|PUT)$/', $symbol);
    }

    public static function multiplier(array $o): int
    {
        return self::isOptionSymbol((string) ($o['symbol'] ?? '')) ? 100 : 1;
    }

    public static function value(array $o): string
    {
        $mult = self::multiplier($o);
        $status = $o['status'] ?? '';
        if ($status === 'filled' && ! empty($o['avgFill'])) {
            $fq = (float) ($o['filledQty'] ?? $o['quantity'] ?? 0);

            return \App\Support\Money::formatCad($fq * (float) $o['avgFill'] * $mult, 2);
        }
        $type = $o['type'] ?? '';
        $price = $type === 'MARKET' ? ($o['avgFill'] ?? null) : ($type === 'STOP' ? ($o['stopPrice'] ?? null) : ($o['limitPrice'] ?? null));
        if (! ($price > 0)) {
            return '';
        }
        $money = \App\Support\Money::formatCad((float) ($o['quantity'] ?? 0) * (float) $price * $mult, 2);

        return $type === 'MARKET' ? '≈ '.$money : $money;
    }

    /**
     * Bracket legs for display.
     *
     * @return list<array{label:string,tone:string,line:string,amount:string,note:string}>
     */
    public static function bracketLegs(array $b, ?array $entry = null): array
    {
        $legs = [];
        $mult = $entry ? self::multiplier($entry) : 1;
        $qty = (float) ($b['quantity'] ?? 0);
        $amount = function (?float $price) use ($qty, $mult): string {
            if (! ($price > 0) || ! ($qty > 0)) {
                return '';
            }

            return \App\Support\Money::formatCad($qty * $price * $mult, 2);
        };
        if (! empty($b['slKind'])) {
            $line = self::qty($qty).' at '.self::px(isset($b['slPrice']) ? (float) $b['slPrice'] : null);
            if (($b['slKind'] ?? '') === 'trail') {
                $trail = ($b['slTrailUnit'] ?? '') === 'amt'
                    ? self::px(isset($b['slTrail']) ? (float) $b['slTrail'] : null)
                    : (isset($b['slTrail']) ? rtrim(rtrim(number_format((float) $b['slTrail'], 2, '.', ''), '0'), '.').'%' : '—');
                $line .= ' · trailing '.$trail;
            }
            $legs[] = [
                'label' => 'Stop loss',
                'tone' => 'neg',
                'line' => $line,
                'amount' => $amount(isset($b['slPrice']) ? (float) $b['slPrice'] : null),
                'note' => '',
            ];
        }
        if (! empty($b['tpPrice'])) {
            $line = self::qty($qty).' at '.self::px((float) $b['tpPrice']);
            $legs[] = [
                'label' => 'Take profit',
                'tone' => 'pos',
                'line' => $line,
                'amount' => $amount((float) $b['tpPrice']),
                'note' => '',
            ];
        }

        return $legs;
    }
}
