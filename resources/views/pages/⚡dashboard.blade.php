<?php

use App\Journal\BookCache;
use App\Journal\CsvImport;
use App\Journal\Snapshot;
use App\Livewire\Forms\JournalFilters;
use App\Models\Activity;
use App\Models\Meta;
use App\Support\Money;
use App\Wealthsimple\Api;
use App\Wealthsimple\ChromeLoginCapture;
use App\Wealthsimple\SessionStore;
use App\Wealthsimple\SyncService;
use Flux\DateRange;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::app')] #[Title('Bagholder')] class extends Component
{
    use WithFileUploads;

    public JournalFilters $form;

    public string $view = 'dashboard';

    public string $theme = 'nocturne';

    public string $tab = 'closed';

    public string $sortTab = 'closed';

    public string $sortKey = 'exitDate';

    public string $sortDir = 'desc';

    public string $openSortKey = 'date';

    public string $openSortDir = 'desc';

    public string $activitySortKey = 'when';

    public string $activitySortDir = 'desc';

    public string $innerSortKey = 'when';

    public string $innerSortDir = 'desc';

    public int $activityPage = 1;

    public bool $executionsOpen = false;

    public bool $ordersOpen = false;

    public string $ordersTab = 'pending';

    public bool $ticketOpen = false;

    public ?float $ticketHeldQty = null;

    public string $ticketHeldAccountId = '';

    public string $ticketSymbol = '';

    public string $ticketSide = 'BUY';

    public string $ticketExchange = '';

    public string $dryNotice = '';

    public ?string $selectedTradeId = null;

    public ?string $selectedLotId = null;

    public string $chartTf = '1h';

    public bool $filtersOpen = false;

    public bool $connectOpen = false;

    public bool $capturing = false;

    public string $captureStatus = '';

    public string $captureError = '';

    public bool $syncing = false;

    public string $syncStep = '';

    public string $lastSyncedAt = '';

    public string $draftThesis = '';

    public string $draftGrade = '';

    public string $draftTags = '';

    public string $benchmark = 'SP500';

    public string $posSortKey = 'unreal';

    public string $posSortDir = 'desc';

    public string $bySymbolSortKey = 'pnlCad';

    public string $bySymbolSortDir = 'desc';

    public string $cfHoldSortKey = 'ttm';

    public string $cfHoldSortDir = 'desc';

    public string $cfHistSortKey = 'date';

    public string $cfHistSortDir = 'desc';

    public string $syncError = '';

    public string $notice = '';

    public string $noticeKind = '';

    public ?DateRange $closedRange = null;

    public bool $tradeOpen = false;

    public string $tradeDate = '';

    public string $tradeAccount = '';

    public string $tradeSymbol = '';

    public string $tradeSide = 'BUY';

    public string $tradeQty = '';

    public string $tradePrice = '';

    public string $tradeCurrency = 'CAD';

    public string $tradeFees = '';

    public string $tradeError = '';

    public bool $importOpen = false;

    /** @var mixed */
    public $csvFiles = [];

    public array $importReport = [];

    public bool $folderOpen = false;

    public string $folderPath = '';

    public string $folderError = '';

    public array $folderStatus = [];

    public bool $dataOpen = false;

    public function mount(): void
    {
        $v = request()->query('view');
        if (is_string($v) && in_array($v, ['dashboard', 'trades', 'positions', 'cashflow'], true)) {
            $this->setView($v);
        }
        $this->syncStep = (string) Meta::getValue('ws_sync_step', '');
        $this->lastSyncedAt = (string) Meta::getValue('ws_synced_at', '');
        $this->syncError = (string) Meta::getValue('ws_sync_error', '');
        $phase = (string) Meta::getValue('ws_sync_phase', 'idle');
        $this->syncing = $phase !== 'idle' && $phase !== 'done';
        $this->capturing = ChromeLoginCapture::capturing();
        $this->captureError = (string) Meta::getValue('ws_capture_error', '');
        if (! $this->capturing) {
            $this->connectOpen = false;
        }
        $this->benchmark = (string) Meta::getValue('active_benchmark', 'SP500') ?: 'SP500';
        // Stale Waiting: Meta capturing but capture already finished (session present / status done).
        if ($this->capturing) {
            $st = ChromeLoginCapture::status();
            $sessionReady = SessionStore::hasRefresh() || SessionStore::connected();
            if (! empty($st['done']) || (! ChromeLoginCapture::capturing() && $sessionReady)) {
                Meta::putValue('ws_capturing', '0');
                $this->capturing = false;
                $this->connectOpen = false;
                $this->captureStatus = '';
                if (! empty($st['connected']) || $sessionReady) {
                    $this->captureError = '';
                    if (! $this->syncing) {
                        SyncService::dispatch();
                        $this->syncing = true;
                        $this->syncStep = (string) Meta::getValue('ws_sync_step', 'Checking for new rows…');
                    }
                } else {
                    $this->captureError = '';
                }
            } else {
                $this->captureStatus = 'Waiting for Wealthsimple login…';
                $this->connectOpen = true;
            }
        }
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form.')) {
            $field = substr($name, 5);
            if ($field === 'closedIn') {
                $this->form->applyClosedIn($this->form->closedIn);
                $this->syncClosedRangeFromForm();
            }
            if ($field === 'closed' || str_starts_with($field, 'closed.')) {
                $this->form->syncFromClosedRange();
                $this->syncClosedRangeFromForm();
            }
            if (in_array($field, ['from', 'to'], true)) {
                $this->form->syncClosedInFromRange();
                $this->syncClosedRangeFromForm();
            }
            if ($field === 'priceOp' && $this->form->priceOp !== 'between') {
                $this->form->priceMax = '';
            }
            if ($field === 'priceOp' && $this->form->priceOp === '') {
                $this->form->priceMin = '';
                $this->form->priceMax = '';
            }
            foreach (['hold', 'pnl', 'qty'] as $rk) {
                if ($field === $rk.'Op' && $this->form->{$rk.'Op'} === '') {
                    $this->form->{$rk.'Min'} = '';
                }
            }
            $this->activityPage = 1;
            unset($this->book);
        }
        if ($name === 'closedRange' || str_starts_with($name, 'closedRange')) {
            $this->applyRangeToForm();
        }
        if ($name === 'tab') {
            $this->activityPage = 1;
            unset($this->book);
        }
    }

    #[Computed]
    public function book(): array
    {
        return Snapshot::book($this->form->payload(), [
            'closed' => ['key' => $this->sortKey, 'dir' => $this->sortDir],
            'open' => ['key' => $this->openSortKey, 'dir' => $this->openSortDir],
            'activity' => ['key' => $this->activitySortKey, 'dir' => $this->activitySortDir],
        ]);
    }

    public function sortBy(string $tab, string $key): void
    {
        // Desktop ledger.html: new column always starts desc; same key toggles.
        $toggle = function (string $curKey, string $curDir, string $key): array {
            if ($curKey === $key) {
                return [$key, $curDir === 'asc' ? 'desc' : 'asc'];
            }

            return [$key, 'desc'];
        };
        if ($tab === 'closed') {
            [$this->sortKey, $this->sortDir] = $toggle($this->sortKey, $this->sortDir, $key);
        } elseif ($tab === 'open') {
            [$this->openSortKey, $this->openSortDir] = $toggle($this->openSortKey, $this->openSortDir, $key);
        } elseif ($tab === 'activity') {
            [$this->activitySortKey, $this->activitySortDir] = $toggle($this->activitySortKey, $this->activitySortDir, $key);
        } elseif ($tab === 'inner') {
            [$this->innerSortKey, $this->innerSortDir] = $toggle($this->innerSortKey, $this->innerSortDir, $key);
        } elseif ($tab === 'positions') {
            [$this->posSortKey, $this->posSortDir] = $toggle($this->posSortKey, $this->posSortDir, $key);
        } elseif ($tab === 'bySymbol') {
            [$this->bySymbolSortKey, $this->bySymbolSortDir] = $toggle($this->bySymbolSortKey, $this->bySymbolSortDir, $key);
        } elseif ($tab === 'cfHold') {
            [$this->cfHoldSortKey, $this->cfHoldSortDir] = $toggle($this->cfHoldSortKey, $this->cfHoldSortDir, $key);
        } elseif ($tab === 'cfHist') {
            [$this->cfHistSortKey, $this->cfHistSortDir] = $toggle($this->cfHistSortKey, $this->cfHistSortDir, $key);
        }
        unset($this->book);
    }

    public function openExecutions(string $id): void
    {
        $this->selectedTradeId = $id;
        $this->chartTf = '1h';
        $this->executionsOpen = true;
        $trade = null;
        foreach ($this->book['closed'] ?? [] as $row) {
            if (($row['id'] ?? '') === $id) {
                $trade = $row;
                break;
            }
        }
        $this->loadJournalDraft($trade);
        // Side panel is enough on select; do not auto-open executions modal.
    }

    public function closeExecutions(): void
    {
        $this->executionsOpen = false;
        $this->selectedTradeId = null;
        $this->dispatch('modal-close', name: 'executions');
    }


    public function flashDryNotice(string $message): void
    {
        $this->dryNotice = $message;
    }

    public function clearDryNotice(): void
    {
        $this->dryNotice = '';
    }

    public function openOrders(): void
    {
        $this->ordersOpen = true;
        $this->dispatch('modal-show', name: 'orders');
    }

    public function closeOrders(): void
    {
        $this->ordersOpen = false;
        $this->dispatch('modal-close', name: 'orders');
    }

    public function toggleOrders(): void
    {
        if ($this->ordersOpen) {
            $this->closeOrders();
        } else {
            $this->openOrders();
        }
    }

    public function setOrdersTab(string $tab): void
    {
        if (in_array($tab, \App\Journal\Orders::TABS, true)) {
            $this->ordersTab = $tab;
        }
    }

    public function openOrdersCount(): int
    {
        return \App\Journal\Orders::openCount();
    }

    public function openTicket(string $symbol = '', string $side = 'BUY', string $exchange = ''): void
    {
        $this->ticketSymbol = $symbol !== '' ? $symbol : 'QNC';
        $side = strtoupper($side);
        $this->ticketSide = in_array($side, ['BUY', 'SELL'], true) ? $side : 'BUY';
        $this->ticketExchange = $exchange;
        $this->ticketHeldQty = null;
        $this->ticketHeldAccountId = '';
        $sym = strtoupper($this->ticketSymbol);
        foreach ($this->book['positions'] ?? [] as $p) {
            if (strtoupper((string) ($p['symbol'] ?? '')) !== $sym) {
                continue;
            }
            if (! empty($p['short'])) {
                continue;
            }
            $q = (float) ($p['qty'] ?? 0);
            if ($q > 0) {
                $this->ticketHeldQty = $q;
                $this->ticketHeldAccountId = (string) ($p['accountId'] ?? '');
                break;
            }
        }
        $this->ticketOpen = true;
        $this->ordersOpen = false;
        $this->dispatch('modal-close', name: 'orders');
        $this->dispatch('modal-show', name: 'ticket');
    }

    public function closeTicket(): void
    {
        $this->ticketOpen = false;
        $this->dispatch('modal-close', name: 'ticket');
    }

    public function setTicketSide(string $side): void
    {
        $side = strtoupper($side);
        if (in_array($side, ['BUY', 'SELL'], true)) {
            $this->ticketSide = $side;
        }
    }

    public function selectedTrade(): ?array
    {
        if ($this->selectedTradeId === null) {
            return null;
        }
        foreach ($this->book['closed'] as $row) {
            if (($row['id'] ?? '') === $this->selectedTradeId) {
                return $row;
            }
        }

        return null;
    }

    public function executionsRows(): array
    {
        $trade = $this->selectedTrade();
        if (! $trade) {
            return [];
        }
        $acts = array_map(function ($a) {
            $a['when'] = \App\Journal\Dates::activityWhen($a);
            $a['displaySide'] = \App\Journal\Sides::activityDisplaySide($a);

            return $a;
        }, $trade['executions'] ?? []);
        $key = $this->innerSortKey;
        $dir = $this->innerSortDir;

        return \App\Journal\Filters::sortRows($acts, function ($a) use ($key) {
            $map = [
                'when' => $a['when'] ?? '',
                'side' => $a['displaySide'] ?? '',
                'quantity' => $a['quantity'] ?? 0,
                'currency' => $a['currency'] ?? '',
                'unitPrice' => $a['unitPrice'] ?? 0,
                'netCashAmount' => $a['netCashAmount'] ?? 0,
            ];

            return $map[$key] ?? null;
        }, $dir);
    }

    public function setClosedIn(string $year): void
    {
        $this->form->applyClosedIn($year);
        $this->syncClosedRangeFromForm();
        $this->activityPage = 1;
        unset($this->book);
    }

    public function applyRangeToForm(): void
    {
        $range = $this->closedRange;
        if ($range === null || ! $range->hasStart() || ! $range->hasEnd()) {
            if ($range === null || (method_exists($range, 'isNotAllTime') && $range->isNotAllTime())) {
                $this->form->from = '';
                $this->form->to = '';
                $this->form->closedIn = '';
            }
            $this->activityPage = 1;
            unset($this->book);

            return;
        }
        if (method_exists($range, 'isNotAllTime') && ! $range->isNotAllTime()) {
            $this->form->from = '';
            $this->form->to = '';
            $this->form->closedIn = '';
        } else {
            $this->form->from = $range->start()->format('Y-m-d');
            $this->form->to = $range->end()->format('Y-m-d');
            $this->form->syncClosedInFromRange();
        }
        $this->activityPage = 1;
        unset($this->book);
    }

    public function syncClosedRangeFromForm(): void
    {
        if ($this->form->from !== '' && $this->form->to !== '') {
            $this->closedRange = new DateRange($this->form->from, $this->form->to);
        } else {
            $this->closedRange = null;
        }
    }

    public function earliestClose(): string
    {
        $years = $this->book['years'] ?? [];
        if ($years === []) {
            return now()->subYears(20)->format('Y-m-d');
        }

        return min(array_map('strval', $years)).'-01-01';
    }

    #[On('bh-set-filter')]
    public function applySheetFilter(string $key, string $value): void
    {
        $this->setFilter($key, $value);
    }

    public function setFilter(string $key, string $value): void
    {
        if (! in_array($key, ['account', 'exchange', 'symbol', 'priceOp', 'grade', 'tag', 'side', 'kind', 'result'], true)) {
            return;
        }
        $this->form->{$key} = $value;
        $this->updated('form.'.$key);
    }

    public function escapeIdle(): void
    {
        // Orders / Ticket first — do not clear filters when closing those panels.
        if ($this->ticketOpen) {
            $this->closeTicket();

            return;
        }
        if ($this->ordersOpen) {
            $this->closeOrders();

            return;
        }
        // Filters flyout is closed in JS (dialog.close). Clear active filters here.
        if ($this->form->active()) {
            $this->resetFilters();
        }
    }

    public function setTheme(string $theme): void
    {
        if (! in_array($theme, ['nocturne', 'midnight', 'light'], true)) {
            return;
        }
        $this->theme = $theme;
        $this->js('localStorage.setItem("bh2.theme", "'.$theme.'"); document.documentElement.setAttribute("data-bh-theme", "'.$theme.'");');
    }

    public function tickQuotes(): void
    {
        if ($this->syncing || $this->capturing) {
            return;
        }
        $positions = $this->book['positions'] ?? [];
        $out = \App\Journal\QuoteRefresh::run(yahoo: true, positions: $positions);
        $changed = ! empty($out['changed']);
        $st = CsvImport::watchStatus();
        if (! empty($st['watching'])) {
            $last = (string) ($st['lastScan'] ?? '');
            $age = $last === '' ? 10_000 : (time() - (int) strtotime($last));
            if ($age >= 600) {
                $scan = CsvImport::scanWatchFolder(false);
                if (($scan['added'] ?? 0) > 0) {
                    $changed = true;
                }
            }
        }
        if ($changed) {
            unset($this->book);
        }
    }

    public function resetFilters(): void
    {
        $this->form->reset();
        $this->closedRange = null;
        $this->activityPage = 1;
        unset($this->book);
    }

    public function clearFilter(string $key): void
    {
        if ($key === 'date' || $key === 'closed' || $key === 'closedIn') {
            $this->form->closedIn = '';
            $this->form->from = '';
            $this->form->to = '';
            $this->form->closed = null;
            $this->closedRange = null;
        } elseif ($key === 'price') {
            $this->form->priceOp = '';
            $this->form->priceMin = '';
            $this->form->priceMax = '';
        } elseif (in_array($key, ['hold', 'pnl', 'qty'], true)) {
            $this->form->{$key.'Op'} = '';
            $this->form->{$key.'Min'} = '';
        } elseif (in_array($key, ['account', 'exchange', 'symbol', 'grade', 'tag', 'side', 'kind', 'result'], true)) {
            $this->form->{$key} = '';
        } else {
            return;
        }
        $this->activityPage = 1;
        unset($this->book);
    }

    /**
     * Desktop-style filter chips (field label + value + remove).
     *
     * @return list<array{key:string,field:string,value:string}>
     */
    public function activeFilterChips(): array
    {
        $out = [];
        $f = $this->form;
        if ($f->closedIn !== '') {
            $out[] = ['key' => 'closedIn', 'field' => 'Date', 'value' => $f->closedIn];
        } elseif ($f->from !== '' || $f->to !== '') {
            $label = trim(($f->from !== '' ? $f->from : '…').' → '.($f->to !== '' ? $f->to : '…'));
            $out[] = ['key' => 'date', 'field' => 'Date', 'value' => $label];
        }
        $map = [
            'account' => 'Account is',
            'symbol' => 'Symbol is',
            'exchange' => 'Exchange is',
            'grade' => 'Grade is',
            'tag' => 'Tag is',
            'side' => 'Side is',
            'kind' => 'Kind is',
            'result' => 'Result is',
        ];
        foreach ($map as $key => $field) {
            $v = (string) ($f->{$key} ?? '');
            if ($v === '') {
                continue;
            }
            if ($key === 'result') {
                $v = ['win' => 'Winners', 'loss' => 'Losers', 'breakeven' => 'Breakeven'][$v] ?? $v;
            }
            $out[] = ['key' => $key, 'field' => $field, 'value' => $v];
        }
        if ($f->priceOp !== '') {
            $op = $f->priceOp;
            $val = $op === 'between'
                ? trim($f->priceMin.' – '.$f->priceMax)
                : trim($f->priceMin);
            $out[] = ['key' => 'price', 'field' => 'Price '.$op, 'value' => $val !== '' ? $val : $op];
        }
        foreach ([
            'hold' => 'Hold',
            'pnl' => 'P&L',
            'qty' => 'Qty',
        ] as $rk => $label) {
            $op = (string) ($f->{$rk.'Op'} ?? '');
            if ($op === '') {
                continue;
            }
            $val = trim((string) ($f->{$rk.'Min'} ?? ''));
            $opWord = $op === 'over' ? '>' : ($op === 'under' ? '<' : $op);
            $out[] = ['key' => $rk, 'field' => $label.' '.$opWord, 'value' => $val !== '' ? $val : $op];
        }

        return $out;
    }

    public function setSymbol(string $symbol): void
    {
        $this->form->symbol = $symbol;
        $this->tab = 'closed';
        $this->activityPage = 1;
        unset($this->book);
    }

    /** Desktop gradeOpen: single trade → open it; else filter Trades by grade. */
    public function gradeOpen(string $grade): void
    {
        $grade = strtoupper(trim($grade));
        if (! in_array($grade, ['A', 'B', 'C', 'F'], true)) {
            return;
        }
        $bucket = null;
        foreach ($this->book['grades']['buckets'] ?? [] as $b) {
            if (($b['grade'] ?? '') === $grade) {
                $bucket = $b;
                break;
            }
        }
        if (! $bucket || empty($bucket['n'])) {
            return;
        }
        $this->js('(() => { const mat = document.querySelector(".bh-mat"); const data = mat && window.Alpine ? Alpine.$data(mat) : null; if (data) data.bhView = "trades"; })()');
        $this->view = 'trades';
        $this->tab = 'closed';
        if ((int) $bucket['n'] === 1) {
            $id = (string) ($bucket['tradeIds'][0] ?? '');
            if ($id !== '') {
                $this->openExecutions($id);
            }

            return;
        }
        $this->form->grade = $grade;
        $this->activityPage = 1;
        $this->selectedTradeId = null;
        unset($this->book);
    }

    public function loadMoreActivity(): void
    {
        $this->activityPage++;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activityPageRows(): array
    {
        return array_slice($this->book['activityRows'] ?? [], 0, $this->activityPage * 50);
    }

    public function openConnect(): void
    {
        $result = ChromeLoginCapture::start();
        if (! ($result['ok'] ?? false)) {
            $this->capturing = false;
            $this->connectOpen = true;
            $this->captureError = (string) ($result['error'] ?? 'Could not start Chrome login.');
            $this->captureStatus = '';

            return;
        }
        $this->capturing = true;
        $this->connectOpen = true;
        $this->captureError = '';
        $this->captureStatus = 'Waiting for Wealthsimple login…';
    }

    public function tickCapture(): void
    {
        if (! $this->capturing) {
            return;
        }
        $result = ChromeLoginCapture::status();
        $state = (string) ($result['state'] ?? '');
        $sessionReady = SessionStore::hasRefresh() || SessionStore::connected();
        // Handoff: status done, OR Meta cleared while session already present (done-file race).
        if (! empty($result['done']) || (! ChromeLoginCapture::capturing() && $sessionReady) || ($sessionReady && in_array($state, ['done', 'idle', 'error', 'cancelled'], true))) {
            $this->capturing = false;
            $this->connectOpen = false;
            $this->captureStatus = '';
            Meta::putValue('ws_capturing', '0');
            if ($state === 'done' || ! empty($result['connected']) || $sessionReady) {
                $this->captureError = '';
                if (! $this->syncing) {
                    SyncService::dispatch();
                    $this->syncing = true;
                    $this->syncError = '';
                    $this->syncStep = (string) Meta::getValue('ws_sync_step', 'Checking for new rows…');
                }
            } else {
                // Window closed or capture ended without a session — Ricardo: Cancel, idle Connect.
                $this->captureError = '';
            }

            return;
        }
        $this->captureStatus = 'Waiting for Wealthsimple login…';
    }

    public function cancelCapture(): void
    {
        ChromeLoginCapture::cancel();
        $this->capturing = false;
        $this->connectOpen = false;
        $this->captureStatus = '';
        $this->captureError = '';
    }

    public function startSync(): void
    {
        if (SessionStore::isDry()) {
            $this->notice = 'Dry session — connect Wealthsimple to sync.';
            $this->noticeKind = 'err';

            return;
        }
        if (! SessionStore::hasRefresh()) {
            $this->openConnect();

            return;
        }
        SyncService::dispatch();
        $this->syncing = true;
        $this->syncError = '';
        $this->syncStep = (string) Meta::getValue('ws_sync_step', 'Checking for new rows…');
    }

    #[Async]
    public function tickSync(): void
    {
        if (! $this->syncing) {
            return;
        }
        SyncService::ensureWorker();
        $result = SyncService::status();
        $this->syncStep = (string) ($result['step'] ?? '');
        $this->syncError = (string) ($result['error'] ?? '');
        if (! empty($result['done'])) {
            $this->syncing = false;
            $this->lastSyncedAt = (string) Meta::getValue('ws_synced_at', '');
            unset($this->book);
        }
    }


    public function refreshSession(): void
    {
        $this->notice = '';
        $this->noticeKind = '';
        if (SessionStore::isDry()) {
            $this->notice = 'Dry session — connect Wealthsimple to refresh.';
            $this->noticeKind = 'err';

            return;
        }
        if (! SessionStore::hasRefresh()) {
            $this->notice = 'Connect a Wealthsimple session first.';
            $this->noticeKind = 'err';

            return;
        }
        try {
            Api::refresh(SessionStore::read());
            $this->notice = 'Session refreshed';
            $this->noticeKind = 'ok';
        } catch (\Throwable $e) {
            $this->notice = 'Refresh failed';
            $this->noticeKind = 'err';
        }
    }

    public function disconnect(): void
    {
        SessionStore::forget();
        BookCache::flush();
        $this->syncStep = '';
        $this->lastSyncedAt = (string) Meta::getValue('synced_at', '');
        $this->syncing = false;
        unset($this->book);
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    public function tradeAccounts(): array
    {
        $out = [];
        foreach (DB::table('accounts')->orderBy('id')->get() as $r) {
            $name = trim((string) ($r->nickname ?: $r->unified_account_type ?: $r->id));
            $out[] = ['id' => (string) $r->id, 'name' => $name !== '' ? $name : (string) $r->id];
        }

        return $out;
    }

    public function openAddTrade(): void
    {
        $this->tradeOpen = true;
        $this->tradeError = '';
        $this->tradeDate = now('America/Edmonton')->toDateString();
        $this->tradeAccount = '';
        $this->tradeSymbol = '';
        $this->tradeSide = 'BUY';
        $this->tradeQty = '';
        $this->tradePrice = '';
        $this->tradeCurrency = 'CAD';
        $this->tradeFees = '';
        $this->dispatch('modal-show', name: 'add-trade');
    }

    public function closeAddTrade(): void
    {
        $this->tradeOpen = false;
        $this->dispatch('modal-close', name: 'add-trade');
    }

    public function saveTrade(): void
    {
        $this->tradeError = '';
        $acc = collect($this->tradeAccounts())->firstWhere('id', $this->tradeAccount);
        $result = CsvImport::appendManual([
            'date' => $this->tradeDate,
            'symbol' => $this->tradeSymbol,
            'side' => $this->tradeSide,
            'qty' => $this->tradeQty,
            'price' => $this->tradePrice,
            'currency' => $this->tradeCurrency,
            'fees' => $this->tradeFees,
            'accountId' => $acc['id'] ?? 'manual',
            'accountType' => $acc['name'] ?? 'Manual',
        ]);
        if (! ($result['ok'] ?? false)) {
            $this->tradeError = (string) ($result['error'] ?? 'Could not save the trade.');

            return;
        }
        $this->tradeOpen = false;
        $this->dispatch('modal-close', name: 'add-trade');
        unset($this->book);
        $this->notice = ($result['added'] ?? 0) ? 'Trade added' : 'That trade was already recorded';
        $this->noticeKind = 'ok';
    }

    public function openImportCsv(): void
    {
        $this->importOpen = true;
        $this->importReport = [];
        $this->csvFiles = [];
        $this->dispatch('modal-show', name: 'import-csv');
    }

    public function closeImportCsv(): void
    {
        $this->importOpen = false;
        $this->csvFiles = [];
        $this->dispatch('modal-close', name: 'import-csv');
    }

    public function updatedCsvFiles(): void
    {
        $this->importSelectedCsv();
    }

    public function importSelectedCsv(): void
    {
        $files = is_array($this->csvFiles) ? $this->csvFiles : ($this->csvFiles ? [$this->csvFiles] : []);
        if ($files === []) {
            return;
        }
        $report = ['files' => [], 'added' => 0, 'duplicates' => 0];
        foreach ($files as $file) {
            $name = is_object($file) && method_exists($file, 'getClientOriginalName')
                ? (string) $file->getClientOriginalName()
                : 'import.csv';
            if (! str_ends_with(strtolower($name), '.csv') || str_starts_with($name, '._')) {
                $report['files'][] = ['file' => $name, 'error' => 'Not a CSV file'];
                continue;
            }
            $text = is_object($file) && method_exists($file, 'get')
                ? (string) $file->get()
                : (is_object($file) && method_exists($file, 'getRealPath') ? (string) @file_get_contents((string) $file->getRealPath()) : '');
            $r = CsvImport::importText($name, $text);
            if (! ($r['ok'] ?? false)) {
                $report['files'][] = ['file' => $name, 'error' => (string) ($r['error'] ?? 'Import failed')];
            } else {
                $report['files'][] = $r;
                $report['added'] += (int) ($r['added'] ?? 0);
                $report['duplicates'] += (int) ($r['duplicates'] ?? 0);
            }
        }
        $this->csvFiles = [];
        $this->importReport = $report;
        $this->importOpen = true;
        unset($this->book);
        if ($report['added'] > 0) {
            $this->notice = $report['added'].' new '.($report['added'] === 1 ? 'activity' : 'activities').' imported';
            $this->noticeKind = 'ok';
        } elseif ($report['files'] !== []) {
            $this->notice = 'CSV scanned · nothing new';
            $this->noticeKind = 'ok';
        }
    }

    public function importCsvText(string $name, string $text): void
    {
        $r = CsvImport::importText($name, $text);
        $this->importReport = [
            'files' => [$r],
            'added' => (int) ($r['added'] ?? 0),
            'duplicates' => (int) ($r['duplicates'] ?? 0),
        ];
        $this->importOpen = true;
        unset($this->book);
        if (($r['ok'] ?? false) && ($r['added'] ?? 0) > 0) {
            $this->notice = $r['added'].' new '.($r['added'] === 1 ? 'activity' : 'activities').' imported';
            $this->noticeKind = 'ok';
        } elseif (! ($r['ok'] ?? false)) {
            $this->notice = (string) ($r['error'] ?? 'Import failed');
            $this->noticeKind = 'err';
        } else {
            $this->notice = 'CSV scanned · nothing new';
            $this->noticeKind = 'ok';
        }
    }

    public function exportTradesCsv()
    {
        $csv = CsvImport::exportTradesCsv($this->book['closed'] ?? []);
        $name = 'bagholder-trades-'.now('America/Edmonton')->toDateString().'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function openFolder(): void
    {
        $this->folderOpen = true;
        $this->folderError = '';
        $st = CsvImport::watchStatus();
        $this->folderStatus = $st;
        $this->folderPath = (string) ($st['path'] ?? '');
        $this->dispatch('modal-show', name: 'load-folder');
    }

    public function closeFolder(): void
    {
        $this->folderOpen = false;
        $this->dispatch('modal-close', name: 'load-folder');
    }

    public function watchFolder(): void
    {
        $this->folderError = '';
        $set = CsvImport::setWatchFolder($this->folderPath);
        if (! ($set['ok'] ?? false)) {
            $this->folderError = (string) ($set['error'] ?? 'Could not watch that folder.');

            return;
        }
        $this->scanFolder(true);
    }

    public function scanFolder(bool $force = true): void
    {
        $this->folderError = '';
        $r = CsvImport::scanWatchFolder($force);
        if (! ($r['ok'] ?? false)) {
            $this->folderError = (string) ($r['error'] ?? 'Could not scan that folder.');
            $this->folderStatus = CsvImport::watchStatus();

            return;
        }
        $this->folderStatus = CsvImport::watchStatus();
        $this->folderPath = (string) ($this->folderStatus['path'] ?? $this->folderPath);
        unset($this->book);
        $added = (int) ($r['added'] ?? 0);
        $this->notice = $added ? $added.' new '.($added === 1 ? 'activity' : 'activities').' imported' : 'Folder scanned · nothing new';
        $this->noticeKind = 'ok';
    }

    public function stopWatch(): void
    {
        CsvImport::clearWatchFolder();
        $this->folderPath = '';
        $this->folderStatus = CsvImport::watchStatus();
        $this->folderError = '';
    }

    public function openClearData(): void
    {
        $this->dataOpen = true;
        $this->dispatch('modal-show', name: 'clear-data');
    }

    public function closeClearData(): void
    {
        $this->dataOpen = false;
        $this->dispatch('modal-close', name: 'clear-data');
    }

    public function clearData(): void
    {
        CsvImport::clearData(session: true, journal: true);
        $this->dataOpen = false;
        $this->dispatch('modal-close', name: 'clear-data');
        $this->selectedTradeId = null;
        $this->selectedLotId = null;
        $this->syncStep = '';
        $this->lastSyncedAt = '';
        $this->syncing = false;
        $this->form->reset();
        unset($this->book);
        $this->notice = 'Data cleared';
        $this->noticeKind = 'ok';
    }

    #[On('session-saved')]
    public function sessionSaved(): void
    {
        $this->connectOpen = false;
        $this->startSync();
    }


    public function selectLot(string $id): void
    {
        $this->selectedLotId = $this->selectedLotId === $id ? null : $id;
        if ($this->selectedLotId) {
            $this->loadJournalDraft($this->selectedPosition());
        }
    }

    public function selectedPosition(): ?array
    {
        if ($this->selectedLotId === null) {
            return null;
        }
        foreach ($this->book['positions'] ?? [] as $row) {
            if (($row['id'] ?? '') === $this->selectedLotId) {
                return $row;
            }
        }

        return null;
    }

    public function setChartTf(string $tf): void
    {
        $allowed = array_column(\App\Journal\Charts::TRADE_TFS, 0);
        if (in_array($tf, $allowed, true)) {
            $this->chartTf = $tf;
        }
    }

    /**
     * Synced price bars for the selected trade + TF, if any. Empty when Meta has
     * no history for the symbol (honest empty chart frame).
     */
    public function tradePriceBars(): array
    {
        $trade = $this->selectedTrade();
        if (! $trade) {
            return [];
        }
        $sym = (string) ($trade['symbol'] ?? '');
        if ($sym === '') {
            return [];
        }
        $entry = substr((string) ($trade['entryDate'] ?? ''), 0, 10);
        $exit = substr((string) ($trade['exitDate'] ?? ''), 0, 10);
        $startTs = $entry !== '' ? (strtotime($entry.' UTC') - 86400 * 5) : null;
        $endTs = $exit !== '' ? (strtotime($exit.' UTC') + 86400 * 5) : null;
        $tfs = array_values(array_unique(array_merge([$this->chartTf], ['1h', '4h', '1d', '1w', '1M'])));
        foreach ($tfs as $tf) {
            $bars = \App\Journal\MarketImport::barsFor($sym, $tf, $startTs ?: null, $endTs ?: null);
            if ($bars !== []) {
                return $bars;
            }
        }
        // Window miss (imported bars outside trade span): show available history for symbol.
        foreach ($tfs as $tf) {
            $bars = \App\Journal\MarketImport::barsFor($sym, $tf, null, null);
            if ($bars !== []) {
                return $bars;
            }
        }

        return [];
    }

    public function pxOrDash(mixed $n): string
    {
        if ($n === null || ! is_numeric($n)) {
            return '—';
        }

        return number_format((float) $n, 2, '.', ',');
    }

    public function moneyOrDash(mixed $n): string
    {
        if ($n === null || ! is_numeric($n)) {
            return '—';
        }

        return \App\Support\Money::formatCad($n, 0);
    }

    public function pctPlain(?float $n, int $digits = 2): string
    {
        if ($n === null || ! is_finite($n)) {
            return '—';
        }

        return number_format($n * 100, $digits).'%';
    }

    public function money0(mixed $n): string
    {
        return \App\Support\Money::formatCad($n, 0);
    }

    #[Renderless]
    public function setView(string $view): void
    {
        if (! in_array($view, ['dashboard', 'trades', 'positions', 'cashflow'], true)) {
            return;
        }
        $this->view = $view;
        if ($view === 'trades') {
            $this->tab = 'closed';
            $this->selectedLotId = null;
        } elseif ($view === 'positions') {
            $this->tab = 'open';
        } elseif ($view === 'cashflow') {
            $this->tab = 'activity';
            $this->selectedLotId = null;
        } elseif ($view === 'dashboard') {
            $this->selectedLotId = null;
        }
    }

    public function openFilters(): void
    {
        $this->modal('filters')->show();
        $this->js('queueMicrotask(() => { const el = document.querySelector(\'#bh-filter-search\'); if (el) el.focus(); })');
    }

    public function setBenchmark(string $symbol): void
    {
        $allowed = ['SP500', 'TSX', 'TSX60'];
        if (! in_array($symbol, $allowed, true)) {
            return;
        }
        $series = \App\Journal\MarketImport::benchmarkSeries($symbol === 'SP500' ? 'SP500' : $symbol);
        if ($symbol !== 'SP500' && $series === []) {
            // Keep pill, do not fake series.
            return;
        }
        $this->benchmark = $symbol;
        Meta::putValue('active_benchmark', $symbol);
        if ($symbol === 'SP500') {
            // spy_by_date already holds SP500 from import / seed.
        } else {
            // Snapshot::load swaps Spy boot source via active_benchmark.
        }
        BookCache::flush();
        unset($this->book);
    }

    public function hasBenchmark(string $symbol): bool
    {
        if ($symbol === 'SP500') {
            return true;
        }

        return \App\Journal\MarketImport::benchmarkSeries($symbol) !== [];
    }

    public function loadJournalDraft(?array $row): void
    {
        $this->draftThesis = (string) ($row['thesis'] ?? $row['notes'] ?? '');
        $this->draftGrade = (string) ($row['grade'] ?? '');
        $this->draftTags = (string) ($row['tag'] ?? '');
    }

    public function saveJournal(?string $id = null): void
    {
        $id = $id ?: $this->selectedTradeId ?: $this->selectedLotId;
        if (! $id) {
            return;
        }
        $notes = Meta::json('trade_notes', []);
        $grade = strtoupper(trim($this->draftGrade));
        if (! in_array($grade, ['A', 'B', 'C', 'F', ''], true)) {
            $grade = '';
        }
        $thesis = trim($this->draftThesis);
        $tagsRaw = trim($this->draftTags);
        $tags = $tagsRaw === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $tagsRaw) ?: [])));
        if ($thesis === '' && $grade === '' && $tags === []) {
            unset($notes[$id]);
        } else {
            $notes[$id] = [
                'thesis' => $thesis,
                'grade' => $grade,
                'tags' => $tags,
                'tag' => implode(', ', $tags),
                'tradeId' => $id,
            ];
        }
        Meta::putValue('trade_notes', json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        BookCache::flush();
        unset($this->book);
    }

    public function sortedPositions(): array
    {
        $rows = $this->book['positions'] ?? [];
        $key = $this->posSortKey;
        $dir = $this->posSortDir;

        return \App\Journal\Filters::sortRows($rows, function ($p) use ($key) {
            $map = [
                'symbol' => $p['displaySymbol'] ?? $p['symbol'] ?? '',
                'qty' => $p['qty'] ?? 0,
                'avg' => $p['avg'] ?? 0,
                'last' => $p['last'] ?? -INF,
                'currency' => $p['currency'] ?? '',
                'cost' => $p['cost'] ?? 0,
                'mv' => $p['mv'] ?? -INF,
                'unreal' => $p['unreal'] ?? -INF,
                'dayChange' => $p['dayChange'] ?? -INF,
                'dayPct' => $p['dayPct'] ?? -INF,
                'held' => $p['held'] ?? 0,
                'alloc' => $p['alloc'] ?? 0,
            ];

            return $map[$key] ?? null;
        }, $dir);
    }

    public function sortedBySymbol(): array
    {
        $rows = $this->book['bySymbol'] ?? [];
        $key = $this->bySymbolSortKey;
        $dir = $this->bySymbolSortDir;

        return \App\Journal\Filters::sortRows($rows, function ($r) use ($key) {
            $map = [
                'symbol' => $r['label'] ?? $r['key'] ?? '',
                'pnlCad' => $r['pnlCad'] ?? 0,
                'trades' => $r['trades'] ?? 0,
                'winRate' => $r['winRate'] ?? 0,
                'avgHold' => $r['avgHold'] ?? 0,
            ];

            return $map[$key] ?? null;
        }, $dir);
    }

    public function sortedCfHoldings(): array
    {
        $rows = $this->book['cashflow']['holdings'] ?? [];
        $key = $this->cfHoldSortKey;
        $dir = $this->cfHoldSortDir;

        return \App\Journal\Filters::sortRows($rows, function ($h) use ($key) {
            $map = [
                'symbol' => $h['symbol'] ?? '',
                'qty' => $h['qty'] ?? 0,
                'avg' => $h['avg'] ?? 0,
                'cost' => $h['cost'] ?? 0,
                'mv' => $h['mv'] ?? -INF,
                'per' => $h['per'] ?? -INF,
                'ytd' => $h['ytd'] ?? 0,
                'ttm' => $h['ttm'] ?? 0,
                'all' => $h['all'] ?? 0,
                'nextExDate' => $h['nextExDate'] ?? '',
                'nextPayDate' => $h['nextPayDate'] ?? '',
                'annual' => $h['annual'] ?? -INF,
                'yoc' => $h['yoc'] ?? -INF,
                'currentYield' => $h['currentYield'] ?? -INF,
            ];

            return $map[$key] ?? null;
        }, $dir);
    }

    public function sortedCfHistory(): array
    {
        $rows = $this->book['cashflow']['rows'] ?? [];
        $key = $this->cfHistSortKey;
        $dir = $this->cfHistSortDir;

        return \App\Journal\Filters::sortRows($rows, function ($r) use ($key) {
            $map = [
                'date' => $r['date'] ?? '',
                'symbol' => $r['symbol'] ?? '',
                'kind' => $r['kind'] ?? '',
                'account' => $r['account'] ?? '',
                'qty' => $r['qty'] ?? 0,
                'per' => $r['per'] ?? 0,
                'amount' => $r['amount'] ?? 0,
            ];

            return $map[$key] ?? null;
        }, $dir);
    }

    public function statusLabel(): string
    {
        $this->benchmark = (string) Meta::getValue('active_benchmark', 'SP500') ?: 'SP500';
        if ($this->capturing) {
            return 'Waiting for Wealthsimple login…';
        }
        $canceledAt = (int) Meta::getValue('ws_signin_canceled_at', '0');
        if ($canceledAt > 0 && (time() - $canceledAt) < 8) {
            return 'Sign-in canceled';
        }
        if ($this->syncing) {
            return $this->syncStep !== '' ? $this->syncStep : 'Checking for new rows…';
        }
        if (! $this->connected()) {
            return \App\Models\Activity::query()->exists() ? 'Demo data' : 'Not connected';
        }
        $at = $this->lastSyncedAt;
        // Desktop syncLine: connected with no lastSync → "Synced —" (not "Not connected").
        if ($at === '') {
            return 'Synced —';
        }
        $ts = strtotime($at);
        if ($ts === false) {
            return 'Synced just now';
        }
        $sec = max(0, time() - $ts);
        if ($sec < 45) {
            return 'Synced just now';
        }
        if ($sec < 120) {
            return 'Synced 1 min ago';
        }
        if ($sec < 3600) {
            return 'Synced '.((int) round($sec / 60)).' min ago';
        }

        return 'Synced '.((int) round($sec / 3600)).'h ago';
    }

    public function connected(): bool
    {
        return SessionStore::connected() || SessionStore::hasRefresh();
    }

    public function formatCad(mixed $n): string
    {
        return Money::formatCad($n);
    }

    public function qty(mixed $n): string
    {
        $n = (float) $n;
        $digits = abs($n - round($n)) > 0.0001 ? 4 : 0;

        return number_format($n, $digits, '.', ',');
    }

    public function px(mixed $n): string
    {
        if (! is_numeric($n) || (float) $n == 0.0) {
            return '';
        }

        return number_format((float) $n, 2, '.', ',');
    }

    public function symbolOptions(): array
    {
        $q = strtoupper(trim($this->form->symbolQuery));
        $all = $this->book['symbols'] ?? [];
        if ($q === '') {
            return $all;
        }

        return array_values(array_filter($all, fn ($s) => str_contains(strtoupper((string) $s), $q)));
    }
};
?>

<div
    @if($syncing) wire:poll.750ms="tickSync" @elseif($capturing) wire:poll.1500ms="tickCapture" @else wire:poll.60s="tickQuotes" @endif
    class="bh-mat"
    x-data="{ bhView: @js($view) }"
    tabindex="0"
>

@php
        $book = $this->book;
        $trade = $this->selectedTrade();
        $appVersion = trim((string) config('app.version', '0.1.0'));
    @endphp

    <div class="bh-panel">
    <div class="bh-hdr">
        <a href="/" wire:navigate class="no-underline flex items-baseline gap-1">
            <span class="bh-wordmark">Bagholder</span>
            <span class="bh-ver">v{{ $appVersion }}</span>
        </a>
        <flux:spacer />
        <div class="flex items-center gap-2">
            <span class="bh-status{{ $noticeKind === 'err' ? ' status-err' : '' }}">{{ $notice !== '' ? $notice : $this->statusLabel() }}</span>

            @php $odOpen = $this->openOrdersCount(); @endphp
            <flux:button
                variant="ghost"
                icon="receipt-percent"
                size="sm"
                aria-label="Orders"
                class="relative size-9"
                wire:click="toggleOrders"
                title="Orders (⌘O)"
            >
                @if ($odOpen > 0)
                    <span class="od-badge">{{ $odOpen }}</span>
                @endif
            </flux:button>

            <flux:modal.trigger name="filters" shortcut="meta.k,ctrl.k">
                <flux:button variant="ghost" icon="funnel" size="sm" aria-label="Filters" class="relative size-9">
                    @if ($this->form->active())
                        <span class="absolute top-1.5 right-1.5 size-2 rounded-full" style="background: var(--bh-pos)"></span>
                    @endif
                </flux:button>
            </flux:modal.trigger>

            {{-- Desktop menuHtml: always show ⋯; Connect Wealthsimple primary when disconnected; Sync/Refresh when sessioned --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button variant="ghost" icon="ellipsis-horizontal" size="sm" aria-label="Menu" class="size-9" />
                <flux:menu>
                    @if ($this->connected())
                        <flux:menu.item icon="arrow-path" wire:click.async="startSync" class="font-semibold">Sync now</flux:menu.item>
                        <flux:menu.item icon="key" wire:click="refreshSession">Refresh session</flux:menu.item>
                        <flux:menu.item icon="ticket" wire:click="openTicket('QNC', 'BUY')">Order ticket · ⇧⌘K</flux:menu.item>
                        <flux:menu.separator />
                    @else
                        <flux:menu.item icon="link" wire:click="openConnect" class="font-semibold">Connect Wealthsimple</flux:menu.item>
                        <flux:menu.separator />
                    @endif
                    <flux:menu.item icon="plus" wire:click="openAddTrade">Add trade</flux:menu.item>
                    <flux:menu.item icon="arrow-down-tray" wire:click="openImportCsv">Import CSV</flux:menu.item>
                    <flux:menu.item icon="folder" wire:click="openFolder">Load folder</flux:menu.item>
                    <flux:menu.item icon="arrow-up-tray" wire:click="exportTradesCsv">Export trades CSV</flux:menu.item>
                    <flux:menu.item icon="trash" wire:click="openClearData">Clear data</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item wire:click="setTheme('nocturne')" x-on:click="document.documentElement.setAttribute('data-bh-theme','nocturne'); localStorage.setItem('bh2.theme','nocturne')" class="{{ $theme === 'nocturne' ? 'font-semibold' : '' }}">Theme · Nocturne</flux:menu.item>
                    <flux:menu.item wire:click="setTheme('midnight')" x-on:click="document.documentElement.setAttribute('data-bh-theme','midnight'); localStorage.setItem('bh2.theme','midnight')" class="{{ $theme === 'midnight' ? 'font-semibold' : '' }}">Theme · Midnight</flux:menu.item>
                    <flux:menu.item wire:click="setTheme('light')" x-on:click="document.documentElement.setAttribute('data-bh-theme','light'); localStorage.setItem('bh2.theme','light')" class="{{ $theme === 'light' ? 'font-semibold' : '' }}">Theme · Light</flux:menu.item>
                    <flux:menu.separator />
                    @if ($this->connected())
                        <flux:menu.item variant="danger" icon="arrow-right-start-on-rectangle" wire:click="disconnect">Logout</flux:menu.item>
                    @else
                        <flux:menu.item variant="danger" icon="arrow-right-start-on-rectangle" disabled>Logout</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <nav class="bh-tabs" aria-label="Primary">
        <button type="button" class="bh-tab" :class="bhView === 'dashboard' && 'is-active'" :aria-current="bhView === 'dashboard' ? 'page' : null" x-on:click="bhView = 'dashboard'; $wire.setView('dashboard')">Dashboard</button>
        <button type="button" class="bh-tab" :class="bhView === 'trades' && 'is-active'" :aria-current="bhView === 'trades' ? 'page' : null" x-on:click="bhView = 'trades'; $wire.setView('trades')">Trades</button>
        <button type="button" class="bh-tab" :class="bhView === 'positions' && 'is-active'" :aria-current="bhView === 'positions' ? 'page' : null" x-on:click="bhView = 'positions'; $wire.setView('positions')">Positions</button>
        <button type="button" class="bh-tab" :class="bhView === 'cashflow' && 'is-active'" :aria-current="bhView === 'cashflow' ? 'page' : null" x-on:click="bhView = 'cashflow'; $wire.setView('cashflow')">Cashflow</button>
    </nav>

    @php $chips = $this->activeFilterChips(); @endphp
    @if ($chips !== [])
        <div class="bh-chips" aria-label="Active filters">
            @foreach ($chips as $chip)
                <span class="bh-chip">
                    <span class="bh-chip-f">{{ $chip['field'] }}</span>
                    <button type="button" class="bh-chip-v" wire:click="openFilters">{{ $chip['value'] }}</button>
                    <button type="button" class="bh-chip-x" wire:click="clearFilter({{ \Illuminate\Support\Js::from($chip['key']) }})" aria-label="Remove filter">×</button>
                </span>
            @endforeach
        </div>
    @endif

    <div class="bh-page">
        @php
            $activityCount = (int) ($book['activityCount'] ?? count($book['activities'] ?? []));
        @endphp

        @if ($activityCount === 0)
            {{-- Desktop emptyHtml: No activity yet + Connect / Sync CTAs --}}
            <div class="empty" style="min-height:60vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:40px 20px">
                <div style="font-size:16px;font-weight:500">
                    @if ($this->connected() && $syncing)
                        Pulling your history
                    @else
                        No activity yet
                    @endif
                </div>
                <div class="bh-dim" style="font-size:12.5px;max-width:420px;line-height:1.5">
                    @if (! $this->connected())
                        Connect your Wealthsimple account and Bagholder will pull your full history, then keep it up to date every weekday after the close. Nothing leaves this machine.
                    @elseif ($syncing)
                        Your full Wealthsimple history is on its way. The first sync can take a minute.
                    @else
                        Connected to Wealthsimple, but no activity has come back yet.
                    @endif
                </div>
                @if (! $this->connected())
                    <flux:button variant="primary" wire:click="openConnect">Connect Wealthsimple</flux:button>
                @elseif (! $syncing)
                    <flux:button variant="primary" wire:click.async="startSync">Sync now</flux:button>
                @endif
                @if ($syncError !== '')
                    <div class="status-err" style="font-size:12px">{{ $syncError }}</div>
                @endif
            </div>
        @else
            @if (! $this->connected())
                <flux:callout icon="inbox" heading="Demo data">
                    <flux:callout.text>
                        You're viewing demo journal data. Connect a Wealthsimple session to sync your real trades.
                    </flux:callout.text>
                </flux:callout>
            @endif

        <div data-bh-page="dashboard" x-show="bhView === 'dashboard'" x-cloak>
        @php
            $m = $this->book['metrics'];
            $cf = $this->book['cashflow'] ?? ['tiles'=>[], 'months'=>[], 'holdings'=>[], 'rows'=>[], 'skippedFilters'=>[]];
            $grades = $this->book['grades'] ?? ['buckets'=>[], 'graded'=>0];
            $queue = $this->book['queue'] ?? [];
            $compare = $this->book['compare'] ?? [];
            $ann = $this->book['annual'] ?? [];
            $peakGrade = max(array_map(fn ($b) => abs($b['pnl'] ?? 0), $grades['buckets'] ?? []) ?: [1]);
            $beat = 0; $compN = 0;
            foreach ($compare as $yr) {
                if (($yr['spy'] ?? null) === null) continue;
                $compN++;
                if (($yr['mine'] ?? 0) > ($yr['spy'] ?? 0)) $beat++;
            }
        @endphp
        <div class="bh-kpi-grid">
            <x-stat-card label="Realized P&L" :value="$this->formatCad($m['realizedPnlCad'])" :sub="number_format($m['tradeCount']).($m['tradeCount']===1?' trade':' trades')" :cls="\App\Support\Money::pnlClass($m['realizedPnlCad'])" />
            <x-stat-card label="Win rate" :value="\App\Support\Money::formatPct($m['winRate'])" :sub="\App\Journal\Metrics::winRateSubtitle($m)" />
            <x-stat-card label="Profit factor" :value="\App\Journal\Metrics::profitFactorLabel($m)" :sub="\App\Journal\Metrics::profitFactorSubtitle($m)" />
            <x-stat-card label="Expectancy" :value="$this->formatCad($m['expectancy'])" :sub="'avg W '.$this->formatCad($m['avgWin']).' · avg L '.$this->formatCad($m['avgLoss'])" :cls="\App\Support\Money::pnlClass($m['expectancy'])" />
            <x-stat-card label="Max drawdown" :value="$this->formatCad($m['maxDrawdown'])" sub="Peak to trough on realized curve" cls="neg" />
            <x-stat-card label="Avg annualized" :value="\App\Support\Money::formatReturn($ann['rate'] ?? null)" :sub="!empty($ann['from']) ? ($ann['from'].' → '.$ann['to']) : '—'" :cls="\App\Support\Money::pnlClass($ann['rate'] ?? 0)" />
        </div>

        <div class="bh-dash-row">
            <div class="bh-card">
                <h5 class="bh-h5">Equity curve</h5>
                <div class="bh-muted" style="margin-top:4px">{{ \App\Journal\Filters::listingFiltersOn($this->form->payload()) ? 'Realized P&L (filtered)' : 'Wealthsimple daily NAV' }}</div>
                <div class="chart-wrap" style="margin-top:12px">
                    @php
                        $eqCurve = $this->book['curve'] ?? [];
                        $eqFlux = [];
                        foreach ($eqCurve as $pt) {
                            $eqFlux[] = [
                                'date' => (string) ($pt['date'] ?? ''),
                                'equity' => round((float) ($pt['equity'] ?? 0), 2),
                            ];
                        }
                        $eqUp = $eqFlux !== [] && ((float) ($eqFlux[array_key_last($eqFlux)]['equity'] ?? 0)) >= ((float) ($eqFlux[0]['equity'] ?? 0));
                    @endphp
                    @if ($eqFlux === [])
                        {!! \App\Journal\Charts::equity([]) !!}
                    @else
                        <flux:chart :value="$eqFlux" class="w-full" style="height:220px">
                            <flux:chart.svg>
                                <flux:chart.area field="equity" class="{{ $eqUp ? 'text-emerald-500/25' : 'text-rose-500/25' }}" />
                                <flux:chart.line field="equity" class="{{ $eqUp ? 'text-emerald-400' : 'text-rose-400' }}" />
                                <flux:chart.axis axis="x" field="date">
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>
                                <flux:chart.axis axis="y">
                                    <flux:chart.axis.grid class="stroke-zinc-700" />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>
                                <flux:chart.cursor />
                            </flux:chart.svg>
                            <flux:chart.tooltip>
                                <flux:chart.tooltip.heading field="date" />
                                <flux:chart.tooltip.value field="equity" label="Equity" />
                            </flux:chart.tooltip>
                        </flux:chart>
                    @endif
                </div>
            </div>
            <div class="bh-card" style="display:flex;flex-direction:column;min-height:0;contain:size">
                <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px">
                    <h5 class="bh-h5">Annualized returns</h5>
                    <div style="display:flex;gap:4px">
                        <button type="button" class="bh-pill {{ $benchmark === 'SP500' ? 'on' : '' }}" wire:click="setBenchmark('SP500')">S&amp;P 500</button>
                        <button type="button" class="bh-pill {{ $benchmark === 'TSX' ? 'on' : '' }}" @disabled(! $this->hasBenchmark('TSX')) title="{{ $this->hasBenchmark('TSX') ? 'S&amp;P/TSX composite' : 'Benchmark series not synced yet' }}" wire:click="setBenchmark('TSX')">S&amp;P/TSX</button>
                        <button type="button" class="bh-pill {{ $benchmark === 'TSX60' ? 'on' : '' }}" @disabled(! $this->hasBenchmark('TSX60')) title="{{ $this->hasBenchmark('TSX60') ? 'TSX 60' : 'Benchmark series not synced yet' }}" wire:click="setBenchmark('TSX60')">TSX 60</button>
                    </div>
                </div>
                <div class="bh-muted" style="margin:4px 0 12px">Vs {{ $benchmark === 'TSX' ? 'S&amp;P/TSX' : ($benchmark === 'TSX60' ? 'TSX 60' : 'S&amp;P 500') }}</div>
                <div class="bh-scroll" style="display:flex;flex-direction:column;gap:14px">
                    @forelse (array_reverse($compare) as $row)
                        @php
                            $mine = $row['mine'] ?? null;
                            $spy = $row['spy'] ?? null;
                            $scale = max(abs((float)($mine ?? 0)), abs((float)($spy ?? 0)), 0.01);
                        @endphp
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
                                <span>{{ $row['year'] }}</span>
                                <span class="{{ \App\Support\Money::pnlClass($mine ?? 0) }}">{{ \App\Support\Money::formatReturn($mine) }}<span class="bh-dim"> / {{ $spy === null ? '—' : \App\Support\Money::formatReturn($spy) }}</span></span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:3px">
                                <div style="height:7px;width:{{ max(2, abs((float)($mine ?? 0)) / $scale * 100) }}%;background:{{ (($mine ?? 0) >= 0) ? 'var(--bh-pos)' : 'var(--bh-neg)' }};border-radius:2px"></div>
                                <div style="height:7px;width:{{ max(2, abs((float)($spy ?? 0)) / $scale * 100) }}%;background:var(--bh-mixed);border-radius:2px"></div>
                            </div>
                        </div>
                    @empty
                        <div class="bh-muted">No complete years yet.</div>
                    @endforelse
                </div>
                <div class="bh-muted" style="margin-top:auto;padding-top:12px;border-top:1px solid var(--bh-hair);font-size:10px">
                    {{ $compN ? ('Outperformed S&P 500 in '.$beat.' of '.$compN.($compN===1?' year.':' years.')) : 'No NAV history yet.' }}
                </div>
            </div>
        </div>

        <div class="bh-dash-row">
            <div class="bh-card">
                <h5 class="bh-h5">Monthly P&amp;L</h5>
                <div class="bh-muted" style="margin-top:4px">One row per close — same as win rate and profit factor.</div>
                <div class="chart-wrap" style="margin-top:12px">
                    @php
                        $moRows = $this->book['monthly'] ?? [];
                        $moFlux = [];
                        foreach ($moRows as $r) {
                            $pnl = (float) ($r['pnl'] ?? 0);
                            $moFlux[] = [
                                'month' => \App\Journal\Charts::monthTick((string) ($r['month'] ?? '')),
                                'pos' => $pnl > 0 ? round($pnl, 2) : 0,
                                'neg' => $pnl < 0 ? round($pnl, 2) : 0,
                                'pnl' => round($pnl, 2),
                            ];
                        }
                    @endphp
                    @if ($moFlux === [])
                        {!! \App\Journal\Charts::monthly([]) !!}
                    @else
                        <flux:chart :value="$moFlux" class="w-full" style="height:220px">
                            <flux:chart.svg>
                                <flux:chart.bar field="pos" class="text-emerald-400" radius="2" />
                                <flux:chart.bar field="neg" class="text-rose-400" radius="2" />
                                <flux:chart.zero-line class="stroke-zinc-600" />
                                <flux:chart.axis axis="x" field="month">
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>
                                <flux:chart.axis axis="y">
                                    <flux:chart.axis.grid class="stroke-zinc-700" />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>
                                <flux:chart.cursor />
                            </flux:chart.svg>
                            <flux:chart.tooltip>
                                <flux:chart.tooltip.heading field="month" />
                                <flux:chart.tooltip.value field="pnl" label="P&amp;L" />
                            </flux:chart.tooltip>
                        </flux:chart>
                    @endif
                </div>
            </div>
            <div class="bh-card" style="display:flex;flex-direction:column">
                <h5 class="bh-h5">Grade vs P&amp;L</h5>
                <div class="bh-muted" style="margin:4px 0 14px">Realized P&amp;L by the grade you gave the trade</div>
                @if (empty($grades['graded']))
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;min-height:120px">
                        <div style="font-size:13px">No trades graded yet</div>
                        <div class="bh-dim" style="font-size:11px">{{ number_format($m['tradeCount']) }} closed trades to review</div>
                        <button type="button" class="bh-pill on" style="margin-top:4px" x-on:click="bhView = 'trades'; $wire.setView('trades')">Open Trades</button>
                    </div>
                @else
                    <div style="flex:1;min-height:120px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end">
                        @foreach ($grades['buckets'] as $b)
                            <div
                                class="bh-bar-col{{ empty($b['n']) ? ' is-empty' : '' }}"
                                style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px;height:100%"
                                @if (! empty($b['n']))
                                    wire:click="gradeOpen({{ \Illuminate\Support\Js::from($b['grade']) }})"
                                    title="Open {{ $b['grade'] }} trades"
                                @endif
                            >
                                <span style="font-size:11px;text-align:center" class="{{ \App\Support\Money::pnlClass($b['pnl']) }}">{{ $b['n'] ? $this->money0($b['pnl']) : '—' }}</span>
                                <div style="height:{{ $b['n'] ? max(3, abs($b['pnl']) / max($peakGrade,1) * 100) : 0 }}%;background:{{ ($b['pnl']??0) >= 0 ? 'var(--bh-pos)' : 'var(--bh-neg)' }};border-radius:3px 3px 0 0"></div>
                            </div>
                        @endforeach
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:6px;font-size:10px;color:var(--bh-ink55);text-align:center">
                        @foreach ($grades['buckets'] as $b)
                            <span>{{ $b['grade'] }} · {{ $b['n'] }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="bh-dash-row bh-dash-bottom">
            <div class="bh-card">
                <h5 class="bh-h5" style="margin-bottom:6px">By symbol</h5>
                <div class="bh-scroll">
                    <table class="bh-table">
                        <thead>
                            <tr>
                                <x-sort-th tab="bySymbol" col="symbol" label="Symbol" :active-key="$bySymbolSortKey" :active-dir="$bySymbolSortDir" />
                                <x-sort-th tab="bySymbol" col="pnlCad" label="P&L" align="right" :active-key="$bySymbolSortKey" :active-dir="$bySymbolSortDir" />
                                <x-sort-th tab="bySymbol" col="trades" label="Trades" align="right" :active-key="$bySymbolSortKey" :active-dir="$bySymbolSortDir" />
                                <x-sort-th tab="bySymbol" col="winRate" label="Win rate" align="right" :active-key="$bySymbolSortKey" :active-dir="$bySymbolSortDir" />
                                <x-sort-th tab="bySymbol" col="avgHold" label="Avg hold" align="right" :active-key="$bySymbolSortKey" :active-dir="$bySymbolSortDir" />
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->sortedBySymbol() as $row)
                                <tr class="bh-row" wire:click="setSymbol({{ \Illuminate\Support\Js::from($row['key']) }})">
                                    <td style="font-weight:500">{{ $row['label'] }}</td>
                                    <td class="r {{ \App\Support\Money::pnlClass($row['pnlCad']) }}">{{ $this->formatCad($row['pnlCad']) }}</td>
                                    <td class="r">{{ $row['trades'] }}</td>
                                    <td class="r">{{ \App\Support\Money::formatPct($row['winRate']) }}</td>
                                    <td class="r bh-dim">{{ \App\Support\Money::formatHold($row['avgHold'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="bh-dim">None yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="bh-card">
                <h5 class="bh-h5">Review queue</h5>
                <div class="bh-muted" style="margin:4px 0 12px">Closed trades with no grade or thesis</div>
                <div class="bh-scroll" style="display:flex;flex-direction:column;gap:0;min-height:0">
                    @forelse ($queue as $r)
                        <div class="bh-queue-row" wire:click="openExecutions({{ \Illuminate\Support\Js::from($r['id']) }})">
                            <div style="min-width:0">
                                <div style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px">{{ $r['symbol'] }}</div>
                                <div class="bh-muted">{{ $r['date'] }} · {{ $r['missing'] }}</div>
                            </div>
                            <span class="{{ \App\Support\Money::pnlClass($r['pnl']) }}" style="margin-left:auto;font-size:12px">{{ $this->formatCad($r['pnl']) }}</span>
                        </div>
                    @empty
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;text-align:center;padding:8px 0">
                            <svg width="20" height="20" viewBox="0 0 256 256" style="fill:var(--bh-pos)" aria-hidden="true"><path d="M232.5 82.5l-128 128a12 12 0 0 1-17 0l-56-56a12 12 0 0 1 17-17L96 185l119.5-119.5a12 12 0 0 1 17 17Z"/></svg>
                            <div style="font-size:13px">Nothing left to review</div>
                            <div class="bh-dim" style="font-size:11px">Every closed trade has a grade and a thesis</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
        </div>{{-- dashboard --}}

        <div data-bh-page="trades-pills" x-show="bhView === 'trades'" x-cloak class="flex items-center" style="gap:8px">
            <button type="button" class="bh-pill {{ $tab === 'closed' ? 'on' : '' }}" wire:click="$set('tab', 'closed')">Closed trades</button>
            <button type="button" class="bh-pill {{ $tab === 'open' ? 'on' : '' }}" wire:click="$set('tab', 'open')">Open lots</button>
            <button type="button" class="bh-pill {{ $tab === 'activity' ? 'on' : '' }}" wire:click="$set('tab', 'activity')">Activity</button>
        </div>

        <div data-bh-page="positions" x-show="bhView === 'positions'" x-cloak>
                @php
                    $selPos = $this->selectedPosition();
                    $pf = $this->book['portfolio'] ?? [
                        'allocation' => [], 'marketValue' => 0, 'sectors' => [], 'regions' => [],
                        'nav' => null, 'navAccounts' => 0, 'costBasis' => 0, 'cash' => 0, 'cashPct' => null,
                        'hasMargin' => false, 'marginUsed' => 0, 'marginUsedPct' => null,
                        'availableMargin' => null, 'availableMarginUnavailable' => [],
                        'unrealized' => 0, 'unrealizedPct' => null, 'positionCount' => 0,
                        'dayChange' => null, 'dayChangePct' => null,
                    ];
                    $nPos = (int) ($pf['positionCount'] ?? count($this->book['positions'] ?? []));
                    $posWord = $nPos === 1 ? 'position' : 'positions';
                    $openPosSub = 'across '.$nPos.' open '.$posWord;
                    $navSub = (($pf['nav'] ?? null) === null)
                        ? '—'
                        : ((int) ($pf['navAccounts'] ?? 0)).(((int) ($pf['navAccounts'] ?? 0)) === 1 ? ' account, ' : ' accounts, ').$nPos.' '.$posWord;
                    $unavail = $pf['availableMarginUnavailable'] ?? [];
                    $availSub = $unavail !== []
                        ? ('unavailable for '.implode(', ', $unavail))
                        : ((($pf['availableMargin'] ?? null) === null) ? '—' : 'buying power');
                    $pfTiles = [];
                    $pfTiles[] = ['label' => 'Market value', 'value' => $this->money0($pf['marketValue'] ?? 0), 'sub' => $openPosSub, 'cls' => ''];
                    $pfTiles[] = ['label' => 'Net asset value', 'value' => (($pf['nav'] ?? null) === null ? '—' : $this->money0($pf['nav'])), 'sub' => $navSub, 'cls' => ''];
                    $pfTiles[] = ['label' => 'Cost basis', 'value' => $this->money0($pf['costBasis'] ?? 0), 'sub' => 'Total book value', 'cls' => ''];
                    if (! empty($pf['hasMargin'])) {
                        $pfTiles[] = [
                            'label' => 'Margin used',
                            'value' => $this->money0($pf['marginUsed'] ?? 0),
                            'sub' => (($pf['marginUsedPct'] ?? null) === null ? '—' : $this->pctPlain($pf['marginUsedPct']).' of market value'),
                            'cls' => '',
                        ];
                        $pfTiles[] = [
                            'label' => 'Available margin',
                            'value' => (($pf['availableMargin'] ?? null) === null ? '—' : $this->money0($pf['availableMargin'])),
                            'sub' => $availSub,
                            'cls' => '',
                        ];
                    } else {
                        $pfTiles[] = [
                            'label' => 'Cash',
                            'value' => $this->money0($pf['cash'] ?? 0),
                            'sub' => (($pf['cashPct'] ?? null) === null ? '—' : $this->pctPlain($pf['cashPct']).' of net asset value'),
                            'cls' => '',
                        ];
                        $pfTiles[] = [
                            'label' => 'Day change',
                            'value' => (($pf['dayChange'] ?? null) === null ? '—' : \App\Support\Money::signedCad($pf['dayChange'])),
                            'sub' => (($pf['dayChangePct'] ?? null) === null ? '—' : \App\Support\Money::signedPct($pf['dayChangePct'], 2).' today'),
                            'cls' => (($pf['dayChange'] ?? null) === null ? '' : \App\Support\Money::pnlClass((float) $pf['dayChange'])),
                        ];
                    }
                    $pfTiles[] = [
                        'label' => 'Unrealized P&L',
                        'value' => \App\Support\Money::signedCad($pf['unrealized'] ?? 0),
                        'sub' => (($pf['unrealizedPct'] ?? null) === null ? '—' : \App\Support\Money::signedPct($pf['unrealizedPct'], 2).(((float) ($pf['unrealized'] ?? 0)) >= 0 ? ' gain' : ' loss')),
                        'cls' => \App\Support\Money::pnlClass((float) ($pf['unrealized'] ?? 0)),
                    ];
                @endphp
                <div class="bh-kpi-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:14px">
                    @foreach ($pfTiles as $tile)
                        <div class="bh-kpi">
                            <div class="bh-kpi-lbl">{{ $tile['label'] }}</div>
                            <div class="bh-kpi-val {{ $tile['cls'] }}">{{ $tile['value'] }}</div>
                            <div class="bh-kpi-sub">{{ $tile['sub'] }}</div>
                        </div>
                    @endforeach
                </div>
                {{-- Row 1: Allocation (market-value donut) + Holdings — desktop portfolioHtml --}}
                <div style="display:grid;grid-template-columns:minmax(260px,0.9fr) minmax(0,1.6fr);gap:14px;align-items:stretch">
                    {!! \App\Journal\Charts::portfolioAllocationCard($pf['allocation'] ?? [], (float) ($pf['marketValue'] ?? 0)) !!}
                    <div class="bh-split" style="min-height:0">
                        <div class="bh-card bh-split-main" style="display:flex;flex-direction:column;min-height:0;padding:14px 16px 8px">
                            <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:8px">
                                <h5 class="bh-h5" style="margin:0">Holdings</h5>
                                <span class="bh-dim" style="font-weight:400;font-size:12px">{{ count($this->book['positions'] ?? []) }}</span>
                            </div>
                            <div class="bh-scroll" style="margin-top:0;overflow:auto;max-height:362px">
                                <table class="bh-table bh-table-wide" style="min-width:920px">
                                    <thead>
                                        <tr>
                                            <x-sort-th tab="positions" col="symbol" label="Symbol" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="avg" label="Avg" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="last" label="Last" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="cost" label="Book" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="mv" label="Market" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="dayChange" label="Change ($)" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="dayPct" label="Change (%)" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                            <x-sort-th tab="positions" col="unreal" label="Unrealized P&L" align="right" :active-key="$posSortKey" :active-dir="$posSortDir" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->sortedPositions() as $p)
                                            <tr class="bh-row {{ $selectedLotId === ($p['id'] ?? '') ? 'is-selected' : '' }}" wire:click="selectLot({{ \Illuminate\Support\Js::from($p['id']) }})">
                                                <td title="{{ ($p['symbol'] ?? '').' · '.($p['account'] ?? '') }}" style="font-weight:500;white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis">
                                                    {{ $p['displaySymbol'] ?? $p['symbol'] }}@if(!empty($p['short'])) <span class="bh-muted" style="font-size:10px">SHORT</span>@endif
                                                </td>
                                                <td class="r">{{ $this->px($p['avg']) }}</td>
                                                <td class="r">{{ $this->pxOrDash($p['last'] ?? null) }}</td>
                                                <td class="r">{{ $this->money0($p['cost']) }}</td>
                                                <td class="r">{{ $this->moneyOrDash($p['mv'] ?? null) }}</td>
                                                <td class="r {{ ($p['dayChange'] ?? null) === null ? '' : \App\Support\Money::pnlClass((float) $p['dayChange']) }}" style="white-space:nowrap;font-weight:500">@if (($p['dayChange'] ?? null) === null)—@else{{ \App\Support\Money::signedCad($p['dayChange']) }}@endif</td>
                                                <td class="r {{ ($p['dayPct'] ?? null) === null ? '' : \App\Support\Money::pnlClass((float) $p['dayPct']) }}" style="white-space:nowrap;font-weight:500">@if (($p['dayPct'] ?? null) === null)—@else{{ \App\Support\Money::signedPct($p['dayPct'], 2) }}@endif</td>
                                                <td class="r {{ ($p['unreal'] ?? null) === null ? '' : \App\Support\Money::pnlClass((float) $p['unreal']) }}" style="white-space:nowrap;font-weight:500;padding-right:0">@if (($p['unreal'] ?? null) === null)—@else{{ \App\Support\Money::signedCad($p['unreal']) }} ({{ \App\Support\Money::signedPct($p['unrealPct'] ?? null, 2) }})@endif</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="8" class="bh-dim">No open positions match these filters.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        @if ($selPos)
                        <aside class="bh-split-side">
                                <div>
                                    <div style="font:500 16px/1.2 Inter,system-ui,sans-serif">{{ $selPos['displaySymbol'] ?? $selPos['symbol'] }}</div>
                                    <div class="bh-muted" style="margin-top:4px">{{ $selPos['account'] }} · {{ $selPos['currency'] }} · {{ $selPos['direction'] ?? '' }}</div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px">
                                    <div><div class="bh-muted">Qty</div><div>{{ $this->qty($selPos['qty']) }}@if(!empty($selPos['short'])) <span class="bh-muted" style="font-size:10px">SHORT</span>@endif</div></div>
                                    <div><div class="bh-muted">Hold</div><div>{{ \App\Support\Money::formatHold($selPos['held'] ?? 0) }}</div></div>
                                    <div><div class="bh-muted">Avg cost</div><div>{{ $this->px($selPos['avg']) }}</div></div>
                                    <div><div class="bh-muted">Price</div><div>{{ $this->pxOrDash($selPos['last'] ?? null) }}</div></div>
                                    <div><div class="bh-muted">Book</div><div>{{ $this->money0($selPos['cost']) }}</div></div>
                                    <div><div class="bh-muted">Market</div><div>{{ $this->moneyOrDash($selPos['mv'] ?? null) }}</div></div>
                                    <div><div class="bh-muted">P&amp;L</div><div class="{{ ($selPos['unreal'] ?? null) === null ? '' : \App\Support\Money::pnlClass((float) $selPos['unreal']) }}">@if (($selPos['unreal'] ?? null) === null)—@else{{ \App\Support\Money::signedCad($selPos['unreal']) }} <span style="font-size:12px">({{ \App\Support\Money::signedPct($selPos['unrealPct'] ?? null, 2) }})</span>@endif</div></div>
                                    <div><div class="bh-muted">Allocation</div><div>{{ $this->pctPlain($selPos['alloc'] ?? null) }}</div></div>
                                    <div><div class="bh-muted">FX</div><div>{{ $selPos['currency'] }}</div></div>
                                </div>
                                <div>
                                    <div class="bh-h5" style="font-size:12px;margin-bottom:6px">Lots</div>
                                    <div class="bh-scroll" style="max-height:180px">
                                        <table class="bh-table">
                                            <thead><tr><th>Date</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead>
                                            <tbody>
                                                @foreach ($selPos['lots'] ?? [] as $lot)
                                                    <tr>
                                                        <td>{{ $lot['opened'] }}</td>
                                                        <td class="r">{{ $this->qty($lot['qty']) }}</td>
                                                        <td class="r">{{ $this->px($lot['price']) }}</td>
                                                        <td class="r">{{ $this->money0($lot['basis']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div style="margin-top:auto" wire:key="pos-journal-{{ $selPos['id'] ?? '' }}">
                                    <div class="bh-h5" style="font-size:12px;margin-bottom:6px">Thesis</div>
                                    <textarea class="bh-input" rows="3" wire:model.blur="draftThesis" wire:blur="saveJournal" placeholder="Thesis…" style="width:100%;resize:vertical;min-height:64px">{{ $draftThesis }}</textarea>
                                    <div style="display:flex;gap:6px;margin-top:8px;align-items:center">
                                        <select class="bh-input" wire:model.live="draftGrade" wire:change="saveJournal" style="width:72px">
                                            <option value="">Grade</option>
                                            <option value="A">A</option>
                                            <option value="B">B</option>
                                            <option value="C">C</option>
                                            <option value="F">F</option>
                                        </select>
                                        <input class="bh-input" type="text" wire:model.blur="draftTags" wire:blur="saveJournal" placeholder="Tags (comma)" style="flex:1;min-width:0" />
                                    </div>
                                </div>
                        </aside>
                        @endif
                    </div>
                </div>
                <div style="height:14px"></div>
                {{-- Row 2: Sectors | Regions full width — custom donuts so Not classified is grey last and legend matches chart --}}
                {!! \App\Journal\Charts::exposureCard($pf['sectors'] ?? [], $pf['regions'] ?? []) !!}
        </div>{{-- positions --}}

        <div data-bh-page="trades-body" x-show="bhView === 'trades'" x-cloak>
            <div x-show="$wire.tab === 'activity'" x-cloak>
                <div class="bh-card">
                    <h5 class="bh-h5">Activity <span class="bh-dim" style="font-weight:400">{{ count($this->book['activityRows']) }}</span></h5>
                    <div class="bh-scroll" style="margin-top:8px;max-height:620px">
                        <table class="bh-table">
                            <thead>
                                <tr>
                                    <x-sort-th tab="activity" col="when" label="When" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                    <x-sort-th tab="activity" col="symbol" label="Symbol" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                    <x-sort-th tab="activity" col="displaySide" label="Side" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                    <x-sort-th tab="activity" col="quantity" label="Qty" align="right" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                    <x-sort-th tab="activity" col="unitPrice" label="Price" align="right" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                    <x-sort-th tab="activity" col="netCashAmount" label="Amount" align="right" :active-key="$activitySortKey" :active-dir="$activitySortDir" />
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->activityPageRows() as $a)
                                    <tr>
                                        <td>{{ $a['when'] }}</td>
                                        <td>
                                            <div style="font-weight:500">{{ $a['displaySymbol'] }}</div>
                                            <div class="bh-muted">{{ $a['listingLine'] }}</div>
                                        </td>
                                        <td>{{ $a['displaySide'] }}</td>
                                        <td class="r">{{ $a['quantity'] ? $this->qty($a['quantity']) : '' }}</td>
                                        <td class="r">{{ $this->px($a['unitPrice'] ?? 0) }}</td>
                                        <td class="r {{ \App\Support\Money::pnlClass($a['netCashAmount'] ?? 0) }}">{{ $this->formatCad($a['netCashAmount'] ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="bh-dim">No activity matches.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (count($this->book['activityRows']) > $activityPage * 50)
                        <div style="margin-top:10px">
                            <button type="button" class="bh-pill on" wire:click="loadMoreActivity">Show more activity</button>
                        </div>
                    @endif
                </div>
            </div>{{-- activity --}}

            <div x-show="$wire.tab === 'closed'" x-cloak>
                @php $selTrade = $this->selectedTrade(); @endphp
                <div class="bh-split">
                    <div class="bh-card bh-split-main" style="display:flex;flex-direction:column;min-height:0">
                        <h5 class="bh-h5">Closed trades <span class="bh-dim" style="font-weight:400">{{ count($this->book['closed']) }}</span></h5>
                        <div class="bh-scroll" style="margin-top:8px;max-height:620px">
                            <table class="bh-table" style="min-width:1150px">
                                <thead>
                                    <tr>
                                        <x-sort-th tab="closed" col="entryDate" label="Open" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="exitDate" label="Close" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="symbol" label="Symbol" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="side" label="Side" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="exchange" label="Exchange" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="quantity" label="Qty" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="entryPrice" label="Entry" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="exitPrice" label="Exit" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="currency" label="FX" align="center" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="pnlCad" label="P&L" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="pnlPct" label="P&L %" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="holdDays" label="Hold" align="right" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="grade" label="Grade" :active-key="$sortKey" :active-dir="$sortDir" />
                                        <x-sort-th tab="closed" col="tag" label="Tags" :active-key="$sortKey" :active-dir="$sortDir" />
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->book['closed'] as $t)
                                        <tr class="bh-row {{ $selectedTradeId === ($t['id'] ?? '') ? 'is-selected' : '' }}" wire:click="openExecutions({{ \Illuminate\Support\Js::from($t['id']) }})">
                                            <td>{{ $t['entryDate'] }}</td>
                                            <td>{{ $t['exitDate'] }}</td>
                                            <td>
                                                <div style="font-weight:500">{{ $t['displaySymbol'] }}</div>
                                                <div class="bh-muted">{{ $t['listingLine'] }}</div>
                                            </td>
                                            <td>{{ $t['displaySide'] ?? '' }}</td>
                                            <td class="bh-dim">{{ $t['exchange'] ?? '—' }}</td>
                                            <td class="r">{{ $this->qty($t['quantity']) }}</td>
                                            <td class="r">{{ $this->px($t['entryPrice']) }}</td>
                                            <td class="r">{{ $this->px($t['exitPrice']) }}</td>
                                            <td>{{ $t['currency'] ?? '' }}</td>
                                            <td class="r {{ \App\Support\Money::pnlClass($t['pnlCad'] ?? $t['pnl'] ?? 0) }}">{{ $this->formatCad($t['pnlCad'] ?? $t['pnl'] ?? 0) }}</td>
                                            <td class="r">{{ ($t['pnlPct'] ?? null) === null ? '—' : \App\Support\Money::formatPct($t['pnlPct']) }}</td>
                                            <td>{{ \App\Support\Money::formatHold($t['holdDays'] ?? 0) }}</td>
                                            <td><span class="bh-grade">{{ ($t['grade'] ?? '') !== '' ? $t['grade'] : '—' }}</span></td>
                                            <td class="bh-dim">{{ ($t['tag'] ?? '') !== '' ? $t['tag'] : '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="14" class="bh-dim">No closed trades match.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if ($selTrade)
                    <aside class="bh-split-side" style="overflow:auto">
                            <div>
                                <div style="font:500 16px/1.2 Inter,system-ui,sans-serif">{{ $selTrade['displaySymbol'] }}</div>
                                <div class="bh-muted" style="margin-top:4px">{{ $selTrade['listingLine'] }}</div>
                                <div class="{{ \App\Support\Money::pnlClass($selTrade['pnlCad'] ?? $selTrade['pnl'] ?? 0) }}" style="margin-top:8px;font:500 18px/1.2 Inter,system-ui,sans-serif">{{ $this->formatCad($selTrade['pnlCad'] ?? $selTrade['pnl'] ?? 0) }}</div>
                            </div>
                            <div>
                                <div style="display:flex;gap:4px;justify-content:flex-end;margin:0 0 8px;flex-wrap:wrap">
                                    @foreach (\App\Journal\Charts::TRADE_TFS as [$tfId, $tfLabel])
                                        <button type="button" class="bh-pill {{ $chartTf === $tfId ? 'on' : '' }}" wire:click="setChartTf({{ \Illuminate\Support\Js::from($tfId) }})">{{ $tfLabel }}</button>
                                    @endforeach
                                </div>
                                {!! \App\Journal\Charts::price($this->tradePriceBars(), 'No price history for this span.') !!}
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:12px">
                                <div><div class="bh-muted">Open</div><div>{{ $selTrade['entryDate'] }}</div></div>
                                <div><div class="bh-muted">Close</div><div>{{ $selTrade['exitDate'] }}</div></div>
                                <div><div class="bh-muted">Hold</div><div>{{ \App\Support\Money::formatHold($selTrade['holdDays'] ?? 0) }}</div></div>
                                <div><div class="bh-muted">Entry</div><div>{{ $this->px($selTrade['entryPrice']) }}</div></div>
                                <div><div class="bh-muted">Exit</div><div>{{ $this->px($selTrade['exitPrice']) }}</div></div>
                                <div><div class="bh-muted">Side</div><div>{{ $selTrade['displaySide'] }}</div></div>
                            </div>
                            <div>
                                <div class="bh-h5" style="font-size:12px;margin-bottom:6px">Executions</div>
                                <div class="bh-scroll" style="max-height:140px">
                                    <table class="bh-table">
                                        <thead><tr><th>When</th><th>Side</th><th class="r">Qty</th><th class="c" style="text-align:center">FX</th><th class="r">Price</th></tr></thead>
                                        <tbody>
                                            @forelse ($this->executionsRows() as $ex)
                                                <tr>
                                                    <td>{{ $ex['when'] ?? '' }}</td>
                                                    <td>{{ $ex['displaySide'] ?? '' }}</td>
                                                    <td class="r">{{ isset($ex['quantity']) ? $this->qty($ex['quantity']) : '' }}</td>
                                                    <td class="c bh-dim" style="text-align:center;font-variant-numeric:normal">{{ $ex['currency'] ?? ($selTrade['currency'] ?? '') }}</td>
                                                    <td class="r">{{ $this->px($ex['unitPrice'] ?? 0) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="5" class="bh-dim">No fills.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div style="margin-top:auto" wire:key="journal-{{ $selTrade['id'] ?? '' }}">
                                <div class="bh-h5" style="font-size:12px;margin-bottom:6px">Thesis / Grade / Tags</div>
                                <textarea class="bh-input" rows="3" wire:model.blur="draftThesis" wire:blur="saveJournal" placeholder="Thesis…" style="width:100%;resize:vertical;min-height:64px">{{ $draftThesis }}</textarea>
                                <div style="display:flex;gap:6px;margin-top:8px;align-items:center">
                                    <select class="bh-input" wire:model.live="draftGrade" wire:change="saveJournal" style="width:72px">
                                        <option value="">Grade</option>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="F">F</option>
                                    </select>
                                    <input class="bh-input" type="text" wire:model.blur="draftTags" wire:blur="saveJournal" placeholder="Tags (comma)" style="flex:1;min-width:0" />
                                </div>
                            </div>
                    </aside>
                    @endif
                </div>
            </div>{{-- closed trades --}}

            <div x-show="$wire.tab === 'open'" x-cloak>
                <div class="bh-card">
                    <h5 class="bh-h5">Open lots <span class="bh-dim" style="font-weight:400">{{ count($this->book['open'] ?? []) }}</span></h5>
                    <div class="bh-scroll" style="margin-top:8px;max-height:620px">
                        <table class="bh-table">
                            <thead>
                                <tr>
                                    <x-sort-th tab="open" col="date" label="Opened" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="symbol" label="Symbol" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="direction" label="Dir" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="quantity" label="Qty" align="right" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="price" label="Price" align="right" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="currency" label="Ccy" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                    <x-sort-th tab="open" col="name" label="Name" :active-key="$openSortKey" :active-dir="$openSortDir" />
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->book['open'] ?? [] as $l)
                                    <tr>
                                        <td>{{ $l['date'] ?? '' }}</td>
                                        <td>
                                            <div style="font-weight:500">{{ $l['displaySymbol'] ?? $l['symbol'] ?? '' }}</div>
                                            <div class="bh-muted">{{ $l['listingLine'] ?? '' }}</div>
                                        </td>
                                        <td>{{ $l['direction'] ?? '' }}</td>
                                        <td class="r">{{ $this->qty($l['quantity'] ?? 0) }}</td>
                                        <td class="r">{{ $this->px($l['price'] ?? 0) }}</td>
                                        <td>{{ $l['currency'] ?? '' }}</td>
                                        <td class="bh-dim">{{ $l['name'] ?? '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="bh-dim">No open lots.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>{{-- open lots --}}

        </div>{{-- trades-body --}}

        <div data-bh-page="cashflow" x-show="bhView === 'cashflow'" x-cloak>
            @php
                $cf = $this->book['cashflow'] ?? ['tiles'=>[], 'months'=>[], 'holdings'=>[], 'rows'=>[], 'skippedFilters'=>[], 'tmx'=>false];
                $ms = $cf['months'] ?? [];
                $peak = max(array_map(fn ($x) => (float)($x['value'] ?? 0), $ms) ?: [0]);
                $head = max(100, (int) ceil($peak / 100) * 100);
                $skipped = $cf['skippedFilters'] ?? [];
            @endphp
            <div class="bh-kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
                @foreach ($cf['tiles'] as $tile)
                    @if (($tile['label'] ?? '') === 'Yield on cost')
                        <div class="bh-kpi">
                            <div class="bh-kpi-lbl">Yield on cost</div>
                            <div class="bh-kpi-val" style="color:var(--bh-accent-300)">{{ $this->pctPlain($tile['yield'] ?? null) }}</div>
                            <div class="bh-kpi-sub">{{ !empty($tile['book']) ? ($this->money0($tile['earned'] ?? 0).' earned on '.$this->money0($tile['book']).' book cost') : 'No income holdings in scope' }}</div>
                        </div>
                    @else
                        <div class="bh-kpi">
                            <div class="bh-kpi-lbl">{{ preg_replace('/^\d{4} YTD$/', 'YTD', (string)($tile['label'] ?? '')) }}</div>
                            <div class="bh-kpi-val">{{ $this->money0($tile['total'] ?? 0) }}</div>
                            <div class="bh-kpi-sub">{{ $this->money0($tile['perMonth'] ?? 0) }}/mo avg</div>
                        </div>
                    @endif
                @endforeach
            </div>
            @if ($skipped)
                <div class="bh-muted">{{ implode(', ', $skipped) }} {{ count($skipped) > 1 ? 'filters do not' : 'filter does not' }} apply to distributions — only account, date and symbol narrow this page.</div>
            @endif
            <div class="bh-card">
                <h5 class="bh-h5">Monthly distributions</h5>
                <div class="bh-bars" style="margin-top:14px">
                    @forelse ($ms as $b)
                        <div class="bh-bar-col" title="{{ $b['label'] }}: {{ $this->money0($b['value']) }}">
                            <div style="position:absolute;left:0;right:0;bottom:0;height:{{ ($b['count'] ?? 0) == 0 ? 0 : max(1.5, ($b['value'] / max($head,1)) * 100) }}%;background:var(--bh-accent-bar);border-radius:2px 2px 0 0"></div>
                        </div>
                    @empty
                        <div class="bh-muted" style="margin:auto;font-size:12px">No distributions in this range.</div>
                    @endforelse
                </div>
                @if ($ms)
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--bh-ink55);margin-top:6px">
                        <span>{{ $ms[0]['label'] }}</span>
                        <span>{{ $ms[count($ms)-1]['label'] }}</span>
                    </div>
                @endif
            </div>
            <div class="bh-card">
                <h5 class="bh-h5" style="margin-bottom:8px">Cashflow Positions</h5>
                <div class="bh-scroll" style="max-height:344px">
                    <table class="bh-table" style="min-width:900px">
                        <thead>
                            <tr>
                                <x-sort-th tab="cfHold" col="symbol" label="Holding" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="qty" label="Qty" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="avg" label="Avg" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="cost" label="Book" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="mv" label="Market" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="per" label="Distribution" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="ytd" label="YTD" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="all" label="All time" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="nextExDate" label="Ex-Div" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="nextPayDate" label="Pay Day" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="annual" label="Projected" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="yoc" label="Yield on cost" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                                <x-sort-th tab="cfHold" col="currentYield" label="Current yield" align="right" :active-key="$cfHoldSortKey" :active-dir="$cfHoldSortDir" />
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->sortedCfHoldings() as $h)
                                <tr>
                                    <td style="font-weight:500">{{ $h['symbol'] }}</td>
                                    <td class="r">{{ $this->qty($h['qty']) }}</td>
                                    <td class="r">{{ $this->px($h['avg']) }}</td>
                                    <td class="r">{{ $this->money0($h['cost']) }}</td>
                                    <td class="r">{{ $this->moneyOrDash($h['mv'] ?? null) }}</td>
                                    <td class="r">{{ $h['per'] === null ? '—' : '$'.number_format($h['per'], $h['per'] < 1 ? 4 : 2) }}</td>
                                    <td class="r">{{ $this->money0($h['ytd']) }}</td>
                                    <td class="r">{{ $this->money0($h['all']) }}</td>
                                    <td class="r {{ !empty($h['exPast']) ? 'bh-dim' : '' }}">{{ $h['nextExDate'] ?: '—' }}</td>
                                    <td class="r {{ !empty($h['payPast']) ? 'bh-dim' : '' }}">{{ $h['nextPayDate'] ?: '—' }}</td>
                                    <td class="r">{{ $h['annual'] === null ? '—' : $this->money0($h['annual'] / 12) }}</td>
                                    <td class="r" style="color:var(--bh-accent-300);font-weight:500">{{ $this->pctPlain($h['yoc'] ?? null) }}</td>
                                    <td class="r bh-dim">{{ $this->pctPlain($h['currentYield'] ?? null) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="bh-dim">No income holdings in scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="bh-dash-row" style="height:380px">
                <div class="bh-card" style="display:flex;flex-direction:column;min-height:0">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:10px">
                        <h5 class="bh-h5" style="margin:0">Allocation</h5>
                    </div>
                    @php
                        $pieItems = [];
                        foreach ($cf['holdings'] ?? [] as $h) {
                            $v = isset($h['annual']) && $h['annual'] !== null ? ((float) $h['annual'] / 12) : null;
                            if ($v === null || $v <= 0) {
                                continue;
                            }
                            $pieItems[] = [
                                'symbol' => $h['symbol'] ?? '',
                                'value' => $v,
                                'display' => $this->money0($v).'/mo',
                            ];
                        }
                        usort($pieItems, fn ($a, $b) => $b['value'] <=> $a['value']);
                        $pieTotal = array_sum(array_column($pieItems, 'value'));
                        $centreVal = $this->money0($pieTotal).'/mo';
                    @endphp
                    {!! \App\Journal\Charts::donut($pieItems, $centreVal, 'Projected') !!}
                </div>
                                <div class="bh-card" style="display:flex;flex-direction:column;min-height:0">
                    <h5 class="bh-h5" style="margin-bottom:8px">Distribution history</h5>
                    <div class="bh-scroll">
                        <table class="bh-table" style="min-width:600px">
                            <thead>
                                <tr>
                                    <x-sort-th tab="cfHist" col="date" label="Date" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                    <x-sort-th tab="cfHist" col="symbol" label="Symbol" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                    <x-sort-th tab="cfHist" col="account" label="Account" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                    <x-sort-th tab="cfHist" col="qty" label="Qty" align="right" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                    <x-sort-th tab="cfHist" col="per" label="Distribution" align="right" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                    <x-sort-th tab="cfHist" col="amount" label="Amount" align="right" :active-key="$cfHistSortKey" :active-dir="$cfHistSortDir" />
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->sortedCfHistory() as $r)
                                    <tr>
                                        <td class="bh-dim">{{ $r['date'] }}</td>
                                        <td style="font-weight:500">{{ $r['symbol'] }}</td>
                                        <td class="bh-dim" style="max-width:140px;overflow:hidden;text-overflow:ellipsis">{{ $r['account'] }}</td>
                                        <td class="r">{{ $r['qty'] ? $this->qty($r['qty']) : '—' }}</td>
                                        <td class="r">{{ $r['per'] ? '$'.number_format($r['per'], 4) : '—' }}</td>
                                        <td class="r" style="font-weight:500">{{ $this->formatCad($r['amount']) }} {{ $r['currency'] !== 'CAD' ? $r['currency'] : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="bh-dim">No distributions match these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>{{-- cashflow --}}

        @endif

    </div>{{-- bh-page --}}
    </div>{{-- bh-panel --}}
<livewire:filter-sheet
        :years="$book['years']"
        :accounts="$book['accounts']"
        :exchanges="$book['exchanges']"
        :symbols="$this->symbolOptions()"
        :symbol="$form->symbol"
        :price-op="$form->priceOp"
        :hold-op="$form->holdOp"
        :pnl-op="$form->pnlOp"
        :qty-op="$form->qtyOp"
        :filters-active="$this->form->active()"
        :min-date="$this->earliestClose()"
    />

    <flux:modal wire:model="tradeOpen" name="add-trade" class="md:w-[460px]">
        <div class="space-y-4">
            <flux:heading size="lg">Add trade</flux:heading>
            <div class="tk-grid">
                <label class="tk-f">
                    <span class="tk-l">Date</span>
                    <input class="tk-in" type="date" wire:model="tradeDate" />
                </label>
                <label class="tk-f">
                    <span class="tk-l">Account</span>
                    <select class="tk-in" wire:model="tradeAccount">
                        <option value="">Manual</option>
                        @foreach ($this->tradeAccounts() as $acc)
                            <option value="{{ $acc['id'] }}">{{ $acc['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="tk-f">
                    <span class="tk-l">Symbol</span>
                    <input class="tk-in" type="text" wire:model="tradeSymbol" placeholder="e.g. LUNR" autocapitalize="characters" />
                </label>
                <div class="tk-f">
                    <span class="tk-l">Side</span>
                    <div class="tk-seg">
                        <button type="button" class="tk-segopt {{ $tradeSide === 'BUY' ? 'on buy' : '' }}" wire:click="$set('tradeSide', 'BUY')">BUY</button>
                        <button type="button" class="tk-segopt {{ $tradeSide === 'SELL' ? 'on sell' : '' }}" wire:click="$set('tradeSide', 'SELL')">SELL</button>
                    </div>
                </div>
                <label class="tk-f">
                    <span class="tk-l">Quantity</span>
                    <input class="tk-in num" type="text" inputmode="decimal" wire:model="tradeQty" autocomplete="off" />
                </label>
                <label class="tk-f">
                    <span class="tk-l">Price</span>
                    <input class="tk-in num" type="text" inputmode="decimal" wire:model="tradePrice" autocomplete="off" />
                </label>
                <div class="tk-f">
                    <span class="tk-l">Currency</span>
                    <div class="tk-seg">
                        <button type="button" class="tk-segopt {{ $tradeCurrency === 'CAD' ? 'on' : '' }}" wire:click="$set('tradeCurrency', 'CAD')">CAD</button>
                        <button type="button" class="tk-segopt {{ $tradeCurrency === 'USD' ? 'on' : '' }}" wire:click="$set('tradeCurrency', 'USD')">USD</button>
                    </div>
                </div>
                <label class="tk-f">
                    <span class="tk-l">Fees</span>
                    <input class="tk-in num" type="text" inputmode="decimal" wire:model="tradeFees" placeholder="0" autocomplete="off" />
                </label>
            </div>
            @if ($tradeError !== '')
                <div class="status-err" style="font-size:12px">{{ $tradeError }}</div>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeAddTrade">Cancel</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveTrade">Add trade</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="importOpen" name="import-csv" class="md:w-[520px]">
        <div class="space-y-4">
            <flux:heading size="lg">Import CSV</flux:heading>
            <flux:text class="text-sm">Wealthsimple activities-export or a legacy Date/Action/Symbol file. Multiple CSVs are merged; duplicates already stored are skipped.</flux:text>
            <input type="file" accept=".csv,text/csv,text/plain" multiple wire:model="csvFiles" class="tk-in" style="padding:6px 10px;height:auto" />
            @if ($importReport !== [])
                <div style="font-size:12.5px">
                    {{ count($importReport['files'] ?? []) }} {{ count($importReport['files'] ?? []) === 1 ? 'file' : 'files' }}
                    · {{ (int) ($importReport['added'] ?? 0) }} new
                    · {{ (int) ($importReport['duplicates'] ?? 0) }} already stored
                </div>
                @foreach (($importReport['files'] ?? []) as $row)
                    <div style="padding:8px 0;border-top:1px solid var(--bh-hair);font-size:12.5px;display:flex;gap:10px">
                        <span style="font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis">{{ $row['file'] ?? $row['name'] ?? 'file' }}</span>
                        <span class="bh-dim" style="margin-left:auto;white-space:nowrap">
                            @if (! empty($row['error']))
                                {{ $row['error'] }}
                            @else
                                {{ $row['format'] ?? '' }} · {{ (int) ($row['rows'] ?? 0) }} rows · {{ (int) ($row['added'] ?? 0) }} new · {{ (int) ($row['duplicates'] ?? 0) }} duplicates
                            @endif
                        </span>
                    </div>
                @endforeach
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="primary" wire:click="closeImportCsv">Done</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="folderOpen" name="load-folder" class="md:w-[520px]">
        <div class="space-y-4">
            <flux:heading size="lg">Load folder</flux:heading>
            <flux:text class="text-sm">Every CSV at the top level of this folder is imported, and the folder is checked every 10 minutes while the app is open. Files that haven't changed are not re-read.</flux:text>
            <label class="tk-f">
                <span class="tk-l">Folder path</span>
                <input class="tk-in" type="text" wire:model="folderPath" placeholder="/Users/you/Downloads/wealthsimple" spellcheck="false" />
            </label>
            @if ($folderError !== '')
                <div class="status-err" style="font-size:12px">{{ $folderError }}</div>
            @endif
            <div class="flex gap-2 items-center">
                @if (! empty($folderStatus['watching']))
                    <flux:button type="button" variant="ghost" wire:click="stopWatch">Stop watching</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="scanFolder">Scan now</flux:button>
                @endif
                <flux:button type="button" variant="primary" wire:click="watchFolder" class="ml-auto">{{ ! empty($folderStatus['watching']) ? 'Change folder' : 'Watch folder' }}</flux:button>
            </div>
            @if (! empty($folderStatus['watching']))
                <div class="bh-dim" style="font-size:11px">Watching {{ $folderStatus['path'] ?? '' }}@if (! empty($folderStatus['lastScan'])) · last scan {{ $folderStatus['lastScan'] }}@endif</div>
            @endif
            @foreach (($folderStatus['files'] ?? []) as $row)
                <div style="display:flex;gap:10px;font-size:12px;padding:6px 0;border-top:1px solid var(--bh-hair)">
                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis">{{ $row['file'] ?? '' }}</span>
                    <span class="bh-dim" style="margin-left:auto;white-space:nowrap">{{ $row['format'] ?? '' }} · {{ (int) ($row['added'] ?? 0) }} new · {{ (int) ($row['duplicates'] ?? 0) }} dup</span>
                </div>
            @endforeach
            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeFolder">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="dataOpen" name="clear-data" class="md:w-96">
        <div class="space-y-4">
            <flux:heading size="lg">Clear data</flux:heading>
            <flux:text class="text-sm">Deletes everything synced from Wealthsimple, your journal, and the saved login from this machine. You will need to connect and sync again.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeClearData">Cancel</flux:button>
                <flux:button type="button" variant="danger" wire:click="clearData">Clear data</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="connectOpen" name="connect-waiting" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Connect</flux:heading>
                <flux:text class="mt-2">
                    @if ($capturing)
                        {{ $captureStatus !== '' ? $captureStatus : 'Waiting for Wealthsimple login…' }}
                    @elseif ($captureError !== '')
                        Chrome login could not finish.
                    @else
                        Sign in with Wealthsimple in the Chrome window.
                    @endif
                </flux:text>
            </div>
            @if ($captureError !== '')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$captureError" />
            @else
                <flux:text class="text-sm text-zinc-400">
                    Finish signing in with Wealthsimple in the Chrome window. This dialog closes automatically when the session is captured.
                </flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelCapture">{{ $capturing ? 'Cancel' : 'Close' }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <livewire:executions-panel
        lazy
        :trade="$trade ?? []"
        :rows="$this->executionsRows()"
        :sort-key="$innerSortKey"
        :sort-dir="$innerSortDir"
    />

    <livewire:orders-panel
        :open="$ordersOpen"
        :tab="$ordersTab"
        :account="$form->account"
    />

    <livewire:ticket-panel
        :open="$ticketOpen"
        :symbol="$ticketSymbol"
        :side="$ticketSide"
        :exchange="$ticketExchange"
        :account="$ticketSide === 'SELL' && $ticketHeldAccountId !== '' ? $ticketHeldAccountId : $form->account"
        :held-qty="$ticketHeldQty"
    />

    @if ($dryNotice !== '')
        <div class="bh-dry-toast" role="status" wire:key="dry-{{ md5($dryNotice) }}">{{ $dryNotice }}</div>
    @endif

<script>
    this.$intercept('startSync', ({ onFinish }) => {
        onFinish(() => {
            this.$island('kpis').$refresh()
        })
    })

    // Instant tabs + Renderless setView. Cmd/Ctrl+K opens Filters then focuses search (Flux shortcut alone is not enough).
    const mat = document.querySelector('.bh-mat')
    if (mat && document.activeElement === document.body) {
        mat.focus({ preventScroll: true })
    }

    // Theme boot (avoid Alpine x-init try/catch parse issues)
    ;(() => {
        const saved = localStorage.getItem('bh2.theme')
        const theme = (saved === 'midnight' || saved === 'light' || saved === 'nocturne') ? saved : 'nocturne'
        document.documentElement.setAttribute('data-bh-theme', theme)
        if ($wire && $wire.theme !== theme) {
            $wire.set('theme', theme, false)
        }
    })()

    const bhAlpine = () => (mat && window.Alpine ? Alpine.$data(mat) : null)

    const bhFocusFilterSearch = () => {
        let n = 0
        const tick = () => {
            const el = document.getElementById('bh-filter-search')
            const open = document.querySelector('dialog[open]')
            if (el && open) {
                el.focus({ preventScroll: true })
                if (typeof el.select === 'function') el.select()
                if (document.activeElement === el) return
            }
            if (++n < 40) requestAnimationFrame(tick)
        }
        queueMicrotask(tick)
        setTimeout(tick, 0)
        setTimeout(tick, 50)
        setTimeout(tick, 150)
        setTimeout(tick, 300)
    }

    const bhKey = (e) => {
        if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'o') {
            e.preventDefault()
            e.stopPropagation()
            const od = document.querySelector('dialog[open][data-flux-modal-name="orders"], dialog[open][aria-label="Orders"]')
            if (od) {
                od.close()
                $wire.closeOrders()
            } else {
                $wire.openOrders()
            }
            return
        }

        if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
            e.preventDefault()
            e.stopPropagation()
            // Keyboard PASS: ⌘/Ctrl+K opens Filters and focuses #bh-filter-search (not ticket).
            if (! document.querySelector('dialog[open]')) {
                const btn = document.querySelector('[aria-label="Filters"]')
                if (btn) {
                    btn.click()
                } else if (window.Livewire && Livewire.dispatch) {
                    Livewire.dispatch('modal-show', { name: 'filters' })
                } else {
                    $wire.openFilters()
                }
            }
            bhFocusFilterSearch()
            return
        }

        if (e.key === 'Escape') {
            const el = document.activeElement
            const inFilterSearch = el && el.id === 'bh-filter-search'
            const typing = el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)
            const openDialog = document.querySelector('dialog[open]')
            if (openDialog) {
                e.preventDefault()
                const isOrders = !!openDialog.querySelector('.od-segs')
                const isTicket = !!openDialog.querySelector('.tk-seg')
                openDialog.close()
                if (isTicket) { $wire.closeTicket(); return }
                if (isOrders) { $wire.closeOrders(); return }
                $wire.escapeIdle()
                return
            }
            if (typing && !inFilterSearch) return
            e.preventDefault()
            $wire.escapeIdle()
            return
        }

        // Orders Pending/Filled/Cancelled via arrows while panel open
        if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && document.querySelector('dialog[open] .od-segs')) {
            if (e.metaKey || e.ctrlKey || e.altKey) return
            const el = document.activeElement
            if (el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)) return
            e.preventDefault()
            const tabs = ['pending', 'filled', 'cancelled']
            const cur = ($wire.ordersTab || 'pending')
            const j = tabs.indexOf(cur) + (e.key === 'ArrowRight' ? 1 : -1)
            if (j < 0 || j >= tabs.length) return
            $wire.setOrdersTab(tabs[j])
            return
        }

        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
        if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return
        const el = document.activeElement
        const typing = el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)
        if (typing) return
        if (document.querySelector('dialog[open]')) return
        const pages = ['dashboard', 'trades', 'positions', 'cashflow']
        const data = bhAlpine()
        const cur = (data && data.bhView) || $wire.view
        const j = pages.indexOf(cur) + (e.key === 'ArrowRight' ? 1 : -1)
        if (j < 0 || j >= pages.length) return
        e.preventDefault()
        if (data) data.bhView = pages[j]
        $wire.setView(pages[j])
    }


    document.addEventListener('livewire:init', () => {
        Livewire.on('ticket-dry-notice', (e) => {
            const msg = (e && e.message) || (Array.isArray(e) && e[0] && e[0].message) || 'Not sent (orders are off)'
            $wire.flashDryNotice(typeof msg === 'string' ? msg : 'Not sent (orders are off)')
            setTimeout(() => $wire.clearDryNotice(), 10000)
        })
        Livewire.on('close-ticket-after-send', () => { $wire.closeTicket() })
        Livewire.on('orders-dry-notice', (e) => {
            const msg = (e && e.message) || (Array.isArray(e) && e[0] && e[0].message) || 'Not sent (orders are off)'
            $wire.flashDryNotice(typeof msg === 'string' ? msg : 'Not sent (orders are off)')
            setTimeout(() => $wire.clearDryNotice(), 3500)
        })
    })

    if (! window.__bhKeyBound) {
        window.__bhKeyBound = true
        document.addEventListener('keydown', bhKey, true)
    }
</script>
</div>
