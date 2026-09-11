<?php

use App\Models\Meta;
use App\Wealthsimple\ChromeLoginCapture;
use App\Wealthsimple\SessionStore;
use Database\Seeders\DemoJournalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('parses the oauth cookie the same way Bagholder does', function () {
    $oauth = json_encode([
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'identity_canonical_id' => 'id-1',
        'client_id' => 'cid',
    ]);
    $body = ChromeLoginCapture::tokensFromCookieList([
        ['name' => 'wssdi', 'value' => 'dev-1'],
        ['name' => '_oauth2_access_v2', 'value' => $oauth],
    ]);
    expect($body)->toMatchArray([
        'access_token' => 'tok',
        'refresh_token' => 'ref',
        'identity_canonical_id' => 'id-1',
        'client_id' => 'cid',
        'wssdi' => 'dev-1',
    ]);
});

it('starts Chrome login from Connect and never shows a paste box', function () {
    $this->seed(DemoJournalSeeder::class);
    $page = Livewire::test('pages::dashboard')->call('openConnect');
    $html = $page->html();

    expect($page->get('capturing'))->toBeTrue()
        ->and($html)->toContain('Waiting for Wealthsimple login')
        ->and($html)->not->toContain('Paste a session')
        ->and($html)->not->toContain('Paste a Wealthsimple session')
        ->and($html)->not->toContain('sessionJson')
        ->and(Meta::getValue('ws_capturing'))->toBe('1');

    $page->call('cancelCapture')->assertSet('capturing', false);
});

it('findBrowser locates Chrome or Edge on this Mac', function () {
    $browser = ChromeLoginCapture::findBrowser();
    expect($browser)->not->toBe('')
        ->and(file_exists($browser))->toBeTrue();
});

it('opens Bagholder Chrome once and never relaunches after close', function () {
    $py = ChromeLoginCapture::bagholderPy();
    expect($py)->toBe('/Users/md/dev/Bagholder/bagholder.py')
        ->and(file_exists($py))->toBeTrue();

    $src = file_get_contents(ChromeLoginCapture::scriptPath());
    expect($src)->toContain('_try_capture_from_cdp')
        ->and($src)->toContain('Sign-in canceled')
        ->and($src)->toContain('--user-data-dir=')
        ->and($src)->not->toContain('ws_chrome_profile')
        ->and($src)->not->toContain('Brave Browser');
});
it('clears Waiting when capture status is done and SessionStore already has tokens', function () {
    $this->seed(DemoJournalSeeder::class);

    SessionStore::save([
        'access_token' => 'tok-live',
        'refresh_token' => 'ref-live',
        'identity_canonical_id' => 'id-live',
        'client_id' => 'cid',
    ]);
    Meta::putValue('ws_connected', '1');
    Meta::putValue('ws_capturing', '1');
    Meta::putValue('ws_capture_error', '');
    // done status, but session out file missing (race after unlink / late read)
    file_put_contents(ChromeLoginCapture::statusPath(), json_encode([
        'ok' => true,
        'state' => 'done',
        'error' => '',
        'pid' => 0,
    ])."\n");
    @unlink(ChromeLoginCapture::outPath());

    $page = Livewire::test('pages::dashboard');
    // mount should clear stale Waiting because status done + session present
    expect($page->get('capturing'))->toBeFalse()
        ->and(Meta::getValue('ws_capturing'))->toBe('0')
        ->and($page->get('captureStatus'))->not->toContain('Waiting for Wealthsimple');
});

it('tickCapture clears Waiting when Meta capturing already cleared but session present', function () {
    $this->seed(DemoJournalSeeder::class);

    SessionStore::save([
        'access_token' => 'tok-live',
        'refresh_token' => 'ref-live',
        'identity_canonical_id' => 'id-live',
        'client_id' => 'cid',
    ]);
    Meta::putValue('ws_connected', '1');
    Meta::putValue('ws_capturing', '0');
    Meta::putValue('ws_capture_error', '');
    file_put_contents(ChromeLoginCapture::statusPath(), json_encode([
        'ok' => true,
        'state' => 'idle',
        'error' => '',
    ])."\n");

    $page = Livewire::test('pages::dashboard')
        ->set('capturing', true)
        ->set('connectOpen', true)
        ->set('captureStatus', 'Waiting for Wealthsimple login…')
        ->call('tickCapture');

    expect($page->get('capturing'))->toBeFalse()
        ->and($page->get('connectOpen'))->toBeFalse()
        ->and($page->get('captureStatus'))->toBe('')
        ->and(Meta::getValue('ws_capturing'))->toBe('0');
});
