<?php

use App\Journal\Dates;
use App\Journal\Metrics;
use App\Journal\Sides;
use App\Support\Money;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new #[Lazy] class extends Component
{
    #[Reactive]
    public array $trade = [];

    #[Reactive]
    public array $rows = [];

    #[Reactive]
    public string $sortKey = 'when';

    #[Reactive]
    public string $sortDir = 'desc';
};
?>

<flux:modal name="executions" flyout class="space-y-5 md:min-w-[32rem]" {{ $attributes }}>
    {{ $slot }}

    @if ($trade !== [])
        <div>
            <flux:heading size="lg">{{ $trade['displaySymbol'] ?? $trade['symbol'] ?? '' }}</flux:heading>
            <flux:subheading>{{ $trade['listingLine'] ?? '' }}</flux:subheading>
        </div>

        <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-zinc-500">In</dt><dd>{{ $trade['entryDate'] ?? '' }}</dd></div>
            <div><dt class="text-zinc-500">Out</dt><dd>{{ $trade['exitDate'] ?? '' }}</dd></div>
            <div><dt class="text-zinc-500">Entry</dt><dd>{{ isset($trade['entryPrice']) ? number_format((float) $trade['entryPrice'], 2, '.', ',') : '' }}</dd></div>
            <div><dt class="text-zinc-500">Exit</dt><dd>{{ isset($trade['exitPrice']) ? number_format((float) $trade['exitPrice'], 2, '.', ',') : '' }}</dd></div>
            <div>
                <dt class="text-zinc-500">P&amp;L $</dt>
                <dd class="{{ Money::pnlClass($trade['pnl'] ?? 0) }}">{{ Money::formatCad($trade['pnl'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">P&amp;L %</dt>
                <dd class="{{ Money::pnlClass($trade['pnl'] ?? 0) }}">{{ Money::formatPct(Metrics::closedPnlPct($trade)) }}</dd>
            </div>
            <div><dt class="text-zinc-500">Hold</dt><dd>{{ Money::formatHold($trade['holdDays'] ?? 0) }}</dd></div>
            <div><dt class="text-zinc-500">Currency</dt><dd>{{ $trade['currency'] ?? '' }}</dd></div>
            <div><dt class="text-zinc-500">Side</dt><dd>{{ $trade['displaySide'] ?? Sides::displaySide($trade) }}</dd></div>
        </dl>

        <flux:separator />

        <flux:heading size="lg">Executions ({{ count($rows) }})</flux:heading>
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'when')" :sorted="$sortKey === 'when'" :direction="$sortDir">When</flux:table.sortable>
                    </flux:table.column>
                    <flux:table.column>
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'side')" :sorted="$sortKey === 'side'" :direction="$sortDir">Side</flux:table.sortable>
                    </flux:table.column>
                    <flux:table.column align="end">
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'quantity')" :sorted="$sortKey === 'quantity'" :direction="$sortDir">Qty</flux:table.sortable>
                    </flux:table.column>
                    <flux:table.column>
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'currency')" :sorted="$sortKey === 'currency'" :direction="$sortDir">FX</flux:table.sortable>
                    </flux:table.column>
                    <flux:table.column align="end">
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'unitPrice')" :sorted="$sortKey === 'unitPrice'" :direction="$sortDir">Price</flux:table.sortable>
                    </flux:table.column>
                    <flux:table.column align="end">
                        <flux:table.sortable wire:click="$parent.sortBy('inner', 'netCashAmount')" :sorted="$sortKey === 'netCashAmount'" :direction="$sortDir">Amount</flux:table.sortable>
                    </flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($rows as $a)
                        <flux:table.row :key="$a['id'] ?? $loop->index">
                            <flux:table.cell>{{ Dates::activityWhen($a) }}</flux:table.cell>
                            <flux:table.cell>{{ Sides::tradeSide($a) ?: ($a['activitySubType'] ?? '') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ !empty($a['quantity']) ? number_format((float) $a['quantity'], abs($a['quantity'] - round($a['quantity'])) > 0.0001 ? 4 : 0, '.', ',') : '' }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500" style="font-variant-numeric:normal">{{ $a['currency'] ?? ($trade['currency'] ?? '') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ !empty($a['unitPrice']) ? number_format((float) $a['unitPrice'], 2, '.', ',') : '' }}</flux:table.cell>
                            <flux:table.cell align="end" class="{{ Money::pnlClass($a['netCashAmount'] ?? 0) }}">{{ Money::formatCad($a['netCashAmount'] ?? 0) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        @foreach ($trade['slices'] ?? [] as $s)
                            <flux:table.row :key="$s['id'] ?? $loop->index">
                                <flux:table.cell>{{ $s['exitDate'] ?? '' }}</flux:table.cell>
                                <flux:table.cell>{{ Sides::displaySide($s) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format((float) ($s['quantity'] ?? 0), 0, '.', ',') }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500" style="font-variant-numeric:normal">{{ $s['currency'] ?? ($trade['currency'] ?? '') }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format((float) ($s['exitPrice'] ?? 0), 2, '.', ',') }}</flux:table.cell>
                                <flux:table.cell align="end" class="{{ Money::pnlClass($s['pnl'] ?? 0) }}">{{ Money::formatCad($s['pnl'] ?? 0) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @else
        <flux:heading size="lg">Executions</flux:heading>
        <flux:subheading>Select a closed trade.</flux:subheading>
    @endif
</flux:modal>
