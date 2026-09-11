<?php

use App\Journal\Orders;
use App\Models\Meta;
use App\Support\Money;
use App\Wealthsimple\OrderService;
use App\Wealthsimple\OrderStore;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    #[Reactive]
    public bool $open = false;

    #[Reactive]
    public string $symbol = '';

    #[Reactive]
    public string $side = 'BUY';

    #[Reactive]
    public string $exchange = '';

    /** Account id from parent filter (empty = default tradable). */
    #[Reactive]
    public string $account = '';

    /** Held shares for Max on Sell (parent positions). */
    #[Reactive]
    public ?float $heldQty = null;

    public string $step = 'form';

    public string $type = 'LIMIT';

    public string $tif = 'DAY';

    public string $qty = '1';

    public string $amount = '';

    public string $limit = '';

    public string $stop = '';

    public string $accountId = '';

    public string $securityId = '';

    public string $lastError = '';

    public bool $slOn = true;

    public string $slKind = 'stop';

    public string $slPrice = '';

    public string $slPct = '';

    public string $slPriceUnit = 'amt';

    public string $slTrail = '';

    public string $slTrailUnit = 'pct';

    public bool $tpOn = true;

    public string $tpPrice = '';

    public string $tpPct = '';

    public string $tpUnit = 'amt';

    /** @var array<string,mixed>|null */
    public ?array $quoteData = null;

    public string $quoteError = '';

    public bool $busy = false;

    public function mount(): void
    {
        $this->pickDefaultAccount();
    }

    public function updatedOpen(bool $open): void
    {
        if ($open) {
            $this->step = 'form';
            $this->lastError = '';
            $this->quoteError = '';
            $this->busy = false;
            $this->pickDefaultAccount();
            $this->restoreDraft();
            $this->refreshQuote();
            $this->seedLimitFromQuote();
            $this->seedBracketsFromEntry();
            $this->syncAmountFromQty();
        }
    }

    public function updatedAccountId(): void
    {
        try {
            session(['bh.ticketAccount' => $this->accountId]);
        } catch (\Throwable) {
        }
        $this->refreshQuote();
    }

    public function updatedQty(): void
    {
        $this->syncAmountFromQty();
    }

    public function updatedAmount(): void
    {
        $v = $this->ticketVals();
        $entry = $v['entry'];
        $mult = $v['mult'];
        $amt = $this->num($this->amount);
        if ($entry && $entry > 0 && $mult > 0 && $amt !== null && $amt >= 0) {
            $this->qty = (string) max(0, (int) floor($amt / ($entry * $mult)));
        }
    }

    public function updatedType(): void
    {
        $this->seedLimitFromQuote();
        $this->syncAmountFromQty();
    }

    public function updatedLimit(): void
    {
        $this->syncAmountFromQty();
        $this->seedBracketsFromEntry();
    }

    public function pollQuote(): void
    {
        if (! $this->open || $this->step !== 'form') {
            return;
        }
        $this->refreshQuote();
    }

    public function refreshQuote(): void
    {
        $r = OrderService::quote($this->symbol, $this->securityId, $this->accountId, $this->exchange);
        if (! empty($r['ok'])) {
            $this->quoteData = $r;
            $this->quoteError = '';
            $q = is_array($r['quote'] ?? null) ? $r['quote'] : [];
            if ($this->securityId === '' && ! empty($q['securityId'])) {
                $this->securityId = (string) $q['securityId'];
            }
            $types = $r['orderTypes'] ?? [];
            if (is_array($types) && $types !== [] && ! in_array($this->type, $types, true)) {
                $this->type = in_array('LIMIT', $types, true) ? 'LIMIT' : (string) $types[0];
            }
            if ($this->accountId === '' && ! empty($r['accounts'])) {
                $this->pickDefaultAccount();
            }
            $this->seedLimitFromQuote();
            $this->syncAmountFromQty();
        } else {
            $this->quoteError = (string) ($r['error'] ?? 'Quote failed.');
            // Keep prior quoteData if any; Meta gaps still useful.
            if ($this->quoteData === null && is_array($r)) {
                $this->quoteData = $r;
            }
        }
    }

    private function seedLimitFromQuote(): void
    {
        if ($this->limit !== '' || ! in_array($this->type, ['LIMIT', 'STOP_LIMIT'], true)) {
            return;
        }
        $last = $this->quoteLast();
        if ($last !== null && $last > 0) {
            $this->limit = (string) OrderService::orderTick($last);
        }
    }

    private function quoteLast(): ?float
    {
        $q = is_array($this->quoteData['quote'] ?? null) ? $this->quoteData['quote'] : [];
        if (isset($q['last']) && is_numeric($q['last']) && (float) $q['last'] > 0) {
            return (float) $q['last'];
        }
        // Meta-shaped fallback via local Meta if quoteData empty.
        $sym = strtoupper(trim($this->symbol));
        $quotes = Meta::json('quotes', []);
        $row = is_array($quotes) ? ($quotes[$sym] ?? null) : null;
        if (is_array($row) && isset($row['price']) && (float) $row['price'] > 0) {
            return (float) $row['price'];
        }

        return null;
    }

    /** Desktop-like last + change + BBO from quoteData. */
    public function quoteLine(): array
    {
        $q = is_array($this->quoteData['quote'] ?? null) ? $this->quoteData['quote'] : null;
        if (! is_array($q) || ! isset($q['last']) || ! is_numeric($q['last'])) {
            return [
                'price' => '', 'change' => '', 'tone' => '',
                'gap' => $this->quoteError !== '' ? $this->quoteError : 'No quote',
                'name' => '', 'bid' => '', 'ask' => '', 'mid' => '',
                'bidSize' => '', 'askSize' => '',
            ];
        }
        $price = (float) $q['last'];
        $digits = $price >= 1 ? 2 : 4;
        $chg = isset($q['change']) && is_numeric($q['change']) ? (float) $q['change'] : null;
        $pct = isset($q['changePct']) && is_numeric($q['changePct']) ? (float) $q['changePct'] : null;
        $change = '';
        $tone = '';
        if ($chg !== null) {
            $change = Money::signedCad($chg, $digits);
            if ($pct !== null) {
                $change .= ' ('.($pct < 0 ? '−' : '+').number_format(abs($pct) * 100, 2).'%)';
            }
            $tone = $chg > 0 ? 'pos' : ($chg < 0 ? 'neg' : '');
        }
        $px = function ($k) use ($q, $digits): string {
            $v = $q[$k] ?? null;

            return isset($v) && is_numeric($v) ? Money::formatCad((float) $v, $digits) : '';
        };
        $sz = function ($k) use ($q): string {
            $v = $q[$k] ?? null;

            return isset($v) && is_numeric($v) ? ' × '.number_format((float) $v, 0) : '';
        };

        return [
            'price' => Money::formatCad($price, $digits),
            'change' => $change,
            'tone' => $tone,
            'gap' => '',
            'name' => trim((string) ($q['name'] ?? '')),
            'bid' => $px('bid'),
            'ask' => $px('ask'),
            'mid' => $px('mid'),
            'bidSize' => $sz('bidSize'),
            'askSize' => $sz('askSize'),
        ];
    }

    public function updatedAccount(): void
    {
        $this->pickDefaultAccount();
    }

    private function pickDefaultAccount(): void
    {
        $accounts = OrderStore::orderAccounts();
        // Sell → held account when parent filter empty (desktop).
        if ($this->account === '' && strtoupper($this->side) === 'SELL' && $this->heldQty !== null) {
            // Parent may set account via filter; remembered still applies below.
        }
        if ($this->account !== '') {
            foreach ($accounts as $a) {
                if ($a['id'] === $this->account || $a['name'] === $this->account) {
                    $this->accountId = $a['id'];

                    return;
                }
            }
        }
        $remembered = (string) (session('bh.ticketAccount') ?? '');
        if ($remembered !== '') {
            foreach ($accounts as $a) {
                if ($a['id'] === $remembered) {
                    $this->accountId = $a['id'];

                    return;
                }
            }
        }
        if ($this->accountId !== '') {
            foreach ($accounts as $a) {
                if ($a['id'] === $this->accountId) {
                    return;
                }
            }
        }
        $this->accountId = $accounts[0]['id'] ?? '';
        foreach ($accounts as $a) {
            if (! empty($a['margin'])) {
                $this->accountId = $a['id'];
                break;
            }
        }
    }

    /** @return list<array{id:string,name:string,margin:bool,marginAccountId:string}> */
    public function accounts(): array
    {
        return array_map(fn ($a) => [
            'id' => $a['id'],
            'name' => $a['name'],
            'margin' => ! empty($a['margin']),
            'marginAccountId' => (string) ($a['marginAccountId'] ?? ''),
        ], OrderStore::orderAccounts());
    }

    public function toggleSl(): void
    {
        $this->slOn = ! $this->slOn;
        if ($this->slOn) {
            $this->seedBracketsFromEntry();
        }
    }

    public function toggleTp(): void
    {
        $this->tpOn = ! $this->tpOn;
        if ($this->tpOn) {
            $this->seedBracketsFromEntry();
        }
    }

    public function setSlKind(string $kind): void
    {
        $this->slKind = $kind === 'trail' ? 'trail' : 'stop';
    }

    public function setSlUnit(string $unit): void
    {
        $unit = $unit === 'amt' ? 'amt' : 'pct';
        if ($this->slKind === 'trail') {
            $this->slTrailUnit = $unit;
        } else {
            $this->slPriceUnit = $unit;
        }
    }

    public function setTpUnit(string $unit): void
    {
        $this->tpUnit = $unit === 'amt' ? 'amt' : 'pct';
    }

    private function workingEntry(): ?float
    {
        $q = is_array($this->quoteData['quote'] ?? null) ? $this->quoteData['quote'] : [];
        $last = isset($q['last']) && is_numeric($q['last']) ? (float) $q['last'] : $this->quoteLast();
        $ask = isset($q['ask']) && is_numeric($q['ask']) ? (float) $q['ask'] : null;
        $bid = isset($q['bid']) && is_numeric($q['bid']) ? (float) $q['bid'] : null;
        $buy = strtoupper($this->side) === 'BUY';
        if ($this->type === 'MARKET') {
            $px = $buy ? ($ask ?? $last) : ($bid ?? $last);

            return $px !== null ? OrderService::orderTick($px) : null;
        }
        if ($this->type === 'STOP') {
            if ($this->stop !== '' && is_numeric($this->stop) && (float) $this->stop > 0) {
                return OrderService::orderTick((float) $this->stop);
            }

            return $last !== null ? OrderService::orderTick($last * ($buy ? 1.02 : 0.98)) : null;
        }
        if ($this->limit !== '' && is_numeric($this->limit) && (float) $this->limit > 0) {
            return OrderService::orderTick((float) $this->limit);
        }

        return $last !== null ? OrderService::orderTick($last) : null;
    }

    public function plain(?float $n): string
    {
        if ($n === null || ! is_finite($n)) {
            return '';
        }
        if (abs($n - round($n)) < 0.0000001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return is_numeric($s) ? (float) $s : null;
    }

    private function syncAmountFromQty(): void
    {
        $v = $this->ticketVals();
        if ($v['notional'] !== null) {
            $n = (float) $v['notional'];
            $this->amount = abs($n - round($n)) < 0.005 ? (string) (int) round($n) : number_format($n, 2, '.', '');
        }
    }

    public function maxQty(): ?float
    {
        $v = $this->ticketVals();
        if (! $v['buy']) {
            return ($this->heldQty !== null && $this->heldQty > 0) ? (float) $this->heldQty : null;
        }
        $bp = $this->quoteData['buyingPower'] ?? null;
        $entry = $v['entry'];
        $mult = $v['mult'];
        if ($bp !== null && is_numeric($bp) && $entry && $entry > 0 && $mult > 0) {
            return (float) max(0, (int) floor(((float) $bp) / ($entry * $mult)));
        }

        return null;
    }

    public function applyMax(): void
    {
        $m = $this->maxQty();
        if ($m === null) {
            return;
        }
        $this->qty = (string) $m;
        $this->syncAmountFromQty();
    }

    /**
     * Desktop tkVals — entry/notional/SL/TP/RR/cash-after.
     *
     * @return array<string,mixed>
     */
    public function ticketVals(): array
    {
        $buy = strtoupper($this->side) === 'BUY';
        $dir = $buy ? 1.0 : -1.0;
        $qtyN = is_numeric($this->qty) ? (float) $this->qty : 0.0;
        $q = is_array($this->quoteData['quote'] ?? null) ? $this->quoteData['quote'] : [];
        $mult = isset($q['multiplier']) && is_numeric($q['multiplier']) && (float) $q['multiplier'] > 0
            ? (float) $q['multiplier']
            : (Orders::isOptionSymbol($this->symbol) ? 100.0 : 1.0);
        $entry = $this->workingEntry();
        $notional = ($entry !== null) ? $qtyN * $entry * $mult : null;
        $isTrail = $this->slKind === 'trail';
        $trailTyped = $this->slTrail !== '' && is_numeric($this->slTrail) ? (float) $this->slTrail : null;
        $trail = $trailTyped ?? ($this->slTrailUnit === 'pct' ? 5.0 : ($entry !== null ? round($entry * 0.05, 2) : null));
        $trailDist = ($entry === null || $trail === null) ? null : ($this->slTrailUnit === 'pct' ? $entry * $trail / 100.0 : $trail);
        $slPctIn = $this->slPct !== '' && is_numeric($this->slPct) ? (float) $this->slPct : 5.0;
        $slPriceTyped = $this->slPrice !== '' && is_numeric($this->slPrice) ? (float) $this->slPrice : null;
        if ($isTrail) {
            $slPrice = ($entry !== null && $trailDist !== null) ? round($entry - $dir * $trailDist, 2) : null;
        } elseif ($this->slPriceUnit === 'pct') {
            $slPrice = $entry !== null ? round($entry * (1 - $dir * $slPctIn / 100.0), 2) : null;
        } else {
            $slPrice = $slPriceTyped ?? ($entry !== null ? round($entry * (1 - $dir * 0.05), 2) : null);
        }
        $tpPctIn = $this->tpPct !== '' && is_numeric($this->tpPct) ? (float) $this->tpPct : 10.0;
        $tpPriceTyped = $this->tpPrice !== '' && is_numeric($this->tpPrice) ? (float) $this->tpPrice : null;
        if ($this->tpUnit === 'pct') {
            $tpPrice = $entry !== null ? round($entry * (1 + $dir * $tpPctIn / 100.0), 2) : null;
        } else {
            $tpPrice = $tpPriceTyped ?? ($entry !== null ? round($entry * (1 + $dir * 0.10), 2) : null);
        }
        $slOn = $buy && $this->slOn;
        $tpOn = $buy && $this->tpOn;
        $risk = ($entry !== null && $slPrice !== null)
            ? (($isTrail && $trailDist !== null) ? $trailDist : $dir * ($entry - $slPrice)) * $qtyN * $mult
            : null;
        $gain = ($entry !== null && $tpPrice !== null) ? $dir * ($tpPrice - $entry) * $qtyN * $mult : null;
        $slPct = ($entry && $slPrice !== null)
            ? (($isTrail && $trailDist !== null) ? -$trailDist / $entry : $dir * ($slPrice - $entry) / $entry)
            : null;
        $tpPct = ($entry && $tpPrice !== null) ? $dir * ($tpPrice - $entry) / $entry : null;
        $rr = ($slOn && $tpOn && $risk !== null && $risk > 0 && $gain !== null) ? ($gain / $risk) : null;
        $fx = 1.0;
        $ccy = strtoupper((string) ($q['currency'] ?? ''));
        if ($ccy === 'USD' && isset($this->quoteData['fxUsdCad']) && is_numeric($this->quoteData['fxUsdCad'])) {
            $fx = (float) $this->quoteData['fxUsdCad'];
        }
        $cad = $notional !== null ? $notional * $fx : null;
        $nav = $this->navCad();
        $acct = null;
        foreach ($this->accounts() as $a) {
            if ($a['id'] === $this->accountId) {
                $acct = $a;
                break;
            }
        }
        $isMargin = ! empty($acct['margin']);
        $linkedMargin = $acct && empty($acct['margin']) && ($acct['marginAccountId'] ?? '') !== '';
        $rate = isset($this->quoteData['marginRate']) && is_numeric($this->quoteData['marginRate'])
            ? (float) $this->quoteData['marginRate'] : 1.0;
        $marginAvail = isset($this->quoteData['marginAvailable']) && is_numeric($this->quoteData['marginAvailable'])
            ? (float) $this->quoteData['marginAvailable'] : null;
        $cash = isset($this->quoteData['cash']) && is_numeric($this->quoteData['cash'])
            ? (float) $this->quoteData['cash'] : null;
        $marginAfter = (($isMargin || $linkedMargin) && $marginAvail !== null && $cad !== null)
            ? $marginAvail - $dir * $cad * $rate : null;
        $after = $isMargin ? $marginAfter : (($cash !== null && $notional !== null) ? $cash - $dir * $notional : null);
        $typeWord = ['MARKET' => 'Market', 'LIMIT' => 'Limit', 'STOP' => 'Stop', 'STOP_LIMIT' => 'Stop limit'][$this->type] ?? $this->type;
        $tifWord = $this->tif === 'DAY' ? 'Day' : 'GTC';
        $trailWord = $this->slTrailUnit === 'pct' ? $this->plain($trail).'%' : Orders::px($trail);

        return [
            'buy' => $buy,
            'dir' => $dir,
            'mult' => $mult,
            'qtyN' => $qtyN,
            'entry' => $entry,
            'notional' => $notional,
            'slOn' => $slOn,
            'tpOn' => $tpOn,
            'isTrail' => $isTrail,
            'trail' => $trail,
            'slPctIn' => $slPctIn,
            'slPrice' => $slPrice,
            'tpPctIn' => $tpPctIn,
            'tpPrice' => $tpPrice,
            'risk' => $risk,
            'gain' => $gain,
            'slPct' => $slPct,
            'tpPct' => $tpPct,
            'rr' => $rr,
            'cad' => $cad,
            'nav' => $nav,
            'acct' => $acct,
            'isMargin' => $isMargin,
            'linkedMargin' => $linkedMargin,
            'marginAfter' => $marginAfter,
            'after' => $after,
            'typeWord' => $typeWord,
            'tifWord' => $tifWord,
            'trailWord' => $trailWord,
            'q' => $q,
        ];
    }

    /** Alias used by bracket UI. */
    public function bracketVals(): array
    {
        return $this->ticketVals();
    }

    private function navCad(): float
    {
        // Lightweight: sum account NLVs from desktop accounts when present.
        $path = OrderStore::dbPath();
        if (! is_file($path)) {
            return 0.0;
        }
        try {
            $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $sum = 0.0;
            foreach ($pdo->query('SELECT net_liquidation_value, currency, status FROM accounts') as $a) {
                if (strtolower((string) ($a['status'] ?? '')) === 'closed') {
                    continue;
                }
                $nlv = $a['net_liquidation_value'] ?? null;
                if (! is_numeric($nlv)) {
                    continue;
                }
                $ccy = strtoupper((string) ($a['currency'] ?? 'CAD'));
                $sum += $ccy === 'USD' ? ((float) $nlv * (\App\Wealthsimple\TicketQuote::fxUsdCad() ?? 1.0)) : (float) $nlv;
            }

            return $sum;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function seedBracketsFromEntry(): void
    {
        $v = $this->ticketVals();
        if ($this->slKind === 'trail') {
            if ($this->slTrail === '' && $v['trail'] !== null) {
                $this->slTrail = $this->slTrailUnit === 'pct' ? $this->plain((float) $v['trail']) : (string) $v['trail'];
            }
        } elseif ($this->slPriceUnit === 'pct') {
            if ($this->slPct === '') {
                $this->slPct = $this->plain((float) $v['slPctIn']);
            }
        } elseif ($this->slPrice === '' && $v['slPrice'] !== null) {
            $this->slPrice = (string) $v['slPrice'];
        }
        if ($this->tpUnit === 'pct') {
            if ($this->tpPct === '') {
                $this->tpPct = $this->plain((float) $v['tpPctIn']);
            }
        } elseif ($this->tpPrice === '' && $v['tpPrice'] !== null) {
            $this->tpPrice = (string) $v['tpPrice'];
        }
    }

    public function ticketBracketPayload(): array
    {
        if (strtoupper($this->side) !== 'BUY') {
            return ['stopLoss' => null, 'takeProfit' => null];
        }
        $v = $this->ticketVals();
        $sl = null;
        if ($this->slOn) {
            $sl = [
                'kind' => $this->slKind === 'trail' ? 'trail' : 'stop',
                'price' => $v['slPrice'],
                'trail' => $v['trail'],
                'trailUnit' => $this->slTrailUnit === 'amt' ? 'amt' : 'pct',
            ];
        }
        $tp = null;
        if ($this->tpOn) {
            $tp = ['price' => $v['tpPrice']];
        }

        return ['stopLoss' => $sl, 'takeProfit' => $tp];
    }

    public function goReview(): void
    {
        $this->lastError = '';
        $qty = $this->num($this->qty);
        if (! ($qty > 0)) {
            $this->qty = '1';
            $this->syncAmountFromQty();
        }
        $this->step = 'review';
        $this->saveDraft();
    }

    public function goBack(): void
    {
        $this->step = 'form';
        $this->lastError = '';
    }

    public function closeKeepDraft(): void
    {
        $this->saveDraft();
        $this->dispatch('close-ticket-after-send');
    }

    public function submit(): void
    {
        if ($this->step !== 'review') {
            $this->goReview();

            return;
        }
        $this->lastError = '';
        $this->busy = true;
        $brackets = $this->ticketBracketPayload();
        $v = $this->ticketVals();
        $result = OrderService::placeOrder([
            'side' => $this->side,
            'type' => $this->type,
            'tif' => $this->type === 'MARKET' ? 'DAY' : $this->tif,
            'quantity' => $this->qty,
            'limitPrice' => $this->limit,
            'stopPrice' => $this->stop,
            'symbol' => $this->symbol,
            'securityId' => $this->securityId,
            'accountId' => $this->accountId,
            'stopLoss' => $brackets['stopLoss'],
            'takeProfit' => $brackets['takeProfit'],
        ]);
        $this->busy = false;
        if (! empty($result['ok']) && ($result['status'] ?? '') === 'dry') {
            $detail = ($v['buy'] ? 'Buy ' : 'Sell ').Orders::qty((float) $v['qtyN']).' '.$this->symbol
                .' at '.($this->type === 'MARKET' ? 'market' : Orders::px($v['entry']).' '.strtolower((string) $v['typeWord']));
            $notice = OrderService::dryNoticeWithBrackets($detail, [
                'stopLoss' => $brackets['stopLoss'],
                'takeProfit' => $brackets['takeProfit'],
            ]);
            $this->dropDraft();
            $this->dispatch('ticket-dry-notice', message: $notice);
            $this->dispatch('orders-refresh');
            $this->dispatch('close-ticket-after-send');

            return;
        }
        if (! empty($result['ok'])) {
            $this->dropDraft();
            $this->dispatch('ticket-dry-notice', message: (string) ($result['notice'] ?? 'Order sent'));
            $this->dispatch('orders-refresh');
            $this->dispatch('close-ticket-after-send');

            return;
        }
        $this->lastError = (string) ($result['error'] ?? 'Order failed.');
    }

    private function draftKeys(): array
    {
        return ['type', 'tif', 'qty', 'limit', 'stop', 'slOn', 'slKind', 'slPrice', 'slPct', 'slPriceUnit', 'slTrail', 'slTrailUnit', 'tpOn', 'tpPrice', 'tpPct', 'tpUnit', 'accountId', 'amount'];
    }

    private function saveDraft(): void
    {
        $d = ['symbol' => $this->symbol, 'side' => strtoupper($this->side), 'exchange' => $this->exchange];
        foreach ($this->draftKeys() as $k) {
            $d[$k] = $this->{$k};
        }
        try {
            session(['bh.ticketDraft' => $d]);
        } catch (\Throwable) {
        }
    }

    public function discardAndClose(): void
    {
        $this->dropDraft();
        $this->dispatch('close-ticket-after-send');
    }

    private function dropDraft(): void
    {
        try {
            session()->forget('bh.ticketDraft');
        } catch (\Throwable) {
        }
    }

    private function restoreDraft(): void
    {
        try {
            $d = session('bh.ticketDraft');
        } catch (\Throwable) {
            $d = null;
        }
        if (! is_array($d) || strtoupper((string) ($d['symbol'] ?? '')) !== strtoupper($this->symbol)
            || strtoupper((string) ($d['side'] ?? '')) !== strtoupper($this->side)) {
            return;
        }
        foreach ($this->draftKeys() as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) {
                $this->{$k} = $d[$k];
            }
        }
    }
};
?>

<div id="tkWrap" @if (! $open) style="display:none" @endif wire:key="bh-ticket-wrap"
    @if ($open && $step === 'form') wire:poll.5s="pollQuote" @endif>
    <div class="tk-scrim" wire:click="closeKeepDraft" aria-hidden="true"></div>
    <div class="tk" role="dialog" aria-modal="true" aria-label="{{ $step === 'review' ? 'Review order' : 'New order' }}">
        <div class="tk-hd">
            <span class="tk-title">{{ $step === 'review' ? 'Review order' : 'New order' }}</span>
            <button type="button" class="tk-x" aria-label="Close" wire:click="closeKeepDraft">
                <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66 194.34a8 8 0 0 1-11.32 11.32L128 139.31l-66.34 66.35a8 8 0 0 1-11.32-11.32L116.69 128 50.34 61.66a8 8 0 0 1 11.32-11.32L128 116.69l66.34-66.35a8 8 0 0 1 11.32 11.32L139.31 128Z"/></svg>
            </button>
        </div>

        @php $v = $this->ticketVals(); $ql = $this->quoteLine(); @endphp

        @if ($step === 'review')
            <div class="tk-body" data-keep-scroll="ticket-review">
                <div class="tk-review-head">
                    <div class="sym"><span style="color:{{ $v['buy'] ? 'var(--bh-pos)' : 'var(--bh-neg)' }}">{{ $v['buy'] ? 'Buy' : 'Sell' }}</span> {{ Orders::qty((float) $v['qtyN']) }} {{ $ql['name'] !== '' || $symbol !== '' ? ($v['q']['symbol'] ?? $symbol) : '—' }}</div>
                    <div class="sub">{{ $v['typeWord'] }}{{ $type === 'MARKET' ? '' : ' '.Orders::px($v['entry']).' · '.$v['tifWord'] }} · {{ $v['acct']['name'] ?? '' }}</div>
                </div>
                @if ($v['buy'])
                    <div style="display:flex;flex-direction:column;gap:10px;padding-top:14px">
                        <div class="tk-row"><span class="l">Stop loss</span><span class="r num">{{ ! $v['slOn'] ? 'None' : ($v['isTrail'] ? 'Trails '.$v['trailWord'].' under the high · starts at '.Orders::px($v['slPrice']) : 'Market at '.Orders::px($v['slPrice'])) }}</span></div>
                        <div class="tk-row"><span class="l">Take profit</span><span class="r num">{{ $v['tpOn'] ? 'Limit at '.Orders::px($v['tpPrice']) : 'None' }}</span></div>
                    </div>
                @endif
                <div class="tk-review-block">
                    @if ($v['buy'])
                        <div class="tk-row"><span class="l">At risk</span><span class="r num" style="color:var(--bh-neg);font-weight:500">{{ $v['slOn'] && $v['risk'] !== null ? Money::signedCad(-$v['risk'], 2).' ('.Money::signedPct($v['slPct']).')' : '—' }}</span></div>
                        <div class="tk-row"><span class="l">Target</span><span class="r num" style="color:var(--bh-pos);font-weight:500">{{ $v['tpOn'] && $v['gain'] !== null ? Money::signedCad($v['gain'], 2).' ('.Money::signedPct($v['tpPct']).')' : '—' }}</span></div>
                        <div class="tk-row"><span class="l">Risk / reward</span><span class="r num">{{ $v['rr'] === null ? '—' : '1:'.$this->plain((float) round($v['rr'], 1)) }}</span></div>
                    @endif
                    <div class="tk-row"><span class="l">Position size</span><span class="r num">{{ ($v['cad'] !== null && $v['nav'] > 0) ? number_format($v['cad'] / $v['nav'] * 100, 1).'% of net asset value' : '—' }}</span></div>
                    <div class="tk-row"><span class="l">{{ $v['isMargin'] ? 'Available margin after' : 'Cash after' }}</span><span class="r num" @if ($v['after'] !== null && $v['after'] < 0) style="color:var(--bh-neg)" @endif>{{ $v['after'] === null ? '—' : Money::formatCad($v['after'], 0) }}</span></div>
                    @if ($v['linkedMargin'])
                        <div class="tk-row"><span class="l">Available margin after</span><span class="r num" @if ($v['marginAfter'] !== null && $v['marginAfter'] < 0) style="color:var(--bh-neg)" @endif>{{ $v['marginAfter'] === null ? '—' : Money::formatCad($v['marginAfter'], 0) }}</span></div>
                    @endif
                </div>
                <div class="tk-review-cost">
                    <span class="lbl">{{ $v['buy'] ? 'Estimated cost' : 'Estimated proceeds' }}</span>
                    <span class="amt num">{{ $type === 'MARKET' ? '≈ ' : '' }}{{ $v['notional'] === null ? '—' : Money::formatCad($v['notional'], abs($v['notional'] - round($v['notional'])) < 0.005 ? 0 : 2) }}</span>
                </div>
                @if ($lastError !== '')
                    <div class="tk-callout" style="color:var(--bh-neg)">{{ $lastError }}</div>
                @elseif (! OrderService::ordersLive())
                    <div class="tk-callout">Orders are off — Submit records the ticket locally; nothing is sent to Wealthsimple.</div>
                @endif
            </div>
            <div class="tk-ft">
                <button type="button" class="tk-cancel" wire:click="goBack">Back</button>
                <button type="button" class="tk-go" wire:click="submit" wire:loading.attr="disabled" @disabled($busy)>{{ $busy ? 'Submitting…' : 'Submit' }}</button>
            </div>
        @else
            <div class="tk-body" data-keep-scroll="ticket-form">
                <div class="tk-quote">
                    <div class="tk-sym-row">
                        <div style="min-width:0">
                            <div class="tk-sym">{{ $symbol !== '' ? $symbol : '—' }}</div>
                            @if ($ql['name'] !== '' || $exchange !== '')
                                <div style="font-size:12px;color:var(--bh-ink55);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $ql['name'] }}{{ $ql['name'] !== '' && ($v['q']['exchange'] ?? $exchange) !== '' ? ' · ' : '' }}{{ $v['q']['exchange'] ?? $exchange }}</div>
                            @endif
                        </div>
                        @if ($ql['gap'] !== '')
                            <div class="tk-px-gap">{{ $ql['gap'] }}</div>
                        @else
                            <div class="tk-px">
                                <span class="tk-last num">{{ $ql['price'] }}</span>
                                @if ($ql['change'] !== '')
                                    <span class="tk-chg num {{ $ql['tone'] }}">{{ $ql['change'] }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="tk-bbo num">
                        <div><span class="tk-bbo-l">Bid</span> {{ $ql['bid'] !== '' ? $ql['bid'].$ql['bidSize'] : '—' }}</div>
                        <div><span class="tk-bbo-l">Mid</span> {{ $ql['mid'] !== '' ? $ql['mid'] : '—' }}</div>
                        <div><span class="tk-bbo-l">Ask</span> {{ $ql['ask'] !== '' ? $ql['ask'].$ql['askSize'] : '—' }}</div>
                    </div>
                    @if (! empty($quoteData['gaps']))
                        <div class="tk-gap">{{ implode(' · ', $quoteData['gaps']) }}</div>
                    @endif
                    <div class="tk-seg">
                        <button type="button" class="tk-segopt {{ $side === 'BUY' ? 'on buy' : '' }}" wire:click="$parent.setTicketSide('BUY')">Buy</button>
                        <button type="button" class="tk-segopt {{ $side === 'SELL' ? 'on sell' : '' }}" wire:click="$parent.setTicketSide('SELL')">Sell</button>
                    </div>
                </div>

                <div class="tk-grid">
                    <label class="tk-f tk-f-span">
                        <span class="tk-l">Account</span>
                        <select class="tk-in" wire:model.live="accountId">
                            @forelse ($this->accounts() as $a)
                                <option value="{{ $a['id'] }}">{{ $a['name'] }}</option>
                            @empty
                                <option value="">No tradable account</option>
                            @endforelse
                        </select>
                    </label>
                    <label class="tk-f">
                        <span class="tk-l">Shares</span>
                        <span class="tk-wrap">
                            <input class="tk-in num has-max" type="text" inputmode="decimal" wire:model.live.debounce.150ms="qty" autocomplete="off" />
                            <button type="button" class="tk-max {{ $this->maxQty() === null ? 'off' : '' }}" wire:click="applyMax">Max</button>
                        </span>
                    </label>
                    <label class="tk-f">
                        <span class="tk-l">Order type</span>
                        <select class="tk-in" wire:model.live="type">
                            @php $types = $quoteData['orderTypes'] ?? ['MARKET','LIMIT','STOP','STOP_LIMIT']; @endphp
                            @foreach (['MARKET' => 'Market', 'LIMIT' => 'Limit', 'STOP' => 'Stop', 'STOP_LIMIT' => 'Stop limit'] as $k => $lab)
                                @if (in_array($k, $types, true))
                                    <option value="{{ $k }}">{{ $lab }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    @if (in_array($type, ['LIMIT', 'STOP_LIMIT'], true))
                        <label class="tk-f">
                            <span class="tk-l">Limit price</span>
                            <input class="tk-in num" type="text" inputmode="decimal" wire:model.live.debounce.150ms="limit" autocomplete="off" />
                        </label>
                    @endif
                    @if (in_array($type, ['STOP', 'STOP_LIMIT'], true))
                        <label class="tk-f">
                            <span class="tk-l">Stop price</span>
                            <input class="tk-in num" type="text" inputmode="decimal" wire:model.live.debounce.150ms="stop" autocomplete="off" />
                        </label>
                    @endif
                    @if ($type !== 'MARKET')
                        <label class="tk-f">
                            <span class="tk-l">Time in force</span>
                            <select class="tk-in" wire:model="tif">
                                <option value="DAY">Day</option>
                                <option value="UNTIL_CANCEL">GTC</option>
                            </select>
                        </label>
                    @endif
                    <label class="tk-f">
                        <span class="tk-l">Amount</span>
                        <span class="tk-wrap">
                            <input class="tk-in num has-max" type="text" inputmode="decimal" wire:model.live.debounce.150ms="amount" autocomplete="off" />
                            <button type="button" class="tk-max {{ $this->maxQty() === null ? 'off' : '' }}" wire:click="applyMax">Max</button>
                        </span>
                    </label>
                </div>

                @if (strtoupper($side) === 'BUY')
                    @php $bv = $v; @endphp
                    <div class="tk-rule"></div>
                    <div class="tk-sec">
                        <div class="tk-sech">
                            Stop loss
                            <button type="button" class="tk-rowbtn {{ $slOn ? 'sell' : 'buy' }}" style="margin-left:auto" aria-label="{{ $slOn ? 'Remove stop loss' : 'Add stop loss' }}" wire:click="toggleSl">
                                @if ($slOn)
                                    <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66 194.34a8 8 0 0 1-11.32 11.32L128 139.31l-66.34 66.35a8 8 0 0 1-11.32-11.32L116.69 128 50.34 61.66a8 8 0 0 1 11.32-11.32L128 116.69l66.34-66.35a8 8 0 0 1 11.32 11.32L139.31 128Z"/></svg>
                                @else
                                    <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224 128a8 8 0 0 1-8 8h-80v80a8 8 0 0 1-16 0v-80H40a8 8 0 0 1 0-16h80V40a8 8 0 0 1 16 0v80h80a8 8 0 0 1 8 8Z"/></svg>
                                @endif
                            </button>
                        </div>
                        @if ($slOn)
                            <div class="tk-grid">
                                <label class="tk-f">
                                    <span class="tk-l">Type</span>
                                    <select class="tk-in" wire:model.live="slKind">
                                        <option value="stop">Stop</option>
                                        <option value="trail">Trailing stop</option>
                                    </select>
                                </label>
                                <label class="tk-f">
                                    <span class="tk-l">{{ $slKind === 'trail' ? 'Trail' : 'Stop' }}</span>
                                    <span class="tk-wrap">
                                        @if ($slKind === 'trail')
                                            <input class="tk-in num has-unit" type="text" inputmode="decimal" wire:model.live.debounce.150ms="slTrail" autocomplete="off" />
                                        @elseif ($slPriceUnit === 'pct')
                                            <input class="tk-in num has-unit" type="text" inputmode="decimal" wire:model.live.debounce.150ms="slPct" autocomplete="off" />
                                        @else
                                            <input class="tk-in num has-unit" type="text" inputmode="decimal" wire:model.live.debounce.150ms="slPrice" autocomplete="off" />
                                        @endif
                                        <span class="tk-unit">
                                            <button type="button" class="tk-unitopt {{ ($slKind === 'trail' ? $slTrailUnit : $slPriceUnit) === 'amt' ? 'on' : '' }}" wire:click="setSlUnit('amt')">$</button>
                                            <button type="button" class="tk-unitopt {{ ($slKind === 'trail' ? $slTrailUnit : $slPriceUnit) === 'pct' ? 'on' : '' }}" wire:click="setSlUnit('pct')">%</button>
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <div class="tk-read">
                                <span class="l">{{ $bv['isTrail'] ? 'Starts at ' : 'Sells at ' }}{{ Orders::px(isset($bv['slPrice']) ? (float) $bv['slPrice'] : null) }}</span>
                                <span class="r num" style="color:var(--bh-neg)">{{ $bv['risk'] === null ? '—' : Money::signedCad(-$bv['risk'], 2).' ('.Money::signedPct($bv['slPct']).')' }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="tk-rule"></div>
                    <div class="tk-sec">
                        <div class="tk-sech">
                            Take profit
                            <button type="button" class="tk-rowbtn {{ $tpOn ? 'sell' : 'buy' }}" style="margin-left:auto" aria-label="{{ $tpOn ? 'Remove take profit' : 'Add take profit' }}" wire:click="toggleTp">
                                @if ($tpOn)
                                    <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66 194.34a8 8 0 0 1-11.32 11.32L128 139.31l-66.34 66.35a8 8 0 0 1-11.32-11.32L116.69 128 50.34 61.66a8 8 0 0 1 11.32-11.32L128 116.69l66.34-66.35a8 8 0 0 1 11.32 11.32L139.31 128Z"/></svg>
                                @else
                                    <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224 128a8 8 0 0 1-8 8h-80v80a8 8 0 0 1-16 0v-80H40a8 8 0 0 1 0-16h80V40a8 8 0 0 1 16 0v80h80a8 8 0 0 1 8 8Z"/></svg>
                                @endif
                            </button>
                        </div>
                        @if ($tpOn)
                            <div class="tk-grid">
                                <label class="tk-f">
                                    <span class="tk-l">Target</span>
                                    <span class="tk-wrap">
                                        @if ($tpUnit === 'pct')
                                            <input class="tk-in num has-unit" type="text" inputmode="decimal" wire:model.live.debounce.150ms="tpPct" autocomplete="off" />
                                        @else
                                            <input class="tk-in num has-unit" type="text" inputmode="decimal" wire:model.live.debounce.150ms="tpPrice" autocomplete="off" />
                                        @endif
                                        <span class="tk-unit">
                                            <button type="button" class="tk-unitopt {{ $tpUnit === 'amt' ? 'on' : '' }}" wire:click="setTpUnit('amt')">$</button>
                                            <button type="button" class="tk-unitopt {{ $tpUnit === 'pct' ? 'on' : '' }}" wire:click="setTpUnit('pct')">%</button>
                                        </span>
                                    </span>
                                </label>
                                <div></div>
                            </div>
                            <div class="tk-read">
                                <span class="l">Sells at {{ Orders::px(isset($bv['tpPrice']) ? (float) $bv['tpPrice'] : null) }}</span>
                                <span class="r num" style="color:var(--bh-pos)">{{ $bv['gain'] === null ? '—' : Money::signedCad($bv['gain'], 2).' ('.Money::signedPct($bv['tpPct']).')' }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($lastError !== '')
                    <div class="tk-callout" style="color:var(--bh-neg)">{{ $lastError }}</div>
                @elseif (! OrderService::ordersLive())
                    <div class="tk-callout">
                        Orders are off — Review records the ticket locally; nothing is sent to Wealthsimple.
                    </div>
                @endif
            </div>

            <div class="tk-ft">
                <button type="button" class="tk-cancel" wire:click="discardAndClose">Cancel</button>
                <button type="button" class="tk-go" wire:click="goReview">Review</button>
            </div>
        @endif
    </div>
</div>
