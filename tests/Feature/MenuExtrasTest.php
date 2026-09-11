<?php

use App\Journal\CsvImport;
use App\Models\Activity;
use App\Wealthsimple\SessionStore;
use Database\Seeders\DemoJournalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows desktop menu extras when sessioned', function () {
    SessionStore::saveDryConnected();
    $html = Livewire::test('pages::dashboard')->html();

    expect($html)->toContain('Add trade')
        ->and($html)->toContain('Import CSV')
        ->and($html)->toContain('Load folder')
        ->and($html)->toContain('Export trades CSV')
        ->and($html)->toContain('Clear data')
        ->and($html)->toContain('Sync now')
        ->and($html)->toContain('Refresh session')
        ->and($html)->toContain('Logout')
        ->and($html)->not->toContain('wire:click="openConnect"');
});

it('adds a manual trade from the menu path', function () {
    SessionStore::saveDryConnected();
    $this->seed(DemoJournalSeeder::class);

    $page = Livewire::test('pages::dashboard')
        ->call('openAddTrade')
        ->assertSet('tradeOpen', true)
        ->set('tradeSymbol', 'LUNR')
        ->set('tradeQty', '5')
        ->set('tradePrice', '12.5')
        ->set('tradeSide', 'BUY')
        ->call('saveTrade')
        ->assertSet('tradeOpen', false);

    expect($page->get('notice'))->toBe('Trade added')
        ->and(Activity::query()->where('symbol', 'LUNR')->count())->toBe(1);
});

it('imports a legacy CSV through the dashboard', function () {
    SessionStore::saveDryConnected();
    $text = file_get_contents(base_path('tests/fixtures/activities-legacy-sample.csv'));

    $page = Livewire::test('pages::dashboard')
        ->call('importCsvText', 'legacy.csv', $text);

    expect($page->get('notice'))->toContain('imported')
        ->and(Activity::query()->where('source', 'csv')->count())->toBe(4);
});

it('exports trades CSV from the current book', function () {
    SessionStore::saveDryConnected();
    $this->seed(DemoJournalSeeder::class);
    $name = 'bagholder-trades-'.now('America/Edmonton')->toDateString().'.csv';

    Livewire::test('pages::dashboard')
        ->call('exportTradesCsv')
        ->assertFileDownloaded($name);
});

it('clears journal data from the menu and shows empty state', function () {
    SessionStore::saveDryConnected();
    $this->seed(DemoJournalSeeder::class);
    expect(Activity::query()->count())->toBeGreaterThan(0);

    $page = Livewire::test('pages::dashboard')->call('clearData');

    expect(Activity::query()->count())->toBe(0)
        ->and(SessionStore::connected())->toBeFalse()
        ->and($page->html())->toContain('No activity yet')
        ->and($page->html())->toContain('Connect');
});

it('loads a folder of CSVs', function () {
    SessionStore::saveDryConnected();
    $page = Livewire::test('pages::dashboard')
        ->call('openFolder')
        ->set('folderPath', base_path('tests/fixtures'))
        ->call('watchFolder');

    expect($page->get('folderError'))->toBe('')
        ->and($page->get('notice'))->toContain('imported')
        ->and(Activity::query()->count())->toBeGreaterThan(0)
        ->and(CsvImport::watchStatus()['watching'])->toBeTrue();
});
