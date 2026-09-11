<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Connect')] class extends Component
{
    public function mount(): void
    {
        $this->redirect('/', navigate: true);
    }
};
?>

<div class="min-h-dvh px-4 py-16">
    <flux:heading size="lg">Connect</flux:heading>
    <flux:text class="mt-2">Use Connect on the dashboard — Chrome opens Wealthsimple login.</flux:text>
</div>
