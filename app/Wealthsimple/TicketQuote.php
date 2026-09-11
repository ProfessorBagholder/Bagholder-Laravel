<?php

namespace App\Wealthsimple;

use App\Journal\Fx;
use App\Models\Meta;

/**
 * Desktop ticket_quote — live GraphQL when a real session is present; Meta/desktop DB
 * fallback with honest gaps (no fabricated bid/ask/BP).
 */
final class TicketQuote
{
    /**
     * @return array<string,mixed>
     */
    public static function quote(string $symbol = '', string $securityId = '', string $accountId = '', string $exchange = ''): array
    {
        $symbol = strtoupper(trim($symbol));
        $securityId = trim($securityId);
        $accountId = trim($accountId);
        $exchange = trim($exchange);

        $sec = OrderStore::resolveSecurity($symbol, $securityId);
        if (! $sec && $exchange === '') {
            return ['ok' => false, 'error' => 'No listing stored for '.($symbol !== '' ? $symbol : $securityId).'.'];
        }

        $sess = OrderService::ticketSession();
        if ($sess) {
            if (! $sec && $exchange !== '' && $symbol !== '') {
                $sec = self::lookupListing($sess, $symbol, $exchange);
            }
            if (! $sec) {
                return ['ok' => false, 'error' => 'No listing stored for '.($symbol !== '' ? $symbol : $securityId).'.'];
            }
            try {
                return self::liveQuote($sess, $sec, $accountId);
            } catch (\Throwable $e) {
                // Fall through to Meta with an honest note.
                $liveErr = $e->getMessage() ?: $e::class;
            }
        }

        if (! $sec) {
            return ['ok' => false, 'error' => 'No listing stored for '.($symbol !== '' ? $symbol : $securityId).'.'];
        }

        $meta = self::metaQuote($sec, $accountId);
        if (isset($liveErr)) {
            $meta['gaps'] = array_values(array_unique(array_merge($meta['gaps'] ?? [], ['live quote failed: '.$liveErr])));
            $meta['error'] = ''; // still ok with gaps
        }
        if (! $sess) {
            $meta['gaps'] = array_values(array_unique(array_merge($meta['gaps'] ?? [], ['Not connected — Meta quote only'])));
        }

        return $meta;
    }

    /**
     * @param  array<string,mixed>  $sess
     * @param  array<string,mixed>  $sec
     * @return array<string,mixed>
     */
    private static function liveQuote(array $sess, array $sec, string $accountId): array
    {
        try {
            $data = Api::graphql($sess, 'FetchSecuritiesSummary', ['ids' => [$sec['id']]], Queries::Q_FETCH_SECURITIES_SUMMARY);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: $e::class;
            if (stripos($msg, '401') !== false || stripos($msg, 'refus') !== false || stripos($msg, 'unauthor') !== false) {
                return ['ok' => false, 'error' => 'Wealthsimple refused the session. Connect Wealthsimple again.'];
            }
            throw $e;
        }
        $nodes = $data['securities'] ?? [];
        $node = is_array($nodes[0] ?? null) ? $nodes[0] : null;
        $quote = self::parseQuote($node);
        if (! $quote) {
            return ['ok' => false, 'error' => 'Wealthsimple has no quote for '.(($sec['symbol'] ?? '') ?: $sec['id']).'.'];
        }
        if ($quote['symbol'] === '') {
            $quote['symbol'] = (string) ($sec['symbol'] ?? '');
        }
        if ($quote['name'] === '') {
            $quote['name'] = (string) ($sec['name'] ?? '');
        }
        if ($quote['exchange'] === '') {
            $quote['exchange'] = (string) ($sec['primaryExchange'] ?? '');
        }
        if ($quote['currency'] === '') {
            $quote['currency'] = strtoupper((string) ($sec['currency'] ?? ''));
        }

        $md = ['orderTypes' => OrderService::EXEC_TYPES, 'marginRate' => null];
        try {
            $mdData = Api::graphql($sess, 'FetchSecurityMarketData', ['id' => $sec['id']], Queries::Q_FETCH_SECURITY_MARKET_DATA);
            $md = self::parseMarketData($mdData);
        } catch (\Throwable $e) {
            logger()->warning('bagholder ticket: market data failed', ['id' => $sec['id'], 'err' => $e->getMessage()]);
        }

        $accounts = OrderStore::orderAccounts();
        $acct = null;
        foreach ($accounts as $a) {
            if ($a['id'] === $accountId) {
                $acct = $a;
                break;
            }
        }
        $balance = ['buyingPower' => null, 'cash' => null, 'currency' => ''];
        $ccyLive = $quote['currency'] !== '' ? $quote['currency'] : 'CAD';
        if ($acct) {
            try {
                $bpData = Api::graphql($sess, 'FetchTradingBalanceBuyingPower', [
                    'accountCanonicalId' => $acct['id'],
                    'currency' => $ccyLive,
                    'securityId' => $sec['id'],
                ], Queries::Q_FETCH_TRADING_BALANCE_BUYING_POWER);
                $balance = self::parseBuyingPower($bpData);
            } catch (\Throwable $e) {
                logger()->warning('bagholder ticket: buying power failed', ['id' => $acct['id'], 'err' => $e->getMessage()]);
            }
            // Session quote gaps: fill cash/BP from desktop balances / margin when GraphQL omits them.
            if ($balance['cash'] === null) {
                $balance['cash'] = OrderStore::cashBalance((string) $acct['id'], $ccyLive);
            }
            if ($balance['buyingPower'] === null) {
                $balance['buyingPower'] = self::marginAvailable($acct) ?? $balance['cash'];
            }
        }

        return [
            'ok' => true,
            'quote' => $quote,
            'orderTypes' => $md['orderTypes'] ?: OrderService::EXEC_TYPES,
            'marginRate' => $md['marginRate'],
            'accounts' => $accounts,
            'account' => $acct,
            'buyingPower' => $balance['buyingPower'],
            'cash' => $balance['cash'],
            'marginAvailable' => self::marginAvailable($acct),
            'fxUsdCad' => self::fxUsdCad(),
            'live' => OrderService::ordersLive(),
            'source' => 'live',
            'gaps' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $sec
     * @return array<string,mixed>
     */
    private static function metaQuote(array $sec, string $accountId): array
    {
        $sym = strtoupper((string) ($sec['symbol'] ?? ''));
        $row = null;
        try {
            $quotes = Meta::json('quotes', []);
            $row = is_array($quotes) ? ($quotes[$sym] ?? null) : null;
        } catch (\Throwable) {
            $row = null;
        }
        if (! is_array($row)) {
            $row = self::desktopQuoteRow($sym);
        }
        $last = (is_array($row) && isset($row['price']) && is_numeric($row['price'])) ? (float) $row['price'] : null;
        $chg = (is_array($row) && isset($row['priceChange']) && is_numeric($row['priceChange'])) ? (float) $row['priceChange'] : null;
        $pct = (is_array($row) && isset($row['percentChange']) && is_numeric($row['percentChange'])) ? (float) $row['percentChange'] / 100.0 : null;
        // Meta rarely has BBO; prefer stored keys when present.
        $bid = self::num($row['bid'] ?? null);
        $ask = self::num($row['ask'] ?? null);
        $mid = self::num($row['mid'] ?? null);
        if ($mid === null && $bid !== null && $ask !== null) {
            $mid = ($bid + $ask) / 2.0;
        }
        $gaps = [];
        if ($last === null) {
            $gaps[] = 'No Meta last price';
        }
        if ($bid === null || $ask === null) {
            $gaps[] = 'No live bid/ask (Meta)';
        }
        $gaps[] = 'Buying power / cash from session when connected';

        $accounts = OrderStore::orderAccounts();
        $acct = null;
        foreach ($accounts as $a) {
            if ($a['id'] === $accountId) {
                $acct = $a;
                break;
            }
        }
        $marginAvail = self::marginAvailable($acct);
        $ccy = strtoupper((string) ($sec['currency'] ?? '')) ?: 'CAD';
        $cash = $acct ? OrderStore::cashBalance((string) ($acct['id'] ?? ''), $ccy) : null;
        // Prefer margin BP when present; else cash from balances for cash accounts.
        $buyingPower = $marginAvail;
        if ($buyingPower === null && $cash !== null) {
            $buyingPower = $cash;
        }
        if ($cash !== null || $buyingPower !== null) {
            $gaps = array_values(array_filter($gaps, fn ($g) => ! str_contains($g, 'Buying power / cash')));
            if ($cash === null) {
                $gaps[] = 'Cash from session when connected';
            }
            if ($buyingPower === null) {
                $gaps[] = 'Buying power from session when connected';
            }
        }

        $quote = [
            'securityId' => (string) ($sec['id'] ?? ''),
            'symbol' => $sym,
            'name' => (string) ($sec['name'] ?? ''),
            'exchange' => (string) ($sec['primaryExchange'] ?? ''),
            'currency' => strtoupper((string) ($sec['currency'] ?? '')),
            'securityType' => '',
            'buyable' => true,
            'sellable' => true,
            'tradeEligible' => true,
            'status' => '',
            'last' => $last,
            'bid' => $bid,
            'ask' => $ask,
            'bidSize' => null,
            'askSize' => null,
            'mid' => $mid,
            'change' => $chg,
            'changePct' => $pct,
            'marketStatus' => '',
            'quotedAsOf' => is_array($row) ? (string) ($row['fetchedAt'] ?? '') : '',
            'multiplier' => null,
        ];

        return [
            'ok' => $last !== null,
            'error' => $last === null ? ('No quote for '.$sym.'.') : '',
            'quote' => $quote,
            'orderTypes' => OrderService::EXEC_TYPES,
            'marginRate' => null,
            'accounts' => $accounts,
            'account' => $acct,
            'buyingPower' => $buyingPower,
            'cash' => $cash,
            'marginAvailable' => $marginAvail,
            'fxUsdCad' => self::fxUsdCad(),
            'live' => OrderService::ordersLive(),
            'source' => 'meta',
            'gaps' => $gaps,
        ];
    }

    /** @param  array<string,mixed>|null  $node */
    public static function parseQuote(?array $node): ?array
    {
        if (! is_array($node) || empty($node['id'])) {
            return null;
        }
        $q = is_array($node['quoteV2'] ?? null) ? $node['quoteV2'] : [];
        $stock = is_array($node['stock'] ?? null) ? $node['stock'] : [];
        $opt = is_array($node['optionDetails'] ?? null) ? $node['optionDetails'] : [];
        $last = self::num($q['price'] ?? null);
        if ($last === null) {
            $last = self::num($q['last'] ?? null);
        }
        $base = self::num($q['previousBaseline'] ?? null);
        if ($base === null) {
            $base = self::num($q['referenceClose'] ?? null);
        }
        $bid = self::num($q['bid'] ?? null);
        $ask = self::num($q['ask'] ?? null);
        $change = ($last !== null && $base !== null) ? ($last - $base) : null;
        $mid = array_key_exists('mid', $q) ? self::num($q['mid']) : null;
        if ($mid === null && $bid !== null && $ask !== null) {
            $mid = ($bid + $ask) / 2.0;
        }

        return [
            'securityId' => (string) $node['id'],
            'symbol' => (string) ($stock['symbol'] ?? ''),
            'name' => (string) ($stock['name'] ?? ''),
            'exchange' => (string) ($stock['primaryExchange'] ?? ''),
            'currency' => strtoupper((string) ($q['currency'] ?? $node['currency'] ?? '')),
            'securityType' => (string) ($node['securityType'] ?? ''),
            'buyable' => (bool) ($node['buyable'] ?? false),
            'sellable' => (bool) ($node['sellable'] ?? false),
            'tradeEligible' => (bool) ($node['wsTradeEligible'] ?? false),
            'status' => (string) ($node['status'] ?? ''),
            'last' => $last,
            'bid' => $bid,
            'ask' => $ask,
            'bidSize' => self::num($q['bidSize'] ?? null),
            'askSize' => self::num($q['askSize'] ?? null),
            'mid' => $mid,
            'change' => $change,
            'changePct' => ($change !== null && $base) ? ($change / $base) : null,
            'marketStatus' => (string) ($q['marketStatus'] ?? ''),
            'quotedAsOf' => (string) ($q['quotedAsOf'] ?? ''),
            'multiplier' => $opt !== [] ? self::num($opt['multiplier'] ?? null) : null,
        ];
    }

    /** @param  array<string,mixed>|null  $data */
    public static function parseMarketData(?array $data): array
    {
        $sec = is_array($data['security'] ?? null) ? $data['security'] : [];
        $subtypes = [];
        foreach ($sec['allowedOrderSubtypes'] ?? [] as $s) {
            if ($s) {
                $subtypes[] = strtoupper((string) $s);
            }
        }
        $rate = self::num(is_array($sec['marginRates'] ?? null) ? ($sec['marginRates']['clientMarginRate'] ?? null) : null);
        if ($rate !== null && $rate > 1) {
            $rate = $rate / 100.0;
        }

        $types = array_values(array_filter(OrderService::EXEC_TYPES, fn ($t) => in_array($t, $subtypes, true)));

        return [
            'orderTypes' => $types !== [] ? $types : OrderService::EXEC_TYPES,
            'marginRate' => $rate,
        ];
    }

    /** @param  array<string,mixed>|null  $data */
    public static function parseBuyingPower(?array $data): array
    {
        $view = $data['account']['financials']['current']['tradingBalanceViewV2'] ?? [];
        if (! is_array($view)) {
            $view = [];
        }
        $bp = is_array($view['buyingPower'] ?? null) ? $view['buyingPower'] : [];
        $cash = is_array($view['cash'] ?? null) ? $view['cash'] : [];

        return [
            'buyingPower' => self::num($bp['quantity'] ?? null),
            'cash' => self::num($cash['quantity'] ?? null),
            'currency' => (string) ($bp['currency'] ?? $cash['currency'] ?? ''),
        ];
    }

    /** @param  array<string,mixed>|null  $acct */
    public static function marginAvailable(?array $acct): ?float
    {
        if (! $acct) {
            return null;
        }
        $mid = (string) ($acct['marginAccountId'] ?? '');
        if ($mid === '') {
            return null;
        }
        $path = OrderStore::dbPath();
        if (! is_file($path)) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $st = $pdo->prepare('SELECT buying_power FROM margin WHERE account_id = ? LIMIT 1');
            $st->execute([$mid]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if ($r && isset($r['buying_power']) && is_numeric($r['buying_power'])) {
                return (float) $r['buying_power'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public static function fxUsdCad(): ?float
    {
        // Prefer Journal Fx when booted; else latest desktop fx_rates.
        try {
            $r = Fx::rateOn(gmdate('Y-m-d'));
            if ($r > 0) {
                return $r;
            }
        } catch (\Throwable) {
        }
        $path = OrderStore::dbPath();
        if (! is_file($path)) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $r = $pdo->query("SELECT rate FROM fx_rates WHERE pair = 'USDCAD' ORDER BY date DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if ($r && is_numeric($r['rate'])) {
                return (float) $r['rate'];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $sess
     * @return array<string,mixed>|null
     */
    private static function lookupListing(array $sess, string $symbol, string $exchange): ?array
    {
        try {
            $data = Api::graphql($sess, 'FetchSecuritySearchResult', ['query' => $symbol], Queries::Q_FETCH_SECURITY_SEARCH_RESULT);
        } catch (\Throwable $e) {
            logger()->warning('bagholder ticket: listing search failed', ['symbol' => $symbol, 'err' => $e->getMessage()]);

            return null;
        }
        $wantSym = self::bareSymbol($symbol);
        $wantEx = strtoupper(trim($exchange));
        foreach ($data['securitySearch']['results'] ?? [] as $r) {
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            $stock = is_array($r['stock'] ?? null) ? $r['stock'] : [];
            if (self::bareSymbol((string) ($stock['symbol'] ?? '')) !== $wantSym) {
                continue;
            }
            if (strtoupper((string) ($stock['primaryExchange'] ?? '')) !== $wantEx) {
                continue;
            }
            $type = strtoupper((string) ($r['securityType'] ?? ''));
            if ($type !== '' && ! in_array($type, ['EQUITY', 'EXCHANGE_TRADED_FUND'], true)) {
                continue;
            }

            return [
                'id' => (string) $r['id'],
                'symbol' => strtoupper((string) ($stock['symbol'] ?? '')),
                'name' => (string) ($stock['name'] ?? ''),
                'primaryExchange' => (string) ($stock['primaryExchange'] ?? ''),
                'primaryMic' => (string) ($stock['primaryMic'] ?? ''),
                'currency' => strtoupper((string) ($r['currency'] ?? '')),
                'underlyingId' => null,
            ];
        }

        return null;
    }

    private static function bareSymbol(string $sym): string
    {
        $sym = strtoupper(trim($sym));
        foreach (['.TO', '.V', '.CN', '.NE'] as $suf) {
            if (str_ends_with($sym, $suf)) {
                return substr($sym, 0, -strlen($suf));
            }
        }

        return $sym;
    }


    /** @return array<string,mixed>|null */
    private static function desktopQuoteRow(string $symbol): ?array
    {
        $path = OrderStore::dbPath();
        if ($symbol === '' || ! is_file($path)) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $st = $pdo->prepare('SELECT * FROM quotes WHERE symbol = ? LIMIT 1');
            $st->execute([$symbol]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if (! $r) {
                return null;
            }
            // Desktop quotes table may store JSON blob or columns.
            if (isset($r['json']) && is_string($r['json'])) {
                $d = json_decode($r['json'], true);
                if (is_array($d)) {
                    return $d;
                }
            }
            $out = [];
            foreach (['price', 'priceChange', 'percentChange', 'bid', 'ask', 'mid', 'fetchedAt', 'source'] as $k) {
                $snake = strtolower(preg_replace('/[A-Z]/', '_$0', $k));
                if (array_key_exists($k, $r)) {
                    $out[$k] = $r[$k];
                } elseif (array_key_exists($snake, $r)) {
                    $out[$k] = $r[$snake];
                }
            }
            // Common desktop column names
            if (! isset($out['price']) && isset($r['price'])) {
                $out['price'] = $r['price'];
            }
            if (! isset($out['price']) && isset($r['last'])) {
                $out['price'] = $r['last'];
            }

            return $out !== [] ? $out : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }
}
