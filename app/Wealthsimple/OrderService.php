<?php

namespace App\Wealthsimple;

use App\Models\Meta;
use Illuminate\Support\Str;

/**
 * Place / cancel / modify — ported from desktop bagholder place_order / cancel_order / modify_order.
 * BAGHOLDER_DRY_ORDERS=1 (or unset treated as live only when explicitly not "1") mirrors desktop:
 * dry means nothing is sent to Wealthsimple.
 */
final class OrderService
{
    public const EXEC_TYPES = ['MARKET', 'LIMIT', 'STOP', 'STOP_LIMIT'];

    public const TIFS = ['DAY', 'UNTIL_CANCEL'];

    public const LIVE_STATUSES = ['sent', 'pending', 'cancelling'];

    /** Desktop BRACKET_LIVE — waiting is live but not released on a ticket SELL. */
    public const BRACKET_LIVE = ['waiting', 'armed', 'firing', 'target_placed', 'stopping', 'closing'];

    public const BRACKET_RESTING = ['sent', 'pending'];

    /** Desktop BRACKET_TIF: exits always GTC. */
    public const BRACKET_TIF = 'UNTIL_CANCEL';

    /** Desktop ORDERS_LIVE: false when BAGHOLDER_DRY_ORDERS=1. */
    public static function ordersLive(): bool
    {
        $env = getenv('BAGHOLDER_DRY_ORDERS');
        if ($env === false) {
            $env = $_ENV['BAGHOLDER_DRY_ORDERS'] ?? $_SERVER['BAGHOLDER_DRY_ORDERS'] ?? env('BAGHOLDER_DRY_ORDERS', '1');
        }

        return trim((string) $env) !== '1';
    }

    public static function dryNotice(string $detail = ''): string
    {
        $base = 'Not sent (orders are off)';

        return $detail !== '' ? $base.' · '.$detail : $base;
    }

    /**
     * Desktop ticket_quote (live session or Meta fallback).
     *
     * @return array<string,mixed>
     */
    public static function quote(string $symbol = '', string $securityId = '', string $accountId = '', string $exchange = ''): array
    {
        return TicketQuote::quote($symbol, $securityId, $accountId, $exchange);
    }

    /**
     * Desktop tkNotice: append ", stop X, target Y" when brackets set.
     *
     * @param  array<string,mixed>  $row  placeOrder row (optional stopLoss/takeProfit)
     */
    public static function dryNoticeWithBrackets(string $detail, array $row = []): string
    {
        $base = self::dryNotice($detail);
        $sl = is_array($row['stopLoss'] ?? null) ? $row['stopLoss'] : null;
        $tp = is_array($row['takeProfit'] ?? null) ? $row['takeProfit'] : null;
        if ($sl && isset($sl['price']) && is_numeric($sl['price'])) {
            $base .= ', stop '.\App\Journal\Orders::px((float) $sl['price']);
        }
        if ($tp && isset($tp['price']) && is_numeric($tp['price'])) {
            $base .= ', target '.\App\Journal\Orders::px((float) $tp['price']);
        }

        return $base;
    }

    /**
     * Desktop cancel_bracket — end live bracket (dry: no book cancel sent).
     *
     * @return array<string,mixed>
     */
    public static function cancelBracket(string $bracketId): array
    {
        $b = OrderStore::getBracket($bracketId);
        if (! $b) {
            return ['ok' => false, 'error' => 'No such bracket.'];
        }
        if (! in_array($b['status'] ?? '', self::BRACKET_LIVE, true)) {
            return ['ok' => false, 'error' => 'That bracket is not live.'];
        }
        if (! self::ordersLive()) {
            self::endBracket($b, 'cancelled by the user');

            return [
                'ok' => true,
                'id' => $b['id'],
                'status' => 'dry',
                'dry' => true,
                'notice' => 'Bracket cancel recorded locally (orders are off)',
            ];
        }
        self::endBracket($b, 'cancelled by the user');

        return ['ok' => true, 'id' => $b['id']];
    }

    /**
     * Session ready for GraphQL ticket calls (refresh near expiry). Null when not connected / dry marker only.
     *
     * @return array<string,mixed>|null
     */
    public static function ticketSession(): ?array
    {
        $sess = SessionStore::load();
        if ($sess === [] || SessionStore::isDry($sess)) {
            return null;
        }
        $access = trim((string) ($sess['access_token'] ?? ''));
        $refresh = trim((string) ($sess['refresh_token'] ?? ''));
        if ($access === '' && $refresh === '') {
            return null;
        }
        try {
            self::ensureFresh($sess);
            $sess = SessionStore::load() ?: $sess;
        } catch (\Throwable) {
            // keep current tokens; live call may still fail cleanly
        }

        return $sess;
    }

    /** @param  array<string,mixed>  $sess */
    public static function ensureFresh(array $sess): array
    {
        if (SessionStore::isDry($sess)) {
            return $sess;
        }
        $exp = self::expiresAtUnix($sess);
        if ($exp > 0 && $exp >= time() + 120) {
            return $sess;
        }
        if (trim((string) ($sess['refresh_token'] ?? '')) === '') {
            return $sess;
        }

        return Api::refresh($sess);
    }

    /** @param  array<string,mixed>  $sess */
    public static function expiresAtUnix(array $sess): int
    {
        $raw = $sess['expires_at'] ?? 0;
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return 0;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $t = strtotime($s);

        return $t === false ? 0 : $t;
    }

    public static function orderTick(mixed $price): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }
        $n = (float) $price;
        if (! is_finite($n)) {
            return null;
        }

        return round($n, $n >= 1 ? 2 : 4);
    }

    /**
     * Validate ticket and build Wealthsimple create input.
     *
     * @param  array<string,mixed>  $body
     * @return array{0:?array,1:?array,2:string} row, request, error
     */
    public static function orderRequest(array $body): array
    {
        $side = strtoupper(trim((string) ($body['side'] ?? '')));
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            return [null, null, 'Side must be Buy or Sell.'];
        }
        $execType = strtoupper(trim((string) ($body['type'] ?? '')));
        if (! in_array($execType, self::EXEC_TYPES, true)) {
            return [null, null, 'Order type must be Market, Limit, Stop or Stop limit.'];
        }
        $tif = strtoupper(trim((string) ($body['tif'] ?? 'DAY')));
        if (! in_array($tif, self::TIFS, true)) {
            return [null, null, 'Time in force must be Day or Good till cancelled.'];
        }
        $qty = (float) ($body['quantity'] ?? $body['qty'] ?? 0);
        if (! ($qty > 0)) {
            return [null, null, 'Quantity must be more than zero.'];
        }
        $limitPrice = self::orderTick($body['limitPrice'] ?? $body['limit'] ?? null);
        $stopPrice = self::orderTick($body['stopPrice'] ?? $body['stop'] ?? null);
        if (in_array($execType, ['LIMIT', 'STOP_LIMIT'], true) && ! ($limitPrice > 0)) {
            return [null, null, 'A limit price is required.'];
        }
        if (in_array($execType, ['STOP', 'STOP_LIMIT'], true) && ! ($stopPrice > 0)) {
            return [null, null, 'A stop price is required.'];
        }
        $accounts = OrderStore::orderAccounts();
        $accountId = trim((string) ($body['accountId'] ?? ''));
        $acct = null;
        foreach ($accounts as $a) {
            if ($a['id'] === $accountId) {
                $acct = $a;
                break;
            }
        }
        if (! $acct && $accountId === '' && count($accounts) === 1) {
            $acct = $accounts[0];
        }
        if (! $acct && $accountId === '' && $accounts !== []) {
            // Prefer TFSA trading nick when filter empty — first tradable
            $acct = $accounts[0];
            foreach ($accounts as $a) {
                if (str_contains(strtoupper($a['type']), 'TFSA')) {
                    $acct = $a;
                    break;
                }
            }
        }
        if (! $acct) {
            return [null, null, 'Choose an account.'];
        }
        $sec = OrderStore::resolveSecurity(
            (string) ($body['symbol'] ?? ''),
            (string) ($body['securityId'] ?? '')
        );
        if (! $sec) {
            return [null, null, 'No listing stored for '.trim((string) ($body['symbol'] ?? '')).'.'];
        }
        $sl = self::dict($body['stopLoss'] ?? null);
        $tp = self::dict($body['takeProfit'] ?? null);
        if ($side === 'SELL') {
            $sl = null;
            $tp = null; // nothing to protect: the shares leave
        }
        if ($sl) {
            $kind = strtolower(trim((string) ($sl['kind'] ?? 'stop')));
            if (! in_array($kind, ['stop', 'trail'], true)) {
                return [null, null, 'Stop loss type must be Stop or Trailing stop.'];
            }
            if ($kind === 'stop' && ! (self::num($sl['price'] ?? null, 0) > 0)) {
                return [null, null, 'A stop loss price is required.'];
            }
            if ($kind === 'trail' && ! (self::num($sl['trail'] ?? null, 0) > 0)) {
                return [null, null, 'A trail is required.'];
            }
            $sl = [
                'kind' => $kind,
                'price' => self::orderTick(self::num($sl['price'] ?? null, null)),
                'trail' => self::num($sl['trail'] ?? null, null),
                'trailUnit' => strtolower(trim((string) ($sl['trailUnit'] ?? ''))) === 'amt' ? 'amt' : 'pct',
            ];
        }
        if ($tp) {
            if (! (self::num($tp['price'] ?? null, 0) > 0)) {
                return [null, null, 'A take profit price is required.'];
            }
            $tp = ['price' => self::orderTick(self::num($tp['price'] ?? null, null))];
        }
        $oid = 'order-'.(string) Str::uuid();
        $req = [
            'canonicalAccountId' => $acct['id'],
            'externalId' => $oid,
            'executionType' => $execType,
            'orderType' => $side.'_QUANTITY',
            'quantity' => $qty,
            'securityId' => $sec['id'],
            'timeInForce' => $tif,
        ];
        if (in_array($execType, ['LIMIT', 'STOP_LIMIT'], true)) {
            $req['limitPrice'] = $limitPrice;
        }
        if (in_array($execType, ['STOP', 'STOP_LIMIT'], true)) {
            $req['stopPrice'] = $stopPrice;
        }
        $row = [
            'id' => $oid,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'accountId' => $acct['id'],
            'account' => $acct['name'],
            'securityId' => $sec['id'],
            'symbol' => (string) ($sec['symbol'] ?? ''),
            'currency' => strtoupper(trim((string) ($body['currency'] ?? $sec['currency'] ?? ''))),
            'side' => $side,
            'type' => $execType,
            'quantity' => $qty,
            'limitPrice' => $req['limitPrice'] ?? null,
            'stopPrice' => $req['stopPrice'] ?? null,
            'tif' => $tif,
            'stopLoss' => $sl,
            'takeProfit' => $tp,
            'status' => '',
            'wsOrderId' => '',
            'error' => '',
            'request' => $req,
            'exchange' => (string) ($sec['primaryExchange'] ?? ''),
            'role' => 'entry',
            'source' => 'bagholder',
        ];

        return [$row, $req, ''];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public static function submitOrder(array $row, array $req): array
    {
        if (! self::ordersLive()) {
            $row['status'] = 'dry';
            OrderStore::insertOrder($row);
            logger()->info('bagholder order (dry run, not sent)', ['request' => $req]);

            return [
                'ok' => true,
                'id' => $row['id'],
                'status' => 'dry',
                'order' => $row,
                'notice' => self::dryNoticeWithBrackets($row['side'].' '.$row['quantity'].' '.$row['symbol'], $row),
            ];
        }
        $sess = self::ticketSession();
        if (! $sess) {
            return ['ok' => false, 'error' => 'Not connected.'];
        }
        $row['status'] = 'sending';
        OrderStore::insertOrder($row);
        try {
            $data = Api::graphql($sess, 'SoOrdersOrderCreate', ['input' => $req], Queries::Q_SO_ORDERS_ORDER_CREATE);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: $e::class;
            OrderStore::updateOrder($row['id'], ['status' => 'failed', 'error' => $msg]);

            return ['ok' => false, 'error' => 'Order failed: '.$msg, 'id' => $row['id']];
        }
        $result = $data['soOrdersCreateOrder'] ?? [];
        $errs = $result['errors'] ?? [];
        if ($errs !== []) {
            $first = is_array($errs[0] ?? null) ? $errs[0] : ['message' => (string) ($errs[0] ?? '')];
            $msg = trim((string) ($first['message'] ?? $first['code'] ?? 'rejected'));
            OrderStore::updateOrder($row['id'], ['status' => 'rejected', 'error' => $msg]);

            return ['ok' => false, 'error' => 'Wealthsimple rejected the order: '.$msg, 'id' => $row['id']];
        }
        $order = $result['order'] ?? [];
        $wsId = (string) ($order['orderId'] ?? '');
        OrderStore::updateOrder($row['id'], ['status' => 'sent', 'wsOrderId' => $wsId]);

        return ['ok' => true, 'id' => $row['id'], 'status' => 'sent', 'wsOrderId' => $wsId];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public static function placeOrder(array $body): array
    {
        [$row, $req, $error] = self::orderRequest($body);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }
        if (($row['side'] ?? '') === 'SELL') {
            // A position's shares are all held by the one order resting on them. A bracket's
            // exit on these shares is cancelled, and confirmed gone, before the sell goes out.
            // Selling part of them: the bracket keeps the rest and places its stop on them again.
            self::releaseBracketsForSell($row);
        }
        $r = self::submitOrder($row, $req);
        if (! empty($r['ok']) && (($row['stopLoss'] ?? null) || ($row['takeProfit'] ?? null))) {
            $b = self::createBracket($row);
            $r['bracketId'] = $b['id'] ?? '';
        }

        return $r;
    }

    /**
     * Desktop create_bracket: waiting row for the ticket's SL/TP until the entry fills.
     *
     * @param  array<string,mixed>  $orderRow
     * @return array<string,mixed>
     */
    public static function createBracket(array $orderRow): array
    {
        $sl = is_array($orderRow['stopLoss'] ?? null) ? $orderRow['stopLoss'] : [];
        $tp = is_array($orderRow['takeProfit'] ?? null) ? $orderRow['takeProfit'] : [];
        $b = [
            'id' => 'bracket-'.(string) Str::uuid(),
            'orderId' => (string) ($orderRow['id'] ?? ''),
            'accountId' => (string) ($orderRow['accountId'] ?? ''),
            'securityId' => (string) ($orderRow['securityId'] ?? ''),
            'symbol' => (string) ($orderRow['symbol'] ?? ''),
            'currency' => (string) ($orderRow['currency'] ?? ''),
            'quantity' => $orderRow['quantity'] ?? null,
            'tif' => self::BRACKET_TIF,
            'slKind' => (string) ($sl['kind'] ?? ''),
            'slPrice' => $sl['price'] ?? null,
            'slTrail' => $sl['trail'] ?? null,
            'slTrailUnit' => (string) ($sl['trailUnit'] ?? 'pct') ?: 'pct',
            'tpPrice' => $tp['price'] ?? null,
            'status' => 'waiting',
        ];
        OrderStore::insertBracket($b);
        logger()->info('bagholder bracket (waiting)', ['id' => $b['id'], 'symbol' => $b['symbol'], 'orderId' => $b['orderId']]);

        return OrderStore::getBracket($b['id']) ?? $b;
    }

    /**
     * @param  array<string,mixed>  $row  the ticket sell
     */
    public static function releaseBracketsForSell(array $row): void
    {
        $left = (float) ($row['quantity'] ?? 0);
        if (! ($left > 0)) {
            return;
        }
        foreach (OrderStore::listBrackets(self::BRACKET_LIVE) as $b) {
            if (($b['accountId'] ?? '') !== ($row['accountId'] ?? '')
                || ($b['securityId'] ?? '') !== ($row['securityId'] ?? '')
                || in_array($b['status'] ?? '', ['waiting', 'closing'], true)) {
                continue;
            }
            $held = (float) ($b['quantity'] ?? 0);
            if ($left >= $held) {
                self::endBracket($b, 'sold from the ticket');
                $left -= $held;
            } elseif ($left > 0) {
                self::releaseShares($b, $left);
                $left = 0.0;
            }
            if ($left <= 0) {
                break;
            }
        }
    }

    /**
     * Desktop _end_bracket. Dry: no live cancel is sent; waiting/armed with no
     * resting exits become done immediately.
     *
     * @param  array<string,mixed>  $b
     */
    public static function endBracket(array $b, string $outcome, string $note = ''): string
    {
        $pending = false;
        foreach (OrderStore::listExitOrders((string) ($b['orderId'] ?? '')) as $o) {
            $st = (string) ($o['status'] ?? '');
            if (in_array($st, self::BRACKET_RESTING, true)) {
                self::cancelExit((string) ($o['id'] ?? ''));
                $pending = true;
            } elseif ($st === 'cancelling') {
                $pending = true;
            }
        }
        $status = $pending ? 'closing' : 'done';
        OrderStore::updateBracket((string) $b['id'], [
            'status' => $status,
            'outcome' => $outcome,
            'error' => $note,
            'slOrderId' => '',
            'tpOrderId' => '',
        ]);
        logger()->info('bagholder bracket: '.$outcome, ['id' => $b['id'] ?? '', 'symbol' => $b['symbol'] ?? '', 'status' => $status]);

        return $status;
    }

    /**
     * Desktop _release_shares: part of the bracket sold from the ticket.
     *
     * @param  array<string,mixed>  $b
     */
    public static function releaseShares(array $b, float $sold): void
    {
        $remaining = round(((float) ($b['quantity'] ?? 0)) - $sold, 6);
        foreach ([(string) ($b['slOrderId'] ?? ''), (string) ($b['tpOrderId'] ?? '')] as $oid) {
            self::cancelExit($oid);
        }
        OrderStore::updateBracket((string) $b['id'], [
            'quantity' => $remaining,
            'slOrderId' => '',
            'tpOrderId' => '',
            'status' => 'armed',
            'error' => '',
            'attempts' => 0,
        ]);
        logger()->info('bagholder bracket: shares sold from the ticket', [
            'id' => $b['id'] ?? '',
            'symbol' => $b['symbol'] ?? '',
            'sold' => $sold,
            'remaining' => $remaining,
        ]);
    }

    /** Desktop _cancel_exit. Dry cancelOrder never mutates and never hits the book. */
    public static function cancelExit(string $orderId): string
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return '';
        }
        $row = OrderStore::getOrder($orderId);
        if (! $row || ! in_array($row['status'] ?? '', ['sent', 'pending'], true)) {
            return '';
        }
        $r = self::cancelOrder($orderId);
        if (! empty($r['ok']) || str_contains(strtolower((string) ($r['error'] ?? '')), 'not open')) {
            return '';
        }

        return (string) ($r['error'] ?? 'cancel failed');
    }

    /** @return array<string,mixed>|null */
    private static function dict(mixed $v): ?array
    {
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            $v = is_array($d) ? $d : null;
        }
        if (! is_array($v) || $v === [] || array_is_list($v)) {
            return null;
        }

        return $v;
    }

    private static function num(mixed $v, ?float $default = 0.0): ?float
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        return $default;
    }

    /** @return array<string,mixed> */
    public static function cancelOrder(string $orderId): array
    {
        $row = OrderStore::getOrder($orderId);
        if (! $row) {
            return ['ok' => false, 'error' => 'No such order.'];
        }
        if (! in_array($row['status'] ?? '', self::LIVE_STATUSES, true)) {
            return ['ok' => false, 'error' => 'That order is not open.'];
        }
        if (! self::ordersLive()) {
            return [
                'ok' => false,
                'id' => $row['id'],
                'status' => 'dry',
                'notice' => 'Cancel not sent (orders are off)',
                'error' => 'Orders are off (BAGHOLDER_DRY_ORDERS): nothing is sent to Wealthsimple.',
                'dry' => true,
            ];
        }
        $sess = self::ticketSession();
        if (! $sess) {
            return ['ok' => false, 'error' => 'Not connected.'];
        }
        try {
            $data = Api::graphql(
                $sess,
                'SoOrdersOrderCancel',
                ['cancelOrderRequest' => ['externalId' => $row['id']]],
                Queries::Q_SO_ORDERS_ORDER_CANCEL
            );
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: $e::class;

            return ['ok' => false, 'error' => 'Cancel failed: '.$msg];
        }
        $result = $data['orderServiceCancelOrder'] ?? [];
        $errs = $result['errors'] ?? [];
        if ($errs !== []) {
            $first = is_array($errs[0] ?? null) ? $errs[0] : ['message' => (string) ($errs[0] ?? '')];
            $msg = trim((string) ($first['message'] ?? $first['code'] ?? 'refused'));

            return ['ok' => false, 'error' => 'Wealthsimple refused the cancel: '.$msg];
        }
        OrderStore::updateOrder($row['id'], ['status' => 'cancelling', 'wsStatus' => 'CANCEL_PENDING']);

        return ['ok' => true, 'id' => $row['id'], 'status' => 'cancelling'];
    }

    /** @return array<string,mixed> */
    public static function modifyOrder(string $orderId, mixed $quantity = null, mixed $limitPrice = null): array
    {
        $row = OrderStore::getOrder($orderId);
        if (! $row) {
            return ['ok' => false, 'error' => 'No such order.'];
        }
        if (! in_array($row['status'] ?? '', ['sent', 'pending'], true)) {
            return ['ok' => false, 'error' => 'That order is not open.'];
        }
        if (($row['type'] ?? '') === 'STOP') {
            return ['ok' => false, 'error' => 'A stop order cannot be changed; cancel it and place another.'];
        }
        $q = $quantity === null || $quantity === '' ? null : (float) $quantity;
        $lp = $limitPrice === null || $limitPrice === '' ? null : self::orderTick($limitPrice);
        if ($q !== null && $q <= 0) {
            return ['ok' => false, 'error' => 'Shares must be more than zero.'];
        }
        if ($lp !== null && $lp <= 0) {
            return ['ok' => false, 'error' => 'A limit price must be more than zero.'];
        }
        if (in_array($row['type'] ?? '', ['LIMIT', 'STOP_LIMIT'], true) && $lp === null && $q === null) {
            return ['ok' => false, 'error' => 'Nothing to change.'];
        }
        $inp = ['externalId' => $row['id']];
        if ($lp !== null && in_array($row['type'] ?? '', ['LIMIT', 'STOP_LIMIT'], true)
            && (float) $lp !== (float) ($row['limitPrice'] ?? 0)) {
            $inp['newLimitPrice'] = $lp;
        }
        if ($q !== null && (float) $q !== (float) ($row['quantity'] ?? 0)) {
            $inp['newQuantity'] = $q;
        }
        if (count($inp) === 1) {
            return ['ok' => true, 'id' => $row['id'], 'unchanged' => true];
        }
        if (! self::ordersLive()) {
            return [
                'ok' => false,
                'id' => $row['id'],
                'status' => 'dry',
                'notice' => 'Edit not sent (orders are off)',
                'error' => 'Orders are off (BAGHOLDER_DRY_ORDERS): nothing is sent to Wealthsimple.',
                'dry' => true,
            ];
        }
        $sess = self::ticketSession();
        if (! $sess) {
            return ['ok' => false, 'error' => 'Not connected.'];
        }
        try {
            $data = Api::graphql($sess, 'SoOrdersOrderModify', ['input' => $inp], Queries::Q_SO_ORDERS_ORDER_MODIFY);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: $e::class;

            return ['ok' => false, 'error' => 'Change failed: '.$msg];
        }
        $errs = ($data['soOrdersModifyOrder'] ?? [])['errors'] ?? [];
        if ($errs !== []) {
            $first = is_array($errs[0] ?? null) ? $errs[0] : ['message' => (string) ($errs[0] ?? '')];
            $msg = trim((string) ($first['message'] ?? $first['code'] ?? 'refused'));

            return ['ok' => false, 'error' => 'Wealthsimple refused the change: '.$msg];
        }
        $patch = [];
        if (isset($inp['newLimitPrice'])) {
            $patch['limitPrice'] = $lp;
        }
        if (isset($inp['newQuantity'])) {
            $patch['quantity'] = $q;
        }
        if ($patch !== []) {
            OrderStore::updateOrder($row['id'], $patch);
        }

        return ['ok' => true, 'id' => $row['id']];
    }

    /**
     * Import desktop session.json into SessionStore (real tokens). Does not print secrets.
     *
     * @return array{ok:bool,error?:string,hasRefresh?:bool,isDry?:bool}
     */
    public static function importSessionFile(string $path, ?string $clientIdPath = null): array
    {
        if (! is_file($path)) {
            return ['ok' => false, 'error' => 'session file missing'];
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid session JSON'];
        }
        if (! is_array($data) || empty($data['refresh_token']) || empty($data['access_token'])) {
            return ['ok' => false, 'error' => 'session needs access_token and refresh_token'];
        }
        unset($data['dry']);
        if ($clientIdPath && is_file($clientIdPath)) {
            $cid = trim((string) file_get_contents($clientIdPath));
            if ($cid !== '') {
                $data['client_id'] = $cid;
                ClientIdScraper::save($cid);
            }
        }
        // Normalize expires_at to unix when ISO
        if (! empty($data['expires_at']) && ! is_numeric($data['expires_at'])) {
            $t = strtotime((string) $data['expires_at']);
            if ($t !== false) {
                $data['expires_at'] = $t;
            }
        }
        SessionStore::save($data);
        Meta::putValue('ws_connected', '1');

        return [
            'ok' => true,
            'hasRefresh' => SessionStore::hasRefresh(),
            'isDry' => SessionStore::isDry(),
        ];
    }
    public const ORDER_BRANCH = 'TR';

    public const ORDERS_REFRESH_SEC = 30;

    /** @var list<string> */
    public const WS_PENDING = [
        'NEW', 'PENDING_SUBMISSION', 'PENDING_REVIEW', 'PENDING_FUND_TRANSFER',
        'SUBMITTED', 'PLACED', 'PARTIALLY_FILLED', 'CONTINGENT',
    ];

    /** @var list<string> */
    public const WS_CANCELLING = ['CANCEL_PENDING'];

    /** @var array<string,string> */
    public const WS_STATUS_MAP = [
        'FILLED' => 'filled',
        'POSTED' => 'filled',
        'CANCELLED' => 'cancelled',
        'DELETED' => 'cancelled',
        'EXPIRED' => 'expired',
        'REJECTED' => 'rejected',
    ];

    public static function appStatus(mixed $wsStatus): string
    {
        $s = strtoupper(trim((string) $wsStatus));
        if ($s === '') {
            return '';
        }
        if (in_array($s, self::WS_PENDING, true)) {
            return 'pending';
        }
        if (in_array($s, self::WS_CANCELLING, true)) {
            return 'cancelling';
        }

        return self::WS_STATUS_MAP[$s] ?? 'pending';
    }

    /** @param  array<string,mixed>|null  $data @return array<string,mixed>|null */
    public static function parseExtendedOrder(?array $data): ?array
    {
        $o = is_array($data['soOrdersExtendedOrder'] ?? null) ? $data['soOrdersExtendedOrder'] : null;
        if (! is_array($o) || empty($o['status'])) {
            return null;
        }

        return [
            'wsStatus' => strtoupper(trim((string) ($o['status'] ?? ''))),
            'status' => self::appStatus($o['status'] ?? ''),
            'filledQty' => self::num($o['filledQuantity'] ?? null, null),
            'avgFill' => self::num($o['averageFilledPrice'] ?? null, null),
            'submittedAt' => trim((string) ($o['submittedAtUtc'] ?? '')),
            'expiresAt' => trim((string) ($o['expiredAtUtc'] ?? '')),
            'error' => trim((string) ($o['rejectionCause'] ?? $o['rejectionCode'] ?? '')),
            'quantity' => self::num($o['submittedQuantity'] ?? null, null),
            'limitPrice' => self::num($o['limitPrice'] ?? null, null),
            'stopPrice' => self::num($o['stopPrice'] ?? null, null),
            'tif' => strtoupper(trim((string) ($o['timeInForce'] ?? ''))),
            'currency' => strtoupper(trim((string) ($o['securityCurrency'] ?? ''))),
            'accountId' => trim((string) ($o['canonicalAccountId'] ?? $o['accountId'] ?? '')),
            'securityId' => trim((string) ($o['securityId'] ?? '')),
            'type' => strtoupper(trim((string) ($o['orderType'] ?? ''))),
        ];
    }

    /**
     * @param  array<string,mixed>  $sess
     * @return array<string,mixed>|null
     */
    public static function fetchExtendedOrder(array $sess, string $externalId): ?array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }
        $data = Api::graphql($sess, 'FetchSoOrdersExtendedOrder', [
            'branchId' => self::ORDER_BRANCH,
            'externalId' => $externalId,
        ], Queries::Q_FETCH_SO_ORDERS_EXTENDED_ORDER);

        return self::parseExtendedOrder(is_array($data) ? $data : null);
    }

    /**
     * @param  array<string,mixed>  $sess
     * @param  list<string>|null  $statuses
     * @return list<array<string,mixed>>
     */
    public static function fetchOrderFeed(array $sess, string $identity, ?array $statuses = null): array
    {
        $statuses = $statuses ?? self::WS_PENDING;
        $out = [];
        $cursor = null;
        while (true) {
            $data = Api::graphql($sess, 'OrderServiceExtendedOrderFeed', [
                'identityId' => $identity,
                'statuses' => array_values($statuses),
                'first' => 25,
                'cursor' => $cursor,
            ], Queries::Q_ORDER_SERVICE_EXTENDED_ORDER_FEED);
            $feed = $data['identity']['orderServiceExtendedOrderFeed'] ?? [];
            if (! is_array($feed)) {
                $feed = [];
            }
            foreach ($feed['edges'] ?? [] as $edge) {
                $node = is_array($edge) ? ($edge['node'] ?? null) : null;
                if (is_array($node) && ! empty($node['id'])) {
                    $out[] = $node;
                }
            }
            $page = is_array($feed['pageInfo'] ?? null) ? $feed['pageInfo'] : [];
            $cursor = $page['endCursor'] ?? null;
            if (empty($page['hasNextPage']) || ! $cursor) {
                break;
            }
        }

        return $out;
    }

    /** @param  array<string,mixed>  $node @return array<string,mixed> */
    public static function feedOrderRow(array $node): array
    {
        $sec = is_array($node['security'] ?? null) ? $node['security'] : [];
        $stock = is_array($sec['stock'] ?? null) ? $sec['stock'] : [];
        $acctId = trim((string) ($node['canonicalAccountId'] ?? ''));
        $acctName = '';
        foreach (OrderStore::orderAccounts() as $a) {
            if (($a['id'] ?? '') === $acctId) {
                $acctName = (string) ($a['name'] ?? '');
                break;
            }
        }
        $side = strtoupper(trim((string) ($node['side'] ?? '')));
        $secId = trim((string) ($node['securityId'] ?? $sec['id'] ?? ''));
        $symbol = OrderStore::symbolForSecurity($secId) ?: trim((string) ($node['symbol'] ?? $stock['symbol'] ?? ''));

        return [
            'id' => trim((string) ($node['id'] ?? '')),
            'createdAt' => trim((string) ($node['createdAtUtc'] ?? '')),
            'accountId' => $acctId,
            'account' => $acctName,
            'securityId' => $secId,
            'symbol' => $symbol,
            'currency' => strtoupper(trim((string) ($node['securityCurrency'] ?? ''))),
            'side' => str_starts_with($side, 'SELL') ? 'SELL' : 'BUY',
            'type' => strtoupper(trim((string) ($node['executionType'] ?? ''))) ?: 'LIMIT',
            'quantity' => self::num($node['submittedQuantity'] ?? null, 0.0) ?? 0.0,
            'limitPrice' => self::num($node['limitPrice'] ?? null, null),
            'stopPrice' => self::num($node['stopPrice'] ?? null, null),
            'tif' => '',
            'stopLoss' => null,
            'takeProfit' => null,
            'status' => self::appStatus($node['status'] ?? ''),
            'wsStatus' => strtoupper(trim((string) ($node['status'] ?? ''))),
            'wsOrderId' => trim((string) ($node['orderId'] ?? '')),
            'avgFill' => self::num($node['averageFillPrice'] ?? null, null),
            'source' => 'wealthsimple',
            'role' => 'entry',
        ];
    }

    /**
     * Desktop refresh_orders — extended-order read + pending feed.
     * Dry: no Wealthsimple calls (nothing on the book to sync).
     *
     * @return array<string,mixed>
     */
    public static function refreshOrders(string $onlyId = ''): array
    {
        if (! self::ordersLive()) {
            return ['ok' => true, 'skipped' => 'dry', 'read' => 0, 'added' => 0, 'failed' => 0];
        }
        $sess = self::ticketSession();
        if (! $sess) {
            return ['ok' => false, 'skipped' => 'no session'];
        }
        $live = [];
        foreach (OrderStore::listOrders() as $o) {
            if (! in_array($o['status'] ?? '', self::LIVE_STATUSES, true)) {
                continue;
            }
            if ($onlyId !== '' && ($o['id'] ?? '') !== $onlyId) {
                continue;
            }
            $live[] = $o;
        }
        $read = 0;
        $failed = 0;
        foreach ($live as $o) {
            try {
                $upd = self::fetchExtendedOrder($sess, (string) ($o['id'] ?? ''));
            } catch (\Throwable $e) {
                $msg = $e->getMessage() ?: $e::class;
                if (stripos($msg, '401') !== false || stripos($msg, '403') !== false || stripos($msg, 'refus') !== false) {
                    logger()->warning('bagholder orders: Wealthsimple refused the session');

                    return ['ok' => false, 'skipped' => 'refused'];
                }
                $failed++;
                logger()->warning('bagholder orders: status failed', ['id' => $o['id'] ?? '', 'err' => $msg]);
                continue;
            }
            if (! $upd) {
                continue;
            }
            $patch = [];
            foreach (['wsStatus', 'status', 'filledQty', 'avgFill', 'submittedAt', 'expiresAt'] as $k) {
                if (($upd[$k] ?? null) !== null && $upd[$k] !== '') {
                    $patch[$k] = $upd[$k];
                }
            }
            if (($upd['error'] ?? '') !== '') {
                $patch['error'] = $upd['error'];
            }
            if (($o['source'] ?? '') === 'wealthsimple' || in_array($o['role'] ?? '', ['stop', 'target'], true)) {
                foreach (['tif', 'quantity', 'limitPrice', 'stopPrice', 'currency'] as $k) {
                    if (($upd[$k] ?? null) !== null && $upd[$k] !== '') {
                        $patch[$k] = $upd[$k];
                    }
                }
                $name = OrderStore::symbolForSecurity((string) ($o['securityId'] ?? ''));
                if ($name !== '' && $name !== ($o['symbol'] ?? '')) {
                    $patch['symbol'] = $name;
                }
            }
            if ($patch !== []) {
                OrderStore::updateOrder((string) $o['id'], $patch);
                $read++;
            }
        }
        $added = 0;
        if ($onlyId === '') {
            $identity = SessionStore::identityFrom($sess);
            if ($identity !== '') {
                try {
                    $rows = OrderStore::listOrders();
                    $known = [];
                    foreach ($rows as $o) {
                        $known[(string) ($o['id'] ?? '')] = true;
                        if (($o['wsOrderId'] ?? '') !== '') {
                            $known[(string) $o['wsOrderId']] = true;
                        }
                    }
                    foreach (self::fetchOrderFeed($sess, $identity) as $node) {
                        $nid = trim((string) ($node['id'] ?? ''));
                        $oid = trim((string) ($node['orderId'] ?? ''));
                        if (isset($known[$nid]) || ($oid !== '' && isset($known[$oid]))) {
                            continue;
                        }
                        OrderStore::insertOrder(self::feedOrderRow($node));
                        $added++;
                    }
                } catch (\Throwable $e) {
                    $msg = $e->getMessage() ?: $e::class;
                    if (stripos($msg, '401') !== false || stripos($msg, '403') !== false || stripos($msg, 'refus') !== false) {
                        return ['ok' => false, 'skipped' => 'refused'];
                    }
                    $failed++;
                    logger()->warning('bagholder orders: pending-order feed failed', ['err' => $msg]);
                }
            }
            try {
                Meta::putValue('orders_refreshed_at', gmdate('Y-m-d\TH:i:s\Z'));
            } catch (\Throwable) {
            }
        }
        if ($read || $added || $failed) {
            logger()->info('bagholder orders refresh', compact('read', 'added', 'failed'));
        }

        return ['ok' => $failed === 0, 'read' => $read, 'added' => $added, 'failed' => $failed];
    }

    /**
     * Desktop kick_orders_refresh — when live, refresh if last pass is older than ORDERS_REFRESH_SEC.
     * Dry: no-op (nothing to pull from the book).
     *
     * @return array<string,mixed>
     */
    public static function kickOrdersRefresh(bool $force = false): array
    {
        if (! self::ordersLive()) {
            $at = null;
            try {
                $at = Meta::getValue('orders_refreshed_at', null);
            } catch (\Throwable) {
            }

            return ['ok' => true, 'skipped' => 'dry', 'refreshedAt' => $at];
        }
        $at = '';
        try {
            $at = (string) Meta::getValue('orders_refreshed_at', '');
        } catch (\Throwable) {
        }
        if (! $force && $at !== '') {
            $ts = strtotime($at);
            if ($ts !== false && (time() - $ts) < self::ORDERS_REFRESH_SEC) {
                return ['ok' => true, 'skipped' => 'fresh', 'refreshedAt' => $at];
            }
        }
        try {
            Meta::putValue('orders_refresh_requested_at', gmdate('c'));
        } catch (\Throwable) {
        }
        // Synchronous refresh (Laravel has no desktop daemon thread); Orders poll + artisan tick call this.
        $r = self::refreshOrders();
        $r['kicked'] = true;
        try {
            $r['refreshedAt'] = Meta::getValue('orders_refreshed_at', null);
        } catch (\Throwable) {
            $r['refreshedAt'] = null;
        }

        return $r;
    }

}
