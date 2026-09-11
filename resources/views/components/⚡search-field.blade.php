<?php

use Livewire\Attributes\Modelable;
use Livewire\Component;

new class extends Component
{
    #[Modelable]
    public ?string $query = '';
};
?>

<div>
    <input
        type="search"
        wire:model.live.debounce.200ms="query"
        {{ $attributes->class('field-control') }}
        placeholder="Type to search"
    />
</div>
