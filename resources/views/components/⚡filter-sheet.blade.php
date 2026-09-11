<?php

use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    #[Reactive]
    public array $years = [];

    #[Reactive]
    public array $accounts = [];

    #[Reactive]
    public array $exchanges = [];

    #[Reactive]
    public array $symbols = [];

    #[Reactive]
    public string $symbol = '';

    #[Reactive]
    public string $priceOp = '';

    #[Reactive]
    public string $holdOp = '';

    #[Reactive]
    public string $pnlOp = '';

    #[Reactive]
    public string $qtyOp = '';

    #[Reactive]
    public bool $filtersActive = false;

    #[Reactive]
    public string $minDate = '2000-01-01';

    public string $fieldQuery = '';

    /**
     * @return list<array{key:string,value:string,label:string}>
     */
    public function fieldMatches(): array
    {
        $q = strtoupper(trim($this->fieldQuery));
        if ($q === '') {
            return [];
        }
        $out = [];
        $push = function (string $key, string $label, array $vals) use (&$out, $q) {
            foreach ($vals as $v) {
                $v = (string) $v;
                if ($v !== '' && str_contains(strtoupper($v), $q) && count($out) < 40) {
                    $out[] = ['key' => $key, 'value' => $v, 'label' => $label];
                }
            }
        };
        // Symbols first, then account/grade/tag/side/kind/exchange/result
        $push('symbol', 'Symbol', $this->symbols);
        $push('account', 'Account', $this->accounts);
        $push('exchange', 'Exchange', $this->exchanges);
        foreach (['A', 'B', 'C', 'F'] as $g) {
            if (str_contains($g, $q) && count($out) < 40) {
                $out[] = ['key' => 'grade', 'value' => $g, 'label' => 'Grade'];
            }
        }
        foreach (['SELL', 'COVER'] as $side) {
            if (str_contains($side, $q) && count($out) < 40) {
                $out[] = ['key' => 'side', 'value' => $side, 'label' => 'Side'];
            }
        }
        foreach (['win', 'loss', 'breakeven'] as $r) {
            if (str_contains(strtoupper($r), $q) && count($out) < 40) {
                $out[] = ['key' => 'result', 'value' => $r, 'label' => 'Result'];
            }
        }

        return $out;
    }

    public function pickMatch(string $key, string $value): void
    {
        if (in_array($key, ['symbol', 'account', 'exchange', 'grade', 'tag', 'side', 'kind', 'result'], true)) {
            $this->dispatch('bh-set-filter', key: $key, value: $value);
        }
        $this->fieldQuery = '';
    }

    #[On('bh-focus-filter-search')]
    public function focusSearch(): void
    {
        $this->js('queueMicrotask(() => { const el = document.getElementById("bh-filter-search"); if (el) { el.focus(); el.select(); } })');
    }
};
?>

<flux:modal name="filters" flyout position="right" class="space-y-4" autofocus x-on:open="$nextTick(() => { const el = document.getElementById('bh-filter-search'); if (el) { el.focus(); el.select(); } })">
    {{-- Search first focusable so Flux focus trap / shortcut lands here — not the Close button. --}}
    <div class="bh-filter-search" style="display:flex;align-items:center;gap:7px;padding:5px 7px;border-radius:6px;background:color-mix(in srgb, var(--bh-ink) 6%, transparent);box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--bh-ink) 12%, transparent)">
        <input
            id="bh-filter-search" x-ref="bhFilterSearch"
            type="search"
            autofocus
            wire:model.live.debounce.150ms="fieldQuery"
            placeholder="Search symbol, account, tag…"
            aria-label="Search filters"
            autocomplete="off"
            style="flex:1;min-width:0;border:0;background:transparent;outline:none;font:400 12.5px Inter,system-ui;color:var(--bh-ink)"
        />
    </div>

    <div class="flex items-center justify-between gap-3">
        <flux:heading size="lg">Filters</flux:heading>
        <flux:modal.close>
            <flux:button variant="ghost" icon="x-mark" size="sm" aria-label="Close" tabindex="-1" />
        </flux:modal.close>
    </div>

    @php $matches = $this->fieldMatches(); @endphp
    @if ($fieldQuery !== '' && $matches !== [])
        <div class="bh-scroll" style="max-height:280px;display:flex;flex-direction:column;gap:1px">
            @foreach ($matches as $m)
                <button
                    type="button"
                    class="bh-filter-match"
                    wire:click="pickMatch({{ \Illuminate\Support\Js::from($m['key']) }}, {{ \Illuminate\Support\Js::from($m['value']) }})"
                    style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:7px 8px;border:0;border-radius:6px;background:transparent;color:var(--bh-ink);font:400 12.5px Inter,system-ui;cursor:pointer"
                >
                    <span style="font-weight:500">{{ $m['value'] }}</span>
                    <span class="bh-dim" style="margin-left:auto;font-size:11px">{{ $m['label'] }}</span>
                </button>
            @endforeach
        </div>
    @elseif ($fieldQuery !== '')
        <div class="bh-muted" style="padding:2px 4px;font-size:12px">No match — browsing fields below.</div>
    @endif

    <div class="bh-muted" style="font-size:10.5px;letter-spacing:.04em;text-transform:uppercase;padding-top:4px">Filter by</div>

    <flux:select variant="listbox" label="Closed in" placeholder="All years" clearable wire:model.live="$parent.form.closedIn">
        @forelse ($years as $y)
            <flux:select.option :value="$y">{{ $y }}</flux:select.option>
        @empty
            <flux:select.option.empty>No years</flux:select.option.empty>
        @endforelse
    </flux:select>

    <flux:date-picker
        mode="range"
        type="input"
        with-presets
        presets="today yesterday thisWeek last7Days thisMonth yearToDate allTime"
        label="Closed"
        clearable
        :min="$minDate"
        wire:model.live="$parent.form.closed"
    />

    @php
        $symbolChoices = $symbols;
        if ($symbol !== '' && ! in_array($symbol, $symbolChoices, true)) {
            $symbolChoices = array_values(array_merge([$symbol], $symbolChoices));
        }
    @endphp
    <flux:select variant="listbox" label="Symbol" placeholder="All" searchable clearable wire:model.live="$parent.form.symbol">
        @forelse ($symbolChoices as $s)
            <flux:select.option :value="$s">{{ $s }}</flux:select.option>
        @empty
            <flux:select.option.empty>No symbols</flux:select.option.empty>
        @endforelse
    </flux:select>

    <flux:select variant="listbox" label="Account" placeholder="All" clearable wire:model.live="$parent.form.account">
        @forelse ($accounts as $a)
            <flux:select.option :value="$a">{{ $a }}</flux:select.option>
        @empty
            <flux:select.option.empty>No accounts</flux:select.option.empty>
        @endforelse
    </flux:select>

    <flux:select variant="listbox" label="Exchange" placeholder="All" clearable wire:model.live="$parent.form.exchange">
        @forelse ($exchanges as $e)
            <flux:select.option :value="$e">{{ $e }}</flux:select.option>
        @empty
            <flux:select.option.empty>No exchanges</flux:select.option.empty>
        @endforelse
    </flux:select>

    <flux:select variant="listbox" label="Grade" placeholder="All" clearable wire:model.live="$parent.form.grade">
        <flux:select.option value="A">A</flux:select.option>
        <flux:select.option value="B">B</flux:select.option>
        <flux:select.option value="C">C</flux:select.option>
        <flux:select.option value="F">F</flux:select.option>
        <flux:select.option value="Ungraded">Ungraded</flux:select.option>
    </flux:select>

    <flux:input label="Tag" placeholder="Any tag" clearable wire:model.live.debounce.200ms="$parent.form.tag" />

    <flux:select variant="listbox" label="Side" placeholder="All" clearable wire:model.live="$parent.form.side">
        <flux:select.option value="SELL">SELL</flux:select.option>
        <flux:select.option value="COVER">COVER</flux:select.option>
    </flux:select>

    <flux:select variant="listbox" label="Kind" placeholder="All" clearable wire:model.live="$parent.form.kind">
        <flux:select.option value="Shares">Shares</flux:select.option>
        <flux:select.option value="Options">Options</flux:select.option>
        <flux:select.option value="Crypto">Crypto</flux:select.option>
    </flux:select>

    <flux:select variant="listbox" label="Result" placeholder="All" clearable wire:model.live="$parent.form.result">
        <flux:select.option value="win">Winners</flux:select.option>
        <flux:select.option value="loss">Losers</flux:select.option>
        <flux:select.option value="breakeven">Breakeven</flux:select.option>
    </flux:select>

    <flux:select variant="listbox" label="Price" placeholder="All" clearable wire:model.live="$parent.form.priceOp">
        <flux:select.option value="">All</flux:select.option>
        <flux:select.option value="between">Between</flux:select.option>
        <flux:select.option value="under">Under</flux:select.option>
        <flux:select.option value="equal">Equal</flux:select.option>
        <flux:select.option value="over">Over</flux:select.option>
    </flux:select>

    @if ($priceOp === 'between')
        <div class="grid grid-cols-2 gap-2">
            <flux:input type="number" step="any" wire:model.live="$parent.form.priceMin" placeholder="From" />
            <flux:input type="number" step="any" wire:model.live="$parent.form.priceMax" placeholder="To" />
        </div>
    @elseif ($priceOp !== '')
        <flux:input type="number" step="any" wire:model.live="$parent.form.priceMin" placeholder="Price" />
    @endif


    <flux:select variant="listbox" label="Hold" placeholder="All" clearable wire:model.live="$parent.form.holdOp">
        <flux:select.option value="">All</flux:select.option>
        <flux:select.option value="over">More than</flux:select.option>
        <flux:select.option value="under">Less than</flux:select.option>
    </flux:select>
    @if ($holdOp !== '')
        <flux:input type="number" step="any" wire:model.live="$parent.form.holdMin" placeholder="Days, e.g. 45" />
    @endif

    <flux:select variant="listbox" label="P&L" placeholder="All" clearable wire:model.live="$parent.form.pnlOp">
        <flux:select.option value="">All</flux:select.option>
        <flux:select.option value="over">More than</flux:select.option>
        <flux:select.option value="under">Less than</flux:select.option>
    </flux:select>
    @if ($pnlOp !== '')
        <flux:input type="number" step="any" wire:model.live="$parent.form.pnlMin" placeholder="Amount, e.g. 250" />
    @endif

    <flux:select variant="listbox" label="Qty" placeholder="All" clearable wire:model.live="$parent.form.qtyOp">
        <flux:select.option value="">All</flux:select.option>
        <flux:select.option value="over">More than</flux:select.option>
        <flux:select.option value="under">Less than</flux:select.option>
    </flux:select>
    @if ($qtyOp !== '')
        <flux:input type="number" step="any" wire:model.live="$parent.form.qtyMin" placeholder="Units, e.g. 500" />
    @endif

    @if ($filtersActive)
        <flux:button variant="ghost" class="w-full" wire:click="$parent.resetFilters()">Reset filters</flux:button>
    @endif
</flux:modal>
