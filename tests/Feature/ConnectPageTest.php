<?php

use App\Wealthsimple\ChromeLoginCapture;
use App\Wealthsimple\SessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('does not render a Paste session textarea on the dashboard Connect flow', function () {
    $html = Livewire::test('pages::dashboard')->html();

    expect($html)->toContain('wire:click="openConnect"')
        ->and($html)->not->toContain('Paste a Wealthsimple session')
        ->and($html)->not->toContain('Paste a session')
        ->and($html)->not->toContain('wire:model="form.sessionJson"')
        ->and($html)->not->toContain('Save and sync')
        ->and($html)->not->toContain('<textarea')
        ->and($html)->not->toContain('data-flux-textarea')
        ->and($html)->not->toContain('Livewire experiment')
        ->and($html)->not->toContain('data-flux-main')
        ->and($html)->not->toContain('data-flux-sidebar')
        ->and($html)->not->toContain('target="_blank"');
});

it('hosts filters as a right flyout overlay', function () {
    $html = Livewire::test('pages::dashboard')->html();

    expect($html)->toContain('data-modal="filters"')
        ->and($html)->toContain('data-flux-flyout')
        ->and($html)->toContain('ml-auto')
        ->and($html)->not->toContain('data-flux-sidebar');
});

it('always shows ellipsis menu with Connect Wealthsimple primary when not connected, even with demo data', function () {
    $this->seed(\Database\Seeders\DemoJournalSeeder::class);
    $html = Livewire::test('pages::dashboard')->html();

    // Desktop menuHtml: ⋯ always present; Connect Wealthsimple primary; no standalone header Connect button.
    // Flux resolves icon="ellipsis-horizontal" to SVG — assert aria-label="Menu" instead of the icon name.
    expect($html)->toContain('aria-label="Menu"')
        ->and($html)->toContain('Connect Wealthsimple')
        ->and($html)->toContain('wire:click="openConnect"')
        ->and($html)->not->toContain('wire:click="openConnect">Connect</')
        ->and($html)->not->toContain('wire:click.async="startSync"')
        ->and($html)->not->toContain('Refresh session')
        ->and($html)->toContain('Add trade')
        ->and($html)->toContain('Import CSV')
        ->and($html)->toContain('Load folder')
        ->and($html)->toContain('Export trades CSV')
        ->and($html)->toContain('Clear data')
        ->and($html)->not->toContain('wire:click="disconnect"')
        ->and($html)->not->toContain('x-slot:actions');
});

it('shows Sync and Logout in the menu only after a session is saved', function () {
    SessionStore::save(['access_token' => 'tok', 'refresh_token' => 'abc']);
    $html = Livewire::test('pages::dashboard')->html();

    expect($html)->toContain('Logout')
        ->and($html)->toContain('wire:click.async="startSync"')
        ->and($html)->toContain('Refresh session')
        ->and($html)->toContain('aria-label="Menu"')
        ->and($html)->toContain('Add trade')
        ->and($html)->toContain('wire:click="disconnect"')
        ->and($html)->not->toContain('wire:click="openConnect"')
        ->and($html)->not->toContain('Connect Wealthsimple')
        ->and($html)->not->toContain('Connect again');
});

it('redirects /connect away from any paste page', function () {
    $this->get('/connect')->assertRedirect('/');
});

it('finds a Chrome or Edge browser on this Mac', function () {
    $browser = ChromeLoginCapture::findBrowser();
    if ($browser === '') {
        // Deferred: ConnectPage Mac Chrome — Linux box has no browser binary.
        $this->markTestSkipped('Chrome/Edge not on this machine (Mac-only).');
    }

    expect(file_exists($browser))->toBeTrue();
});

it('openConnect waits for Wealthsimple login and never shows Paste', function () {
    if (ChromeLoginCapture::findBrowser() === '') {
        $this->markTestSkipped('Chrome/Edge not on this machine (Mac-only).');
    }

    $component = Livewire::test('pages::dashboard')->call('openConnect');

    expect($component->get('capturing'))->toBeTrue()
        ->and($component->get('captureStatus'))->toContain('Waiting for Wealthsimple login')
        ->and($component->html())->not->toContain('Paste a Wealthsimple session')
        ->and($component->html())->not->toContain('wire:model="form.sessionJson"')
        ->and($component->html())->not->toContain('data-flux-textarea');

    $component->call('cancelCapture')->assertSet('capturing', false);
});
