<?php

use App\Journal\Book;
use App\Journal\BookCache;
use App\Journal\MarketImport;
use App\Journal\Snapshot;
use App\Wealthsimple\SessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('merges desktop bagholder accounts so hasMargin is true when UAT contains MARGIN', function () {
    if (! is_file(MarketImport::defaultPath())) {
        $this->markTestSkipped('Desktop bagholder.db missing');
    }

    // Thin local journal (no accounts seeded) → Snapshot merges desktop.
    BookCache::flush();
    $snap = Snapshot::load();
    $uatHit = false;
    foreach ($snap['accounts'] as $a) {
        $blob = strtoupper(($a['type'] ?? '').' '.($a['unifiedAccountType'] ?? ''));
        if (str_contains($blob, 'MARGIN')) {
            $uatHit = true;
            break;
        }
    }
    expect($uatHit)->toBeTrue()
        ->and($snap['balances'])->not->toBeEmpty()
        ->and($snap['cashCurrencies'])->not->toBeEmpty();

    $book = Book::build($snap, []);
    expect($book['portfolio']['hasMargin'])->toBeTrue()
        ->and($book['portfolio']['availableMargin'])->not->toBeNull();
});

it('shows Sync Refresh Logout menu when dry-connected, not header Connect', function () {
    SessionStore::saveDryConnected();
    $html = Livewire::test('pages::dashboard')->html();

    expect($html)->toContain('Sync now')
        ->and($html)->toContain('Refresh session')
        ->and($html)->toContain('Logout')
        ->and($html)->not->toContain('wire:click="openConnect"');
});

it('Logout clears dry connected session', function () {
    SessionStore::saveDryConnected();
    expect(SessionStore::connected())->toBeTrue();

    Livewire::test('pages::dashboard')->call('disconnect');

    expect(SessionStore::connected())->toBeFalse()
        ->and(SessionStore::isDry())->toBeFalse();
});
