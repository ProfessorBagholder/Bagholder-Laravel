@props([
    'label' => '',
    'value' => '',
    'sub' => '',
    'cls' => '',
])

<div {{ $attributes->class('bh-kpi') }}>
    <div class="bh-kpi-lbl">{{ $label }}</div>
    <div class="bh-kpi-val {{ $cls }}">{{ $value }}</div>
    @if ($sub !== '')
        <div class="bh-kpi-sub">{{ $sub }}</div>
    @endif
    {{ $slot }}
</div>
