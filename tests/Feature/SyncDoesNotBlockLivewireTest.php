<?php

use App\Models\Meta;
use App\Wealthsimple\SessionStore;
use App\Wealthsimple\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('startSync does not pull Wealthsimple on the Livewire request', function () {
    Http::fake();
    SessionStore::save(['access_token' => 'tok', 'refresh_token' => 'abc']);

    Livewire::test('pages::dashboard')
        ->call('startSync')
        ->assertSet('syncing', true);

    Http::assertNothingSent();
    expect(Meta::getValue('ws_sync_phase'))->toBe('start')
        ->and(Meta::getValue('ws_sync_step'))->toContain('Checking for new rows');
});

it('tickSync only reads status and never calls Guzzle', function () {
    Http::fake();
    Meta::putValue('ws_sync_phase', 'nav');
    Meta::putValue('ws_sync_step', 'Fetching equity history…');

    $component = Livewire::test('pages::dashboard')
        ->set('syncing', true)
        ->call('tickSync');

    Http::assertNothingSent();
    expect($component->get('syncing'))->toBeTrue()
        ->and($component->get('syncStep'))->toBe('Fetching equity history…');
});

it('tickSync marks done from meta without pulling', function () {
    Http::fake();
    Meta::putValue('ws_sync_phase', 'done');
    Meta::putValue('ws_sync_step', '');
    Meta::putValue('ws_synced_at', now()->toIso8601String());

    Livewire::test('pages::dashboard')
        ->set('syncing', true)
        ->call('tickSync')
        ->assertSet('syncing', false);

    Http::assertNothingSent();
});

it('status does not advance the sync phase', function () {
    Http::fake();
    Meta::putValue('ws_sync_phase', 'nav');
    Meta::putValue('ws_sync_step', 'Fetching equity history…');

    $result = SyncService::status();

    Http::assertNothingSent();
    expect($result['done'])->toBeFalse()
        ->and($result['phase'])->toBe('nav')
        ->and(Meta::getValue('ws_sync_phase'))->toBe('nav');
});
