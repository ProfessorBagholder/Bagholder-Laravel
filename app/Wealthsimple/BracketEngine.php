<?php

namespace App\Wealthsimple;

/**
 * Desktop bracket_tick / _arm_step / _watch_step / _roll_step / sold-elsewhere —
 * dry-safe. Waiting stays waiting while entry is dry/pending; exits are never
 * sent when BAGHOLDER_DRY_ORDERS=1 (_place_exit no-ops like desktop). Live paths
 * match desktop when DRY is off.
 */
final class BracketEngine
{
    public const RETRY_SEC = [60, 300, 900, 3600];

    public const TRAIL_MIN_MOVE = 0.005;

    public const TARGET_BACK_OFF = 0.01;

    public const GTC_DAYS = 90;

    public const ROLL_SEC = 7 * 86400;

    public const ROLL_LAST_SEC = 2 * 86400;

    public const BRACKET_INFLIGHT = ['sent', 'pending', 'cancelling'];

    private static bool $running = false;

    /** @var array<string,true> */
    private static array $said = [];

    /** @var array<string,bool> */
    private static array $stopAllowedCache = [];

    /**
     * @param  array<string,array<string,mixed>>|null  $quotes  securityId => quote
     * @return array<string,mixed>
     */
    public static function tick(?array $quotes = null): array
    {
        if (self::$running) {
            return ['ok' => false, 'skipped' => 'running'];
        }
        self::$running = true;
        try {
            $live = OrderStore::listBrackets(OrderService::BRACKET_LIVE);
            if ($live === []) {
                self::sweepExits();

                return ['ok' => true, 'brackets' => 0];
            }
            if (OrderService::ordersLive()) {
                foreach ($live as $b) {
                    $st = (string) ($b['status'] ?? '');
                    if ($st === 'waiting') {
                        OrderService::refreshOrders((string) ($b['orderId'] ?? ''));
                    } elseif ($st === 'firing' && ($b['slOrderId'] ?? '') !== '' && ($b['tpOrderId'] ?? '') === '') {
                        OrderService::refreshOrders((string) $b['slOrderId']);
                    } elseif ($st === 'armed' && ($b['slKind'] ?? '') !== '' && ($b['slOrderId'] ?? '') === '') {
                        $prev = self::exitRow($b, 'stop');
                        if ($prev && in_array($prev['status'] ?? '', self::BRACKET_INFLIGHT, true)) {
                            OrderService::refreshOrders((string) ($prev['id'] ?? ''));
                        }
                    } elseif ($st === 'target_placed' && ($b['tpOrderId'] ?? '') === '') {
                        $prev = self::exitRow($b, 'target');
                        if ($prev && in_array($prev['status'] ?? '', self::BRACKET_INFLIGHT, true)) {
                            OrderService::refreshOrders((string) ($prev['id'] ?? ''));
                        }
                    } elseif ($st === 'stopping') {
                        $prev = self::exitRow($b, 'target');
                        if ($prev && in_array($prev['status'] ?? '', self::BRACKET_INFLIGHT, true)) {
                            OrderService::refreshOrders((string) ($prev['id'] ?? ''));
                        }
                    } elseif ($st === 'closing') {
                        foreach (OrderStore::listExitOrders((string) ($b['orderId'] ?? '')) as $o) {
                            if (($o['status'] ?? '') === 'cancelling') {
                                OrderService::refreshOrders((string) ($o['id'] ?? ''));
                            }
                        }
                    }
                }
            }
            $orders = [];
            foreach (OrderStore::listOrders() as $o) {
                $orders[(string) ($o['id'] ?? '')] = $o;
            }
            if ($quotes === null) {
                $quotes = [];
                $ids = [];
                foreach ($live as $b) {
                    if (in_array($b['status'] ?? '', ['armed', 'firing', 'target_placed', 'stopping'], true)) {
                        $ids[(string) ($b['securityId'] ?? '')] = true;
                    }
                }
                $ids = array_values(array_filter(array_keys($ids)));
                if ($ids !== [] && OrderService::ticketSession()) {
                    try {
                        $quotes = self::fetchQuotes($ids);
                    } catch (\Throwable $e) {
                        logger()->warning('bagholder bracket: quotes failed', ['err' => $e->getMessage()]);
                    }
                }
            }
            foreach ($live as $b0) {
                try {
                    $entry = $orders[$b0['orderId'] ?? ''] ?? null;
                    if (self::reconcileStep($b0, $entry) === 'done') {
                        continue;
                    }
                    $b = OrderStore::getBracket((string) $b0['id']) ?? $b0;
                    self::rollStep($b, $quotes[$b['securityId'] ?? ''] ?? null);
                    $b = OrderStore::getBracket((string) $b0['id']) ?? $b;
                    self::armStep($b, $entry);
                    $b = OrderStore::getBracket((string) $b0['id']) ?? $b;
                    if (in_array($b['status'] ?? '', ['armed', 'firing', 'target_placed', 'stopping'], true)) {
                        self::watchStep($b, $quotes[$b['securityId'] ?? ''] ?? null);
                    }
                } catch (\Throwable $e) {
                    logger()->warning('bagholder bracket: tick failed', ['id' => $b0['id'] ?? '', 'err' => $e->getMessage()]);
                }
            }
            self::sweepExits();

            return ['ok' => true, 'brackets' => count($live)];
        } finally {
            self::$running = false;
        }
    }

    /**
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>|null  $entry
     */
    public static function armStep(array $b, ?array $entry): void
    {
        if (($b['status'] ?? '') === 'waiting') {
            if ($entry === null) {
                OrderStore::updateBracket((string) $b['id'], ['status' => 'cancelled', 'outcome' => 'entry not found']);

                return;
            }
            $st = (string) ($entry['status'] ?? '');
            if (in_array($st, ['pending', 'sent', 'cancelling', 'dry'], true)) {
                return; // dry stays waiting — desktop parity
            }
            $filled = (float) ($entry['filledQty'] ?? 0);
            if ($st === 'filled' && ! ($filled > 0)) {
                $filled = (float) ($entry['quantity'] ?? 0);
            }
            if (! ($filled > 0)) {
                OrderStore::updateBracket((string) $b['id'], ['status' => 'cancelled', 'outcome' => 'entry '.$st]);

                return;
            }
            $patch = [
                'quantity' => $filled,
                'status' => 'armed',
                'armedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            if (($b['slKind'] ?? '') === 'trail') {
                $high = (float) ($entry['avgFill'] ?? 0) ?: (float) ($entry['limitPrice'] ?? 0) ?: (float) ($b['slPrice'] ?? 0);
                if ($high > 0) {
                    $patch['highWater'] = $high;
                    $dist = self::trailDistance($b, $high) ?? 0.0;
                    $patch['slPrice'] = round($high - $dist, 2);
                }
            }
            OrderStore::updateBracket((string) $b['id'], $patch);
            logger()->info('bagholder bracket armed', ['id' => $b['id'], 'qty' => $filled, 'symbol' => $b['symbol'] ?? '']);
            $b = array_merge($b, $patch);
        }
        if (($b['status'] ?? '') !== 'armed' || ($b['slKind'] ?? '') === '' || ($b['slOrderId'] ?? '') !== '') {
            return;
        }
        if (! self::nothingResting($b)) {
            return;
        }
        $native = self::stopAllowed((string) ($b['securityId'] ?? ''));
        if (! $native) {
            if (($b['slMode'] ?? '') !== 'watched') {
                OrderStore::updateBracket((string) $b['id'], ['slMode' => 'watched', 'slNative' => false]);
                logger()->info('bagholder bracket: stop watched (no native STOP)', ['id' => $b['id'], 'symbol' => $b['symbol'] ?? '']);
            }

            return;
        }
        if (! self::mayRetry($b)) {
            return;
        }
        // Dry: never place exits — log once like desktop _place_exit.
        [$oid, $err] = self::placeExit($b, 'STOP', (float) ($b['slPrice'] ?? 0), 'stop');
        if ($err !== '') {
            self::fail($b, 'stop not placed: '.$err);
        } elseif ($oid !== '') {
            OrderStore::updateBracket((string) $b['id'], [
                'slOrderId' => $oid,
                'slNative' => true,
                'slMode' => 'native',
                'error' => '',
                'attempts' => 0,
                'movedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            logger()->info('bagholder bracket: stop placed', ['id' => $b['id'], 'price' => $b['slPrice'] ?? null, 'symbol' => $b['symbol'] ?? '']);
        }
    }

    /**
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>|null  $quote
     */
    public static function watchStep(array $b, ?array $quote): void
    {
        if (! is_array($quote)) {
            return;
        }
        $market = strtoupper(trim((string) ($quote['marketStatus'] ?? '')));
        // Local trail high-water even when dry / market closed for UI; fire only when OPEN + live.
        $last = isset($quote['last']) && is_numeric($quote['last']) ? (float) $quote['last'] : null;
        $bid = isset($quote['bid']) && is_numeric($quote['bid']) ? (float) $quote['bid'] : null;
        if ($last === null) {
            return;
        }
        $nowS = gmdate('Y-m-d\TH:i:s\Z');
        $trigger = $bid ?? $last;
        $atTarget = isset($b['tpPrice']) && is_numeric($b['tpPrice']) && $trigger >= (float) $b['tpPrice'];

        // Trail update (local high-water) even when dry — no book send under dry.
        if (($b['slKind'] ?? '') === 'trail' && (($b['status'] ?? '') === 'target_placed' || (($b['status'] ?? '') === 'armed' && ! $atTarget))) {
            $high = max((float) ($b['highWater'] ?? 0), $last);
            if ($high != ($b['highWater'] ?? null)) {
                OrderStore::updateBracket((string) $b['id'], ['highWater' => $high]);
            }
            $newStop = round($high - (self::trailDistance($b, $high) ?? 0.0), 2);
            $cur = (float) ($b['slPrice'] ?? 0);
            if ($newStop > $cur + max(0.01, $cur * self::TRAIL_MIN_MOVE)) {
                if (($b['slOrderId'] ?? '') !== '') {
                    if (! OrderService::ordersLive()) {
                        // dry: local trail only
                        OrderStore::updateBracket((string) $b['id'], [
                            'slPrice' => $newStop,
                            'highWater' => $high,
                            'movedAt' => $nowS,
                        ]);
                        $b = array_merge($b, ['slPrice' => $newStop, 'highWater' => $high]);
                    } else {
                        $err = OrderService::cancelExit((string) $b['slOrderId']);
                        if ($err !== '') {
                            self::fail($b, 'stop not moved: '.$err);

                            return;
                        }
                        OrderStore::updateBracket((string) $b['id'], [
                            'slPrice' => $newStop,
                            'slOrderId' => '',
                            'highWater' => $high,
                            'movedAt' => $nowS,
                        ]);
                        logger()->info('bagholder bracket: stop moves', ['id' => $b['id'], 'to' => $newStop, 'high' => $high]);
                        $b = array_merge($b, ['slPrice' => $newStop, 'slOrderId' => '', 'highWater' => $high]);
                    }
                } else {
                    OrderStore::updateBracket((string) $b['id'], [
                        'highWater' => $high,
                        'slPrice' => $newStop,
                        'movedAt' => $nowS,
                    ]);
                    $b = array_merge($b, ['slPrice' => $newStop, 'highWater' => $high]);
                }
            }
        }

        if ($market !== 'OPEN') {
            return;
        }
        // Target / stop fire only when live (placement). Dry watches levels locally only.
        if (! OrderService::ordersLive()) {
            return;
        }

        // Watched stop fires at market
        if (($b['status'] ?? '') === 'armed' && ($b['slKind'] ?? '') !== '' && ($b['slMode'] ?? '') === 'watched'
            && ($b['slOrderId'] ?? '') === '' && isset($b['slPrice']) && is_numeric($b['slPrice'])) {
            if ($trigger <= (float) $b['slPrice']) {
                if (! self::mayRetry($b)) {
                    return;
                }
                [$oid, $err] = self::placeExit($b, 'MARKET', (float) $b['slPrice'], 'stop');
                if ($err !== '') {
                    self::fail($b, 'stop not placed: '.$err);
                } elseif ($oid !== '') {
                    OrderStore::updateBracket((string) $b['id'], ['slOrderId' => $oid, 'status' => 'firing', 'error' => '']);
                    logger()->info('bagholder bracket: watched stop hit', ['id' => $b['id'], 'trigger' => $trigger]);
                }

                return;
            }
        }

        // Target: cancel resting stop first, then limit sell
        if (($b['status'] ?? '') === 'armed' && isset($b['tpPrice']) && is_numeric($b['tpPrice'])) {
            if ($trigger >= (float) $b['tpPrice']) {
                if (($b['slOrderId'] ?? '') !== '') {
                    $err = OrderService::cancelExit((string) $b['slOrderId']);
                    if ($err !== '') {
                        self::fail($b, 'stop not cancelled for the target: '.$err);

                        return;
                    }
                    OrderStore::updateBracket((string) $b['id'], ['status' => 'firing', 'error' => '']);
                    logger()->info('bagholder bracket: target reached, stop cancel sent', ['id' => $b['id'], 'trigger' => $trigger]);

                    return;
                }
                self::fireTarget($b);
            }
        }

        if (($b['status'] ?? '') === 'firing' && isset($b['tpPrice']) && ($b['tpOrderId'] ?? '') === '') {
            $stopRow = self::exitRow($b, 'stop');
            if ($stopRow && ($stopRow['status'] ?? '') === 'cancelled') {
                self::fireTarget($b);
            }
        }

        if (($b['status'] ?? '') === 'target_placed' && ($b['slKind'] ?? '') !== '' && isset($b['slPrice']) && ($b['tpOrderId'] ?? '') !== '') {
            if ($trigger <= (float) $b['slPrice']) {
                $err = OrderService::cancelExit((string) $b['tpOrderId']);
                if ($err !== '') {
                    self::fail($b, 'target not cancelled for the stop: '.$err);

                    return;
                }
                OrderStore::updateBracket((string) $b['id'], [
                    'status' => 'stopping',
                    'tpOrderId' => '',
                    'error' => '',
                    'attempts' => 0,
                ]);
                logger()->info('bagholder bracket: stop while target rested', ['id' => $b['id'], 'trigger' => $trigger]);

                return;
            }
            if (isset($b['tpPrice']) && is_numeric($b['tpPrice']) && $trigger < (float) $b['tpPrice'] * (1 - self::TARGET_BACK_OFF)) {
                $err = OrderService::cancelExit((string) $b['tpOrderId']);
                if ($err !== '') {
                    self::fail($b, 'target not cancelled for the stop: '.$err);

                    return;
                }
                OrderStore::updateBracket((string) $b['id'], [
                    'status' => 'armed',
                    'tpOrderId' => '',
                    'slOrderId' => '',
                    'error' => '',
                    'attempts' => 0,
                ]);
                logger()->info('bagholder bracket: target out of reach, stop returns', ['id' => $b['id'], 'trigger' => $trigger]);

                return;
            }
        }

        if (($b['status'] ?? '') === 'stopping') {
            $tpRow = self::exitRow($b, 'target');
            if ($tpRow && in_array($tpRow['status'] ?? '', ['cancelled', 'expired'], true)) {
                if (! self::mayRetry($b) || ! self::nothingResting($b)) {
                    return;
                }
                [$oid, $err] = self::placeExit($b, 'MARKET', (float) ($b['slPrice'] ?? 0), 'stop');
                if ($err !== '') {
                    self::fail($b, 'stop not placed: '.$err);
                } elseif ($oid !== '') {
                    OrderStore::updateBracket((string) $b['id'], [
                        'slOrderId' => $oid,
                        'status' => 'firing',
                        'error' => '',
                        'attempts' => 0,
                    ]);
                    logger()->info('bagholder bracket: market sell at stop', ['id' => $b['id']]);
                }
            }
        }
    }

    /** @param  array<string,mixed>  $b */
    public static function fireTarget(array $b): void
    {
        if (! self::mayRetry($b) || ! self::nothingResting($b)) {
            return;
        }
        [$oid, $err] = self::placeExit($b, 'LIMIT', (float) ($b['tpPrice'] ?? 0), 'target');
        if ($err !== '') {
            self::fail($b, 'target not placed: '.$err);
        } elseif ($oid !== '') {
            OrderStore::updateBracket((string) $b['id'], [
                'tpOrderId' => $oid,
                'status' => 'target_placed',
                'error' => '',
                'attempts' => 0,
            ]);
            logger()->info('bagholder bracket: target placed', ['id' => $b['id'], 'price' => $b['tpPrice'] ?? null]);
        }
    }

    /**
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>|null  $quote
     */
    public static function rollStep(array $b, ?array $quote): void
    {
        if (! OrderService::ordersLive()) {
            return;
        }
        $now = time();
        $nowS = gmdate('Y-m-d\TH:i:s\Z', $now);
        if (($b['status'] ?? '') === 'armed' && ($b['slMode'] ?? '') === 'native' && ($b['slOrderId'] ?? '') !== '') {
            $row = OrderStore::getOrder((string) $b['slOrderId']);
            if (self::rollDue($row, $quote, $now)) {
                $err = OrderService::cancelExit((string) $b['slOrderId']);
                if ($err !== '') {
                    self::fail($b, 'stop not rolled: '.$err);

                    return;
                }
                OrderStore::updateBracket((string) $b['id'], ['slOrderId' => '', 'movedAt' => $nowS, 'error' => '']);
                logger()->info('bagholder bracket: stop rolled (90d GTC)', ['id' => $b['id']]);
            }
        } elseif (($b['status'] ?? '') === 'target_placed') {
            if (($b['tpOrderId'] ?? '') !== '') {
                $row = OrderStore::getOrder((string) $b['tpOrderId']);
                if (self::rollDue($row, $quote, $now)) {
                    $err = OrderService::cancelExit((string) $b['tpOrderId']);
                    if ($err !== '') {
                        self::fail($b, 'target not rolled: '.$err);

                        return;
                    }
                    OrderStore::updateBracket((string) $b['id'], ['tpOrderId' => '', 'movedAt' => $nowS, 'error' => '']);
                    logger()->info('bagholder bracket: target rolled (90d GTC)', ['id' => $b['id']]);
                }
            } else {
                $tpRow = self::exitRow($b, 'target');
                if ($tpRow && in_array($tpRow['status'] ?? '', ['cancelled', 'expired'], true)) {
                    self::fireTarget($b);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>|null  $entry
     */
    private static function reconcileStep(array $b, ?array $entry): string
    {
        if (($b['status'] ?? '') === 'closing') {
            self::closingStep($b);
            $fresh = OrderStore::getBracket((string) $b['id']);

            return ($fresh['status'] ?? '') === 'done' ? 'done' : '';
        }
        $stopRow = self::exitRow($b, 'stop');
        $tpRow = self::exitRow($b, 'target');
        if ($stopRow && ($stopRow['status'] ?? '') === 'filled') {
            OrderService::endBracket($b, 'stopped');

            return 'done';
        }
        if ($tpRow && ($tpRow['status'] ?? '') === 'filled') {
            OrderService::endBracket($b, 'target');

            return 'done';
        }
        if (($b['slOrderId'] ?? '') !== '' && $stopRow && in_array($stopRow['status'] ?? '', OrderService::BRACKET_RESTING, true)
            && isset($stopRow['stopPrice'], $b['slPrice']) && is_numeric($stopRow['stopPrice']) && is_numeric($b['slPrice'])
            && abs((float) $stopRow['stopPrice'] - (float) $b['slPrice']) > 0.005) {
            OrderStore::updateBracket((string) $b['id'], ['slPrice' => (float) $stopRow['stopPrice']]);
            $b['slPrice'] = (float) $stopRow['stopPrice'];
        }
        if (($b['tpOrderId'] ?? '') !== '' && $tpRow && in_array($tpRow['status'] ?? '', OrderService::BRACKET_RESTING, true)
            && isset($tpRow['limitPrice'], $b['tpPrice']) && is_numeric($tpRow['limitPrice']) && is_numeric($b['tpPrice'])
            && abs((float) $tpRow['limitPrice'] - (float) $b['tpPrice']) > 0.005) {
            OrderStore::updateBracket((string) $b['id'], ['tpPrice' => (float) $tpRow['limitPrice']]);
        }
        if (($b['status'] ?? '') === 'armed' && ($b['slMode'] ?? '') === 'native' && ($b['slOrderId'] ?? '') !== '' && $stopRow) {
            if (($stopRow['status'] ?? '') === 'expired') {
                OrderStore::updateBracket((string) $b['id'], ['slOrderId' => '', 'error' => '']);
            } elseif (in_array($stopRow['status'] ?? '', ['cancelled', 'rejected', 'failed'], true)) {
                $why = ($stopRow['status'] ?? '') === 'cancelled'
                    ? 'stop cancelled at Wealthsimple by hand'
                    : 'stop '.$stopRow['status'].' at Wealthsimple'.(($stopRow['error'] ?? '') !== '' ? ': '.$stopRow['error'] : '');
                OrderService::endBracket($b, $why);

                return 'done';
            }
        }
        if (($b['status'] ?? '') === 'target_placed' && ($b['tpOrderId'] ?? '') !== '' && $tpRow) {
            if (($tpRow['status'] ?? '') === 'expired') {
                OrderStore::updateBracket((string) $b['id'], ['tpOrderId' => '', 'error' => '']);
            } elseif (in_array($tpRow['status'] ?? '', ['cancelled', 'rejected', 'failed'], true)) {
                $why = ($tpRow['status'] ?? '') === 'cancelled'
                    ? 'target cancelled at Wealthsimple by hand'
                    : 'target '.$tpRow['status'].' at Wealthsimple'.(($tpRow['error'] ?? '') !== '' ? ': '.$tpRow['error'] : '');
                OrderService::endBracket($b, $why);

                return 'done';
            }
        }
        $why = self::closedElsewhere($b);
        if ($why !== '') {
            OrderService::endBracket($b, $why);

            return 'done';
        }

        return '';
    }

    /** @param  array<string,mixed>  $b */
    private static function closingStep(array $b): void
    {
        $open = [];
        foreach (OrderStore::listExitOrders((string) ($b['orderId'] ?? '')) as $o) {
            if (in_array($o['status'] ?? '', self::BRACKET_INFLIGHT, true)) {
                $open[] = $o;
                if (in_array($o['status'] ?? '', OrderService::BRACKET_RESTING, true)) {
                    OrderService::cancelExit((string) ($o['id'] ?? ''));
                }
            }
        }
        if ($open === []) {
            OrderStore::updateBracket((string) $b['id'], ['status' => 'done']);
        }
    }

    /** @param  array<string,mixed>  $b */
    private static function closedElsewhere(array $b): string
    {
        if (! in_array($b['status'] ?? '', ['armed', 'firing', 'target_placed', 'stopping'], true)
            || ($b['armedAt'] ?? '') === '' || ! self::nothingResting($b)) {
            return '';
        }
        $sold = OrderStore::soldSince(
            (string) ($b['accountId'] ?? ''),
            (string) ($b['securityId'] ?? ''),
            (string) ($b['armedAt'] ?? ''),
            (string) ($b['symbol'] ?? ''),
        );
        $qty = (float) ($b['quantity'] ?? 0);
        if ($sold > 0 && $sold >= $qty) {
            return 'sold: '.$sold.' shares in the activity feed';
        }
        $readAt = '';
        try {
            $readAt = (string) \App\Models\Meta::getValue('balances_read_at', '');
        } catch (\Throwable) {
        }
        if ($readAt === '' || $readAt <= (string) ($b['armedAt'] ?? '')) {
            return '';
        }
        $held = OrderStore::positionQuantity((string) ($b['accountId'] ?? ''), (string) ($b['securityId'] ?? ''));
        if ($held !== null && $held > 0) {
            if (empty($b['seenHeld']) || ($b['missedAt'] ?? '') !== '') {
                OrderStore::updateBracket((string) $b['id'], ['seenHeld' => true, 'missedAt' => '']);
            }

            return '';
        }
        if (empty($b['seenHeld'])) {
            return '';
        }
        $missed = (string) ($b['missedAt'] ?? '');
        if ($missed === '') {
            OrderStore::updateBracket((string) $b['id'], ['missedAt' => $readAt]);

            return '';
        }
        if ($readAt > $missed) {
            return 'position gone: two balance reads without it ('.$missed.', '.$readAt.')';
        }

        return '';
    }

    /**
     * One exit order for the bracket. Without ordersLive the line is logged once and
     * nothing is placed; the bracket keeps waiting. Returns [order id, error].
     *
     * @param  array<string,mixed>  $b
     * @return array{0:string,1:string}
     */
    public static function placeExit(array $b, string $execType, float $price, string $role): array
    {
        [$row, $req, $err] = self::exitBody($b, $execType, $price, $role);
        if ($err !== '') {
            return ['', $err];
        }
        if (! OrderService::ordersLive()) {
            self::sayOnce(($b['id'] ?? '').':'.$role.':'.round($price, 4), 'bagholder bracket (orders are off, not placed): '.$role.' '.$execType.' for '.($b['symbol'] ?? ''));

            return ['', ''];
        }
        $r = OrderService::submitOrder($row, $req);
        if (empty($r['ok'])) {
            return ['', (string) ($r['error'] ?? 'not sent')];
        }

        return [(string) ($r['id'] ?? ''), ''];
    }

    /**
     * @param  array<string,mixed>  $b
     * @return array{0:?array,1:?array,2:string}
     */
    private static function exitBody(array $b, string $execType, float $price, string $role): array
    {
        $body = [
            'symbol' => $b['symbol'] ?? '',
            'securityId' => $b['securityId'] ?? '',
            'accountId' => $b['accountId'] ?? '',
            'side' => 'SELL',
            'type' => $execType,
            'tif' => OrderService::BRACKET_TIF,
            'quantity' => $b['quantity'] ?? null,
            'currency' => $b['currency'] ?? '',
        ];
        if ($execType === 'LIMIT') {
            $body['limitPrice'] = $price;
        }
        if ($execType === 'STOP') {
            $body['stopPrice'] = $price;
        }
        [$row, $req, $err] = OrderService::orderRequest($body);
        if ($err !== '') {
            return [null, null, $err];
        }
        $row['role'] = $role;
        $row['parentId'] = $b['orderId'] ?? '';

        return [$row, $req, ''];
    }

    /** @param  array<string,mixed>  $b */
    public static function exitRow(array $b, string $role): ?array
    {
        $held = $role === 'stop' ? (string) ($b['slOrderId'] ?? '') : (string) ($b['tpOrderId'] ?? '');
        if ($held !== '') {
            $row = OrderStore::getOrder($held);
            if ($row) {
                return $row;
            }
        }
        foreach (OrderStore::listExitOrders((string) ($b['orderId'] ?? '')) as $o) {
            if (($o['role'] ?? '') === $role) {
                return $o;
            }
        }

        return null;
    }

    /** @param  array<string,mixed>  $b */
    public static function mayRetry(array $b): bool
    {
        $attempts = (int) ($b['attempts'] ?? 0);
        if ($attempts === 0) {
            return true;
        }
        $wait = self::RETRY_SEC[min($attempts, count(self::RETRY_SEC)) - 1];
        $updated = (string) ($b['updatedAt'] ?? '');
        $t = $updated !== '' ? strtotime($updated) : false;
        if ($t === false) {
            return true;
        }

        return (time() - $t) >= $wait;
    }

    /** @param  array<string,mixed>  $b */
    public static function fail(array $b, string $msg): void
    {
        $attempts = (int) ($b['attempts'] ?? 0) + 1;
        OrderStore::updateBracket((string) $b['id'], ['error' => $msg, 'attempts' => $attempts]);
        $next = self::RETRY_SEC[min($attempts, count(self::RETRY_SEC)) - 1];
        logger()->warning('bagholder bracket fail', [
            'id' => $b['id'] ?? '',
            'symbol' => $b['symbol'] ?? '',
            'msg' => $msg,
            'attempt' => $attempts,
            'nextSec' => $next,
        ]);
    }

    public static function stopAllowed(string $securityId): bool
    {
        $securityId = trim($securityId);
        if ($securityId === '') {
            return false;
        }
        if (array_key_exists($securityId, self::$stopAllowedCache)) {
            return self::$stopAllowedCache[$securityId];
        }
        // Under dry with no session: treat as watched (native placement skipped anyway).
        $sess = OrderService::ticketSession();
        if (! $sess) {
            return false;
        }
        try {
            $mdData = Api::graphql($sess, 'FetchSecurityMarketData', ['id' => $securityId], Queries::Q_FETCH_SECURITY_MARKET_DATA);
            $md = TicketQuote::parseMarketData($mdData);
            $ok = in_array('STOP', $md['orderTypes'] ?? [], true);
        } catch (\Throwable $e) {
            logger()->warning('bagholder bracket: order types unknown', ['id' => $securityId, 'err' => $e->getMessage()]);

            return false;
        }
        self::$stopAllowedCache[$securityId] = $ok;

        return $ok;
    }

    /**
     * @param  array<string,mixed>|null  $row
     * @param  array<string,mixed>|null  $quote
     */
    private static function rollDue(?array $row, ?array $quote, int $now): bool
    {
        if (! $row || ! in_array($row['status'] ?? '', ['sent', 'pending'], true)) {
            return false;
        }
        $left = self::expiresIn($row, $now);
        if ($left === null || $left > self::ROLL_SEC) {
            return false;
        }
        if ($left <= self::ROLL_LAST_SEC) {
            return true;
        }

        return strtoupper(trim((string) (($quote ?? [])['marketStatus'] ?? ''))) !== 'OPEN';
    }

    /** @param  array<string,mixed>  $row */
    private static function expiresIn(array $row, int $now): ?float
    {
        $exp = self::parseUtc((string) ($row['expiresAt'] ?? ''));
        if ($exp === null && strtoupper(trim((string) ($row['tif'] ?? ''))) === 'UNTIL_CANCEL') {
            $sub = self::parseUtc((string) ($row['submittedAt'] ?? $row['createdAt'] ?? ''));
            $exp = $sub !== null ? $sub + self::GTC_DAYS * 86400 : null;
        }

        return $exp !== null ? ($exp - $now) : null;
    }

    private static function parseUtc(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $t = strtotime(substr($text, 0, 19).' UTC');

        return $t === false ? null : $t;
    }

    /** @param  array<string,mixed>  $b */
    private static function trailDistance(array $b, float $price): ?float
    {
        if (($b['slKind'] ?? '') !== 'trail' || ! isset($b['slTrail']) || ! is_numeric($b['slTrail'])) {
            return null;
        }
        $trail = (float) $b['slTrail'];
        if (($b['slTrailUnit'] ?? 'pct') === 'pct') {
            return $price * $trail / 100.0;
        }

        return $trail;
    }

    /** @param  array<string,mixed>  $b */
    private static function nothingResting(array $b): bool
    {
        foreach (OrderStore::listExitOrders((string) ($b['orderId'] ?? '')) as $o) {
            if (in_array($o['status'] ?? '', self::BRACKET_INFLIGHT, true)) {
                return false;
            }
        }

        return true;
    }

    private static function sweepExits(): void
    {
        foreach (OrderStore::listOrders() as $o) {
            $role = (string) ($o['role'] ?? '');
            if (! in_array($role, ['stop', 'target'], true) || ! in_array($o['status'] ?? '', ['sent', 'pending'], true)) {
                continue;
            }
            $parent = (string) ($o['parentId'] ?? '');
            $b = $parent !== '' ? OrderStore::bracketForOrder($parent) : null;
            $held = $b && in_array($b['status'] ?? '', OrderService::BRACKET_LIVE, true)
                && (($b['status'] ?? '') === 'closing' || in_array($o['id'] ?? '', [$b['slOrderId'] ?? '', $b['tpOrderId'] ?? ''], true));
            if ($held) {
                continue;
            }
            self::sayOnce(($o['id'] ?? '').':orphan', 'bagholder bracket: orphan '.$role.' '.($o['id'] ?? '').' for '.($o['symbol'] ?? ''));
            if (OrderService::ordersLive()) {
                OrderService::cancelExit((string) ($o['id'] ?? ''));
            }
        }
    }

    /** @param  list<string>  $ids @return array<string,array<string,mixed>> */
    private static function fetchQuotes(array $ids): array
    {
        $sess = OrderService::ticketSession();
        if (! $sess || $ids === []) {
            return [];
        }
        $data = Api::graphql($sess, 'FetchSecuritiesSummary', ['ids' => $ids], Queries::Q_FETCH_SECURITIES_SUMMARY);
        $out = [];
        foreach ($data['securities'] ?? [] as $node) {
            $q = TicketQuote::parseQuote(is_array($node) ? $node : null);
            if ($q) {
                $out[$q['securityId']] = $q;
            }
        }

        return $out;
    }

    private static function sayOnce(string $key, string $msg): void
    {
        if (isset(self::$said[$key])) {
            return;
        }
        self::$said[$key] = true;
        logger()->info($msg);
    }

    /**
     * Desktop adjust_bracket — move/remove one leg. Dry: local only (no book cancel).
     *
     * @return array<string,mixed>
     */
    public static function adjustBracket(string $bracketId, string $leg, ?float $price = null, ?float $trail = null, bool $remove = false): array
    {
        $b = OrderStore::getBracket($bracketId);
        if (! $b) {
            return ['ok' => false, 'error' => 'No such bracket.'];
        }
        if (! in_array($b['status'] ?? '', OrderService::BRACKET_LIVE, true)) {
            return ['ok' => false, 'error' => 'That bracket is not live.'];
        }
        $leg = strtolower(trim($leg));
        if (! in_array($leg, ['sl', 'tp'], true)) {
            return ['ok' => false, 'error' => 'Which leg?'];
        }
        if ($remove) {
            if ($leg === 'sl') {
                $err = OrderService::cancelExit((string) ($b['slOrderId'] ?? ''));
                if ($err !== '' && OrderService::ordersLive()) {
                    return ['ok' => false, 'error' => $err];
                }
                $patch = ['slKind' => '', 'slOrderId' => '', 'slMode' => '', 'error' => ''];
                if (! ($b['tpPrice'] ?? null)) {
                    $patch['status'] = 'cancelled';
                    $patch['outcome'] = 'both legs removed';
                }
                OrderStore::updateBracket((string) $b['id'], $patch);
            } else {
                $err = OrderService::cancelExit((string) ($b['tpOrderId'] ?? ''));
                if ($err !== '' && OrderService::ordersLive()) {
                    return ['ok' => false, 'error' => $err];
                }
                $patch = ['tpPrice' => null, 'tpOrderId' => '', 'error' => ''];
                if (($b['status'] ?? '') === 'target_placed') {
                    $patch['status'] = 'armed';
                }
                if (($b['slKind'] ?? '') === '') {
                    $patch['status'] = 'cancelled';
                    $patch['outcome'] = 'both legs removed';
                }
                OrderStore::updateBracket((string) $b['id'], $patch);
            }

            return [
                'ok' => true,
                'id' => $b['id'],
                'dry' => ! OrderService::ordersLive(),
                'notice' => OrderService::ordersLive() ? 'Leg removed' : 'Leg removed locally (orders are off)',
            ];
        }
        if ($leg === 'sl') {
            if (($b['slKind'] ?? '') === '') {
                return ['ok' => false, 'error' => 'This bracket has no stop loss.'];
            }
            if (($b['slKind'] ?? '') === 'trail') {
                if ($trail === null || $trail <= 0) {
                    return ['ok' => false, 'error' => 'A trail is required.'];
                }
                $high = (float) ($b['highWater'] ?? $b['slPrice'] ?? 0);
                $nb = array_merge($b, ['slTrail' => $trail]);
                $newPrice = $high > 0 ? round($high - (self::trailDistance($nb, $high) ?? 0.0), 2) : ($b['slPrice'] ?? null);
                $patch = ['slTrail' => $trail, 'slPrice' => $newPrice];
            } else {
                if ($price === null || $price <= 0) {
                    return ['ok' => false, 'error' => 'A stop price is required.'];
                }
                $patch = ['slPrice' => OrderService::orderTick($price)];
            }
            if (($b['slOrderId'] ?? '') !== '' && ($b['status'] ?? '') === 'armed') {
                $err = OrderService::cancelExit((string) $b['slOrderId']);
                if ($err !== '' && OrderService::ordersLive()) {
                    return ['ok' => false, 'error' => $err];
                }
                $patch['slOrderId'] = '';
                $patch['movedAt'] = gmdate('Y-m-d\TH:i:s\Z');
            }
            $patch['error'] = '';
            OrderStore::updateBracket((string) $b['id'], $patch);

            return [
                'ok' => true,
                'id' => $b['id'],
                'dry' => ! OrderService::ordersLive(),
                'notice' => OrderService::ordersLive() ? 'Stop updated' : 'Stop updated locally (orders are off)',
            ];
        }
        if ($price === null || $price <= 0) {
            return ['ok' => false, 'error' => 'A limit price is required.'];
        }
        $patch = ['tpPrice' => OrderService::orderTick($price), 'error' => ''];
        if (($b['status'] ?? '') === 'target_placed' && ($b['tpOrderId'] ?? '') !== '') {
            $err = OrderService::cancelExit((string) $b['tpOrderId']);
            if ($err !== '' && OrderService::ordersLive()) {
                return ['ok' => false, 'error' => $err];
            }
            $patch['tpOrderId'] = '';
            $patch['status'] = 'armed';
        }
        OrderStore::updateBracket((string) $b['id'], $patch);

        return [
            'ok' => true,
            'id' => $b['id'],
            'dry' => ! OrderService::ordersLive(),
            'notice' => OrderService::ordersLive() ? 'Target updated' : 'Target updated locally (orders are off)',
        ];
    }

    /**
     * Edit bracket SL/TP locally (dry) or via resting order modify when live.
     *
     * @return array<string,mixed>
     */
    public static function modifyBracket(string $bracketId, ?float $slPrice = null, ?float $tpPrice = null, ?float $quantity = null, ?float $slTrail = null): array
    {
        $b = OrderStore::getBracket($bracketId);
        if (! $b) {
            return ['ok' => false, 'error' => 'No such bracket.'];
        }
        if (! in_array($b['status'] ?? '', OrderService::BRACKET_LIVE, true)) {
            return ['ok' => false, 'error' => 'That bracket is not live.'];
        }
        // Prefer adjust_bracket semantics when trail or single-leg edits.
        if (($b['slKind'] ?? '') === 'trail' && $slTrail !== null && $slTrail > 0) {
            $r = self::adjustBracket($bracketId, 'sl', null, $slTrail, false);
            if (empty($r['ok'])) {
                return $r;
            }
            if ($tpPrice !== null && $tpPrice > 0) {
                $r2 = self::adjustBracket($bracketId, 'tp', $tpPrice, null, false);
                if (empty($r2['ok'])) {
                    return $r2;
                }
            }

            return $r;
        }
        if ($slPrice !== null && $slPrice > 0 && ($b['slKind'] ?? '') !== '' && ($b['slKind'] ?? '') !== 'trail') {
            $r = self::adjustBracket($bracketId, 'sl', $slPrice, null, false);
            if (empty($r['ok'])) {
                return $r;
            }
        }
        if ($tpPrice !== null && $tpPrice > 0) {
            $r = self::adjustBracket($bracketId, 'tp', $tpPrice, null, false);
            if (empty($r['ok'])) {
                return $r;
            }
        }
        if ($quantity !== null && $quantity > 0) {
            OrderStore::updateBracket((string) $b['id'], ['quantity' => $quantity]);
        }

        return [
            'ok' => true,
            'id' => $b['id'],
            'dry' => ! OrderService::ordersLive(),
            'notice' => OrderService::ordersLive() ? 'Bracket updated' : 'Bracket edit recorded locally (orders are off)',
        ];
    }
}
