@props([
    'tab',
    'col',
    'label',
    'align' => 'left',
    'activeKey',
    'activeDir',
])
@php
    $on = $activeKey === $col;
    $arrow = ($on && $activeDir === 'asc') ? '▲' : '▼';
    $cls = trim('bh-th-sort'.($align === 'right' ? ' r' : ($align === 'center' ? ' c' : '')).($on ? ' is-sorted' : ''));
@endphp
<th
    wire:click="sortBy({{ \Illuminate\Support\Js::from($tab) }}, {{ \Illuminate\Support\Js::from($col) }})"
    class="{{ $cls }}"
    {{ $attributes }}
>
    <span class="bh-th-in"@if ($align === 'right') style="flex-direction:row-reverse"@endif>
        {{ $label }}
        <span class="bh-arrow{{ $on ? ' is-on' : '' }}{{ $align === 'center' ? ' is-center' : '' }}">{{ $arrow }}</span>
    </span>
</th>
