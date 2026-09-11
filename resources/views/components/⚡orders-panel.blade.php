<?php

use App\Journal\Orders;
use App\Wealthsimple\BracketEngine;
use App\Wealthsimple\OrderService;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    #[Reactive]
    public bool $open = false;

    #[Reactive]
    public string $tab = 'pending';

    /** Account filter scope from parent (empty = all). */
    #[Reactive]
    public string $account = '';

    public string $editingId = '';

    public string $editQty = '';

    public string $editLimit = '';

    public string $editingBracketId = '';

    public string $editSl = '';

    public string $editTp = '';

    public int $refreshTick = 0;

    /**
     * @return array{ok:bool,orders:list<array>,brackets:list<array>,live:bool,source:string,error?:string}
     */
    public function payload(): array
    {
        // touch refreshTick so Livewire re-renders after place/cancel/modify
        $this->refreshTick;
        OrderService::kickOrdersRefresh();
        if ($this->open) {
            BracketEngine::tick();
        }

        return Orders::payload();
    }

    /** @return list<array{kind:string,at:string,order?:array,bracket?:array}> */
    public function cards(): array
    {
        $scope = $this->account !== '' ? [$this->account] : [];

        return Orders::cardsForTab($this->tab, $this->payload(), $scope);
    }

    public function scopeLabel(): string
    {
        return $this->account !== '' ? $this->account : 'All Accounts';
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, Orders::TABS, true)) {
            $this->dispatch('orders-tab', tab: $tab);
        }
    }

    #[On('orders-refresh')]
    public function refreshOrders(): void
    {
        $this->refreshTick++;
    }

    public function cancelOrder(string $id): void
    {
        $result = OrderService::cancelOrder($id);
        if (! empty($result['dry']) || ($result['status'] ?? '') === 'dry' || ! empty($result['notice'])) {
            $this->dispatch('orders-dry-notice', message: (string) ($result['notice'] ?? 'Cancel not sent (orders are off)'));
            $this->refreshTick++;

            return;
        }
        if (! empty($result['ok'])) {
            $this->dispatch('orders-dry-notice', message: 'Cancel requested');
            $this->refreshTick++;

            return;
        }
        $this->dispatch('orders-dry-notice', message: (string) ($result['error'] ?? 'Cancel failed'));
        $this->refreshTick++;
    }

    public function startEdit(string $id): void
    {
        $payload = $this->payload();
        $order = null;
        foreach ($payload['orders'] ?? [] as $o) {
            if (($o['id'] ?? '') === $id) {
                $order = $o;
                break;
            }
        }
        if (! $order) {
            $this->dispatch('orders-dry-notice', message: 'No such order.');

            return;
        }
        if (($order['type'] ?? '') === 'STOP') {
            $this->dispatch('orders-dry-notice', message: 'A stop order cannot be changed; cancel it and place another.');

            return;
        }
        $this->editingId = $id;
        $this->editQty = (string) ($order['quantity'] ?? '');
        $this->editLimit = isset($order['limitPrice']) && $order['limitPrice'] !== null && $order['limitPrice'] !== ''
            ? (string) $order['limitPrice']
            : '';
    }

    public function cancelEdit(): void
    {
        $this->editingId = '';
        $this->editQty = '';
        $this->editLimit = '';
    }


    public function cancelBracket(string $id): void
    {
        $result = OrderService::cancelBracket($id);
        if (! empty($result['ok'])) {
            $this->dispatch('orders-dry-notice', message: (string) ($result['notice'] ?? 'Bracket cancelled'));
            $this->editingBracketId = '';
            $this->refreshTick++;
            BracketEngine::tick([]);

            return;
        }
        $this->dispatch('orders-dry-notice', message: (string) ($result['error'] ?? 'Bracket cancel failed'));
        $this->refreshTick++;
    }

    public function startBracketEdit(string $id): void
    {
        $payload = $this->payload();
        $b = null;
        foreach ($payload['brackets'] ?? [] as $row) {
            if (($row['id'] ?? '') === $id) {
                $b = $row;
                break;
            }
        }
        if (! $b) {
            $this->dispatch('orders-dry-notice', message: 'No such bracket.');

            return;
        }
        $this->editingId = '';
        $this->editingBracketId = $id;
        // Desktop bracketEditorHtml: trail edits the trail distance; stop edits the price.
        if (($b['slKind'] ?? '') === 'trail') {
            $this->editSl = isset($b['slTrail']) && $b['slTrail'] !== null && $b['slTrail'] !== ''
                ? (string) $b['slTrail']
                : '';
        } else {
            $this->editSl = isset($b['slPrice']) && $b['slPrice'] !== null && $b['slPrice'] !== ''
                ? (string) $b['slPrice']
                : '';
        }
        $this->editTp = isset($b['tpPrice']) && $b['tpPrice'] !== null && $b['tpPrice'] !== '' ? (string) $b['tpPrice'] : '';
    }

    public function cancelBracketEdit(): void
    {
        $this->editingBracketId = '';
        $this->editSl = '';
        $this->editTp = '';
    }

    public function submitBracketEdit(): void
    {
        if ($this->editingBracketId === '') {
            return;
        }
        $b = null;
        foreach ($this->payload()['brackets'] ?? [] as $row) {
            if (($row['id'] ?? '') === $this->editingBracketId) {
                $b = $row;
                break;
            }
        }
        $slPrice = null;
        $slTrail = null;
        $tpPrice = $this->editTp !== '' ? (float) $this->editTp : null;
        if ($this->editSl !== '') {
            if (($b['slKind'] ?? '') === 'trail') {
                $slTrail = (float) $this->editSl;
            } else {
                $slPrice = (float) $this->editSl;
            }
        }
        $result = BracketEngine::modifyBracket(
            $this->editingBracketId,
            $slPrice,
            $tpPrice,
            null,
            $slTrail,
        );
        if (! empty($result['ok'])) {
            $this->dispatch('orders-dry-notice', message: (string) ($result['notice'] ?? 'Bracket updated'));
            $this->cancelBracketEdit();
            $this->refreshTick++;

            return;
        }
        $this->dispatch('orders-dry-notice', message: (string) ($result['error'] ?? 'Bracket edit failed'));
        $this->refreshTick++;
    }

    public function removeBracketLeg(string $leg): void
    {
        if ($this->editingBracketId === '') {
            return;
        }
        $result = BracketEngine::adjustBracket($this->editingBracketId, $leg, null, null, true);
        if (! empty($result['ok'])) {
            $this->dispatch('orders-dry-notice', message: (string) ($result['notice'] ?? 'Leg removed'));
            $this->cancelBracketEdit();
            $this->refreshTick++;
            BracketEngine::tick([]);

            return;
        }
        $this->dispatch('orders-dry-notice', message: (string) ($result['error'] ?? 'Remove failed'));
        $this->refreshTick++;
    }

    public function submitEdit(): void
    {
        if ($this->editingId === '') {
            return;
        }
        $result = OrderService::modifyOrder(
            $this->editingId,
            $this->editQty !== '' ? $this->editQty : null,
            $this->editLimit !== '' ? $this->editLimit : null,
        );
        if (! empty($result['dry']) || ($result['status'] ?? '') === 'dry' || ! empty($result['notice'])) {
            $this->dispatch('orders-dry-notice', message: (string) ($result['notice'] ?? 'Edit not sent (orders are off)'));
            $this->cancelEdit();
            $this->refreshTick++;

            return;
        }
        if (! empty($result['unchanged'])) {
            $this->dispatch('orders-dry-notice', message: 'Nothing to change');
            $this->cancelEdit();

            return;
        }
        if (! empty($result['ok'])) {
            $this->dispatch('orders-dry-notice', message: 'Order updated');
            $this->cancelEdit();
            $this->refreshTick++;

            return;
        }
        $this->dispatch('orders-dry-notice', message: (string) ($result['error'] ?? 'Change failed'));
        $this->refreshTick++;
    }
};
?>

<div id="odWrap" @if (! $open) style="display:none" @endif wire:key="bh-orders-wrap" @if ($open) wire:poll.15s @endif>
    <div class="tk-scrim" wire:click="$parent.closeOrders()" aria-hidden="true"></div>
    <div class="tk" role="dialog" aria-modal="true" aria-label="Orders">
        <div class="tk-hd">
            <span class="tk-title">Orders</span>
            <button type="button" class="tk-x" aria-label="Close" wire:click="$parent.closeOrders()">
                <svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M205.66 194.34a8 8 0 0 1-11.32 11.32L128 139.31l-66.34 66.35a8 8 0 0 1-11.32-11.32L116.69 128 50.34 61.66a8 8 0 0 1 11.32-11.32L128 116.69l66.34-66.35a8 8 0 0 1 11.32 11.32L139.31 128Z"/></svg>
            </button>
        </div>

        <div class="tk-body">
            <div class="od-segs" role="tablist" aria-label="Orders tabs">
                @foreach (['pending' => 'Pending', 'filled' => 'Filled', 'cancelled' => 'Cancelled'] as $id => $label)
                    <button
                        type="button"
                        role="tab"
                        class="od-seg {{ $tab === $id ? 'on' : '' }}"
                        aria-selected="{{ $tab === $id ? 'true' : 'false' }}"
                        wire:click="$parent.setOrdersTab('{{ $id }}')"
                    >{{ $label }}</button>
                @endforeach
            </div>

            @php
                $payload = $this->payload();
                $cards = $this->cards();
                $head = match ($tab) {
                    'filled' => 'Filled orders',
                    'cancelled' => 'Cancelled and rejected',
                    default => 'Pending orders',
                };
                $empty = match ($tab) {
                    'filled' => 'Nothing filled yet.',
                    'cancelled' => 'Nothing cancelled or rejected.',
                    default => 'No pending orders.',
                };
                $live = (bool) ($payload['live'] ?? false);
            @endphp

            <div class="od-list">
                <div class="od-head">
                    <span class="od-group">{{ $head }}</span>
                    <span class="od-scope">{{ $this->scopeLabel() }}</span>
                </div>

                @if (! ($payload['ok'] ?? false) && ($payload['error'] ?? '') !== '')
                    <div class="od-empty" style="color:var(--bh-neg)">{{ $payload['error'] }}</div>
                @elseif ($cards === [])
                    <div class="od-empty">{{ $empty }}</div>
                @else
                    @foreach ($cards as $card)
                        @if (($card['kind'] ?? '') === 'bracket')
                            @php
                                $b = $card['bracket'];
                                $entry = $card['order'] ?? null;
                                $legs = \App\Journal\Orders::bracketLegs($b, $entry);
                                $titleSym = (($entry['exchange'] ?? '') !== '' ? $entry['exchange'].': ' : '').($b['symbol'] ?? '');
                                $value = '';
                                if ($entry && ! empty($entry['avgFill'])) {
                                    $value = \App\Support\Money::formatCad(
                                        (float) ($b['quantity'] ?? 0) * (float) $entry['avgFill'] * \App\Journal\Orders::multiplier($entry),
                                        2
                                    );
                                }
                            @endphp
                            <div class="od-card">
                                <div class="od-row1">
                                    <span class="od-title"><span class="od-accent">Bracket</span> {{ $titleSym }}</span>
                                    <span class="od-value num">{{ $value }}</span>
                                </div>
                                @foreach ($legs as $leg)
                                    <div class="od-leg">
                                        <span class="od-leg-label {{ $leg['tone'] }}">{{ $leg['label'] }}</span>
                                        <span class="od-leg-value num">{{ $leg['line'] }}</span>
                                        @if ($leg['note'] !== '')
                                            <span class="od-leg-state">{{ $leg['note'] }}</span>
                                        @endif
                                        <span class="od-leg-amt num {{ $leg['tone'] }}">{{ $leg['amount'] }}</span>
                                    </div>
                                @endforeach
                                @if ($editingBracketId === ($b['id'] ?? ''))
                                    @php
                                        $isTrail = ($b['slKind'] ?? '') === 'trail';
                                        $slLabel = $isTrail
                                            ? (($b['slTrailUnit'] ?? 'pct') === 'amt' ? 'Trail' : 'Trail %')
                                            : 'Stop price';
                                    @endphp
                                    <div class="od-edit">
                                        <div class="tk-grid">
                                            @if (($b['slKind'] ?? '') !== '')
                                                <div class="tk-f">
                                                    <span class="tk-l">{{ $slLabel }}</span>
                                                    <input class="tk-in num" wire:model="editSl" inputmode="decimal" autocomplete="off" />
                                                    <button type="button" class="od-link neg od-remove" wire:click="removeBracketLeg('sl')">Remove stop loss</button>
                                                </div>
                                            @else
                                                <div></div>
                                            @endif
                                            @if (isset($b['tpPrice']) && $b['tpPrice'] !== null && $b['tpPrice'] !== '')
                                                <div class="tk-f">
                                                    <span class="tk-l">Limit price</span>
                                                    <input class="tk-in num" wire:model="editTp" inputmode="decimal" autocomplete="off" />
                                                    <button type="button" class="od-link neg od-remove" wire:click="removeBracketLeg('tp')">Remove take profit</button>
                                                </div>
                                            @else
                                                <div></div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                                <div class="od-foot">
                                    <span class="od-when">{{ \App\Journal\Orders::whenWord($b['armedAt'] ?: ($b['createdAt'] ?? '')) }}</span>
                                    @if ($editingBracketId === ($b['id'] ?? ''))
                                        <span class="od-foot-acts">
                                            <button type="button" class="od-link" wire:click="submitBracketEdit">Save</button>
                                            <button type="button" class="od-link" wire:click="cancelBracketEdit">Cancel</button>
                                        </span>
                                    @else
                                        <span class="od-foot-acts">
                                            <button type="button" class="od-link" wire:click="startBracketEdit('{{ $b['id'] }}')">Edit</button>
                                            <button type="button" class="od-link neg" wire:click="cancelBracket('{{ $b['id'] }}')">Cancel</button>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @else
                            @php
                                $o = $card['order'];
                                $side = strtoupper($o['side'] ?? 'BUY');
                                $title = (($o['exchange'] ?? '') !== '' ? $o['exchange'].': ' : '').($o['symbol'] ?? '');
                                $fill = \App\Journal\Orders::fillLine($o);
                                $pill = $tab === 'cancelled' ? \App\Journal\Orders::pill($o) : null;
                                $waiting = null;
                                if (isset(\App\Journal\Orders::LIVE[$o['status'] ?? ''])) {
                                    foreach ($payload['brackets'] ?? [] as $br) {
                                        if (($br['orderId'] ?? '') === ($o['id'] ?? '') && ($br['status'] ?? '') === 'waiting') {
                                            $waiting = $br;
                                            break;
                                        }
                                    }
                                }
                                $isEditing = $editingId === ($o['id'] ?? '');
                            @endphp
                            <div class="od-card">
                                <div class="od-row1">
                                    <span class="od-title">
                                        <span class="{{ $side === 'SELL' ? 'neg' : 'pos' }}">{{ $side === 'SELL' ? 'Sell' : 'Buy' }}</span>
                                        {{ $title }}
                                    </span>
                                    <span class="od-value num">{{ \App\Journal\Orders::value($o) }}</span>
                                </div>
                                <div class="od-row2">
                                    <span class="od-line">{{ \App\Journal\Orders::detailLine($o) }}</span>
                                </div>
                                @if ($fill !== '')
                                    <div class="od-fill">{{ $fill }}</div>
                                @endif
                                @if ($waiting)
                                    @foreach (\App\Journal\Orders::bracketLegs($waiting, $o) as $leg)
                                        <div class="od-leg">
                                            <span class="od-leg-label {{ $leg['tone'] }}">{{ $leg['label'] }}</span>
                                            <span class="od-leg-value num">{{ $leg['line'] }}</span>
                                            <span class="od-leg-amt num {{ $leg['tone'] }}">{{ $leg['amount'] }}</span>
                                        </div>
                                    @endforeach
                                @endif
                                @if ($isEditing)
                                    <div class="od-edit od-edit-order">
                                        <label class="tk-f" style="flex:1;margin:0">
                                            <span class="tk-l">Shares</span>
                                            <input class="tk-in num" type="text" inputmode="decimal" wire:model="editQty" />
                                        </label>
                                        @if (in_array($o['type'] ?? '', ['LIMIT','STOP_LIMIT'], true))
                                            <label class="tk-f" style="flex:1;margin:0">
                                                <span class="tk-l">Limit</span>
                                                <input class="tk-in num" type="text" inputmode="decimal" wire:model="editLimit" />
                                            </label>
                                        @endif
                                    </div>
                                @endif
                                <div class="od-foot">
                                    <span class="od-when">{{ \App\Journal\Orders::whenWord($o['createdAt'] ?? '') }}</span>
                                    @if ($isEditing)
                                        <span class="od-foot-acts">
                                            <button type="button" class="od-link" wire:click="submitEdit">Save</button>
                                            <button type="button" class="od-link" wire:click="cancelEdit">Back</button>
                                        </span>
                                    @elseif ($pill)
                                        <span class="od-state {{ $pill[1] === 'neg' ? 'neg' : '' }}">{{ $pill[0] }}</span>
                                    @elseif ($tab === 'pending')
                                        <span class="od-foot-acts">
                                            <button type="button" class="od-link" wire:click="startEdit('{{ $o['id'] }}')">Edit</button>
                                            <button type="button" class="od-link neg" wire:click="cancelOrder('{{ $o['id'] }}')">Cancel</button>
                                        </span>
                                    @elseif (($o['status'] ?? '') === 'dry')
                                        <span class="od-state od-dry">Not sent</span>
                                    @else
                                        <span class="od-state">{{ \App\Journal\Orders::pill($o)[0] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                @endif

                <div class="od-note">
                    Source: {{ $payload['source'] ?? '—' }}
                    · {{ $live ? 'Live orders on' : 'Orders are off — place/cancel/modify not sent' }}
                </div>
            </div>
        </div>
    </div>
</div>
