<?php

namespace App\Wealthsimple;

use App\Journal\Spy;
use App\Models\Activity as ActivityModel;
use App\Journal\BookCache;
use App\Journal\MarketImport;
use App\Models\Meta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SyncService
{
    public static function start(): void
    {
        Meta::putValue('ws_sync_phase', 'start');
        Meta::putValue('ws_sync_step', 'Checking for new rows…');
        Meta::putValue('ws_sync_error', '');
        Meta::putValue('ws_sync_account_index', '0');
        Meta::putValue('ws_sync_scratch', json_encode([
            'accounts' => [],
            'rows' => [],
            'balances' => [],
            'nav' => [],
            'securities' => [],
        ]));
    }

    /**
     * Read-only. Safe on the Livewire poll — no Wealthsimple HTTP.
     *
     * @return array{done: bool, syncing: bool, step: string, error: string, phase: string}
     */
    public static function status(): array
    {
        $phase = (string) Meta::getValue('ws_sync_phase', 'idle');
        $done = $phase === 'idle' || $phase === 'done';

        return [
            'done' => $done,
            'syncing' => ! $done,
            'step' => (string) Meta::getValue('ws_sync_step', ''),
            'error' => (string) Meta::getValue('ws_sync_error', ''),
            'phase' => $phase,
        ];
    }

    /** Start a background artisan worker. The HTTP request does not pull Wealthsimple. */
    public static function dispatch(): void
    {
        self::start();
        self::spawnWorker();
    }

    /** If a pull is in progress and the worker died, start it again. No Guzzle here. */
    public static function ensureWorker(): void
    {
        $phase = (string) Meta::getValue('ws_sync_phase', 'idle');
        if ($phase === 'idle' || $phase === 'done') {
            return;
        }
        if (self::workerAlive()) {
            return;
        }
        $last = (int) Meta::getValue('ws_sync_spawned_at', '0');
        if ($last > 0 && (time() - $last) < 2) {
            return;
        }
        self::spawnWorker();
    }

    /** Advance one phase. For the background artisan worker only — never Livewire. */
    public static function tick(): array
    {
        $phase = (string) Meta::getValue('ws_sync_phase', 'idle');
        if ($phase === 'idle' || $phase === 'done') {
            return ['done' => true, 'step' => (string) Meta::getValue('ws_sync_step', '')];
        }

        try {
            match ($phase) {
                'start' => self::phaseStart(),
                'accounts' => self::phaseAccounts(),
                'activities' => self::phaseActivities(),
                'balances' => self::phaseBalances(),
                'nav' => self::phaseNav(),
                'securities' => self::phaseSecurities(),
                'persist' => self::phasePersist(),
                'spy' => self::phaseSpy(),
                default => Meta::putValue('ws_sync_phase', 'done'),
            };
        } catch (\Throwable $e) {
            $public = self::publicError($e);
            Meta::putValue('ws_sync_error', $public);
            Meta::putValue('ws_sync_step', 'Sync failed');
            Meta::putValue('ws_sync_phase', 'done');

            return ['done' => true, 'error' => $public, 'step' => 'Sync failed'];
        }

        $done = Meta::getValue('ws_sync_phase') === 'done';

        return [
            'done' => $done,
            'step' => (string) Meta::getValue('ws_sync_step', ''),
            'error' => (string) Meta::getValue('ws_sync_error', ''),
        ];
    }

    public static function run(): array
    {
        self::start();

        return self::runRemaining();
    }

    /** Drain remaining phases in this process (CLI). Unlimited time is OK here, not on Livewire. */
    public static function runRemaining(): array
    {
        set_time_limit(0);
        $phase = (string) Meta::getValue('ws_sync_phase', 'idle');
        if ($phase === 'idle' || $phase === 'done') {
            self::start();
        }
        $last = ['done' => false];
        $guard = 0;
        while (! ($last['done'] ?? false) && $guard < 5000) {
            $last = self::tick();
            $guard++;
        }

        return $last;
    }

    private static function spawnWorker(): void
    {
        if (app()->environment('testing') || app()->runningUnitTests()) {
            if (! filter_var(env('WS_SYNC_ALLOW_IN_TESTS', false), FILTER_VALIDATE_BOOL)) {
                Meta::putValue('ws_sync_pid', '0');

                return;
            }
        }

        $log = storage_path('logs/ws_sync.log');
        @mkdir(dirname($log), 0755, true);
        $cmd = 'cd '.escapeshellarg(base_path())
            .' && '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(base_path('artisan'))
            .' ws:sync'
            .' >> '.escapeshellarg($log)
            .' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($cmd));
        Meta::putValue('ws_sync_pid', (string) $pid);
        Meta::putValue('ws_sync_spawned_at', (string) time());
    }

    private static function workerAlive(): bool
    {
        $pid = (int) Meta::getValue('ws_sync_pid', '0');
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return file_exists('/proc/'.$pid);
    }


    /** Rewrite fifo_id on stored activities from account nicknames / linked pairs. */
    public static function reassignFifoPools(?array $accounts = null): int
    {
        if ($accounts === null) {
            $accounts = [];
            if (Schema::hasTable('accounts')) {
                foreach (DB::table('accounts')->get() as $row) {
                    $accounts[] = [
                        'id' => $row->id,
                        'nickname' => $row->nickname ?? '',
                        'unifiedAccountType' => $row->unified_account_type ?? '',
                        'currency' => $row->currency ?? '',
                        'type' => $row->type ?? '',
                    ];
                }
            }
        }
        $pools = ActivityMapper::fifoPoolIds($accounts);
        $n = 0;
        foreach ($pools as $aid => $root) {
            $n += DB::table('activities')->where('account_id', $aid)->where(function ($q) use ($root) {
                $q->whereNull('fifo_id')->orWhere('fifo_id', '!=', $root);
            })->update(['fifo_id' => $root]);
        }

        return $n;
    }
    private static function scratch(): array
    {
        return Meta::json('ws_sync_scratch', []);
    }

    private static function putScratch(array $data): void
    {
        Meta::putValue('ws_sync_scratch', json_encode($data));
    }

    private static function sess(): array
    {
        $sess = SessionStore::load();
        if ($sess === []) {
            throw new \RuntimeException('Connect a Wealthsimple session first.');
        }

        return $sess;
    }

    private static function phaseStart(): void
    {
        Meta::putValue('ws_sync_step', 'Refreshing Wealthsimple session…');
        $sess = self::sess();
        if (empty($sess['access_token']) || self::tokenNeedsRefresh($sess)) {
            $sess = Api::refresh($sess);
        }
        $info = [];
        try {
            $info = Api::tokenInfo($sess);
        } catch (\Throwable) {
            $info = [];
        }
        $cid = Api::clientIdFromTokenInfo($info);
        if ($cid !== '') {
            $sess['client_id'] = $cid;
            ClientIdScraper::save($cid);
        }
        $identity = SessionStore::identityFrom($sess) ?: SessionStore::identityFrom($info);
        if ($identity === '') {
            throw new \RuntimeException('no identity_canonical_id');
        }
        $sess['identity_canonical_id'] = $identity;
        if (! empty($info['email'])) {
            $sess['email'] = $info['email'];
        }
        SessionStore::save($sess);
        Meta::putValue('ws_connected', '1');
        $s = self::scratch();
        $s['identityId'] = $identity;
        self::putScratch($s);
        Meta::putValue('ws_sync_phase', 'accounts');
    }

    private static function tokenNeedsRefresh(array $sess): bool
    {
        $exp = (int) ($sess['expires_at'] ?? 0);

        return $exp > 0 && $exp < time() + 300;
    }

    private static function phaseAccounts(): void
    {
        Meta::putValue('ws_sync_step', 'Fetching accounts…');
        $sess = self::sess();
        $s = self::scratch();
        $accounts = Api::fetchAllAccounts($sess, (string) ($s['identityId'] ?? ''));
        $s['accounts'] = $accounts;
        $s['accountIds'] = array_values(array_filter(array_map(fn ($a) => $a['id'] ?? '', $accounts)));
        self::putScratch($s);
        Meta::putValue('ws_sync_account_index', '0');
        Meta::putValue('ws_sync_phase', 'activities');
        Meta::putValue('ws_sync_step', 'Syncing transactions');
    }

    private static function phaseActivities(): void
    {
        $sess = self::sess();
        $s = self::scratch();
        $ids = $s['accountIds'] ?? [];
        $i = (int) Meta::getValue('ws_sync_account_index', '0');
        if ($i >= count($ids)) {
            $s['rows'] = ActivityMapper::applyFifoPools($s['rows'] ?? [], $s['accounts'] ?? []);
            self::putScratch($s);
            Meta::putValue('ws_sync_phase', 'balances');

            return;
        }
        $aid = $ids[$i];
        $accById = [];
        foreach ($s['accounts'] ?? [] as $a) {
            if (! empty($a['id'])) {
                $accById[$a['id']] = $a;
            }
        }
        $nick = $accById[$aid]['nickname'] ?? $aid;
        Meta::putValue('ws_sync_step', 'Syncing transactions (' . ($i + 1) . '/' . count($ids) . ') ' . $nick . '.');
        $items = Api::fetchActivitiesForAccount($sess, $aid);
        $rows = $s['rows'] ?? [];
        $seen = [];
        foreach ($rows as $r) {
            $seen[$r['id'] ?? ''] = true;
        }
        foreach ($items as $item) {
            foreach (ActivityMapper::rows($item, $accById) as $mapped) {
                if ($mapped && empty($seen[$mapped['id']])) {
                    $rows[] = $mapped;
                    $seen[$mapped['id']] = true;
                }
            }
        }
        $s['rows'] = $rows;
        self::putScratch($s);
        Meta::putValue('ws_sync_account_index', (string) ($i + 1));
    }

    private static function phaseBalances(): void
    {
        Meta::putValue('ws_sync_step', 'Fetching balances…');
        $sess = self::sess();
        $s = self::scratch();
        $s['balances'] = Api::fetchBalances($sess, $s['accountIds'] ?? []);
        self::putScratch($s);
        Meta::putValue('ws_sync_phase', 'nav');
    }

    private static function phaseNav(): void
    {
        Meta::putValue('ws_sync_step', 'Fetching equity history…');
        $sess = self::sess();
        $s = self::scratch();
        $identity = (string) ($s['identityId'] ?? '');
        $combined = [];
        try {
            foreach (Api::fetchNavHistory($sess, $identity) as $rec) {
                $rec['accountId'] = '';
                $combined[] = $rec;
            }
        } catch (\Throwable) {
            // keep going — nickname series still useful
        }
        $groups = [];
        foreach ($s['accounts'] ?? [] as $a) {
            $nick = trim((string) ($a['nickname'] ?? $a['unifiedAccountType'] ?? ''));
            $id = (string) ($a['id'] ?? '');
            if ($nick === '' || $id === '') {
                continue;
            }
            $groups[$nick][] = $id;
        }
        foreach ($groups as $nick => $ids) {
            Meta::putValue('ws_sync_step', 'Fetching equity history for '.$nick.'…');
            $series = [];
            foreach ($ids as $id) {
                try {
                    $series[] = Api::fetchAccountNavHistory($sess, $id);
                } catch (\Throwable) {
                    $series[] = [];
                }
            }
            $merged = self::mergeNav($series);
            foreach ($merged as $rec) {
                $rec['accountId'] = $nick;
                $combined[] = $rec;
            }
        }
        $s['nav'] = $combined;

        $range = self::activityDateRange($s['rows'] ?? []);
        try {
            $s['fx'] = Api::fetchUsdCad($range['start'], $range['end']);
        } catch (\Throwable) {
            $s['fx'] = [];
        }
        self::putScratch($s);
        Meta::putValue('ws_sync_phase', 'securities');
    }

    private static function mergeNav(array $seriesList): array
    {
        $byDate = [];
        foreach ($seriesList as $series) {
            foreach ($series as $rec) {
                $d = substr((string) ($rec['date'] ?? ''), 0, 10);
                if ($d === '') {
                    continue;
                }
                $cur = $byDate[$d] ?? [
                    'date' => $d,
                    'equity' => 0.0,
                    'currency' => $rec['currency'] ?? 'CAD',
                ];
                $cur['equity'] += (float) ($rec['equity'] ?? 0);
                if (isset($rec['netDeposits'])) {
                    $cur['netDeposits'] = ($cur['netDeposits'] ?? 0) + (float) $rec['netDeposits'];
                }
                $byDate[$d] = $cur;
            }
        }
        ksort($byDate);

        return array_values($byDate);
    }

    private static function activityDateRange(array $rows): array
    {
        $dates = [];
        foreach ($rows as $r) {
            $d = substr((string) ($r['transactionDate'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $dates[] = $d;
            }
        }
        if ($dates === []) {
            $end = gmdate('Y-m-d');

            return ['start' => gmdate('Y-m-d', strtotime('-5 years')), 'end' => $end];
        }
        sort($dates);

        return ['start' => $dates[0], 'end' => $dates[array_key_last($dates)]];
    }

    private static function phaseSecurities(): void
    {
        Meta::putValue('ws_sync_step', 'Looking up company names…');
        $sess = self::sess();
        $s = self::scratch();
        $ids = [];
        foreach ($s['rows'] ?? [] as $r) {
            $sid = trim((string) ($r['securityId'] ?? ''));
            if ($sid !== '') {
                $ids[$sid] = true;
            }
        }
        $secs = [];
        $left = count($ids);
        foreach (array_keys($ids) as $id) {
            Meta::putValue('ws_sync_step', 'Looking up company names, '.$left.' left');
            $sec = Api::fetchSecurity($sess, $id);
            if ($sec) {
                $secs[] = $sec;
            }
            $left--;
        }
        $s['securities'] = $secs;
        self::putScratch($s);
        Meta::putValue('ws_sync_phase', 'persist');
    }

    private static function phasePersist(): void
    {
        Meta::putValue('ws_sync_step', 'Saving…');
        $s = self::scratch();
        if (! empty($s['fx']) && is_array($s['fx'])) {
            Meta::putValue('fx_by_date', json_encode($s['fx']));
        }

        DB::transaction(function () use ($s) {
            ActivityModel::query()->delete();
            foreach ($s['rows'] ?? [] as $row) {
                $db = ActivityMapper::toDatabase($row);
                if ($db['id'] === '' || $db['transaction_date'] === '') {
                    continue;
                }
                ActivityModel::query()->create($db);
            }

            if (Schema::hasTable('accounts')) {
                DB::table('accounts')->delete();
                foreach ($s['accounts'] ?? [] as $a) {
                    $nlv = $a['financials']['currentCombined']['netLiquidationValue']['amount'] ?? null;
                    DB::table('accounts')->insert([
                        'id' => $a['id'],
                        'nickname' => $a['nickname'] ?? '',
                        'unified_account_type' => $a['unifiedAccountType'] ?? '',
                        'currency' => $a['currency'] ?? 'CAD',
                        'status' => $a['status'] ?? '',
                        'type' => $a['type'] ?? '',
                        'net_liquidation_value' => $nlv,
                    ]);
                }
            }
            if (Schema::hasTable('balances')) {
                DB::table('balances')->delete();
                foreach ($s['balances'] ?? [] as $b) {
                    DB::table('balances')->insert($b);
                }
            }
            if (Schema::hasTable('nav_history')) {
                DB::table('nav_history')->delete();
                foreach ($s['nav'] ?? [] as $n) {
                    DB::table('nav_history')->insert([
                        'account_id' => $n['accountId'] ?? '',
                        'date' => $n['date'],
                        'equity' => $n['equity'] ?? null,
                        'currency' => $n['currency'] ?? 'CAD',
                        'net_deposits' => $n['netDeposits'] ?? null,
                    ]);
                }
            }
            if (Schema::hasTable('securities')) {
                DB::table('securities')->delete();
                foreach ($s['securities'] ?? [] as $sec) {
                    $stock = $sec['stock'] ?? [];
                    $under = $sec['optionDetails']['underlyingSecurity']['id'] ?? null;
                    DB::table('securities')->insert([
                        'id' => $sec['id'] ?? '',
                        'symbol' => $stock['symbol'] ?? '',
                        'name' => $stock['name'] ?? '',
                        'primary_exchange' => $stock['primaryExchange'] ?? '',
                        'primary_mic' => $stock['primaryMic'] ?? '',
                        'currency' => $sec['currency'] ?? '',
                        'underlying_id' => $under,
                        'fetched_at' => now()->toIso8601String(),
                    ]);
                }
            }
        });

        Meta::putValue('ws_sync_phase', 'spy');
    }

    private static function phaseSpy(): void
    {
        Meta::putValue('ws_sync_step', 'Refreshing S&P 500 (FRED SP500)…');
        try {
            $resp = Http::timeout(20)->get('https://fred.stlouisfed.org/graph/fredgraph.csv?id=SP500');
            if ($resp->successful()) {
                $map = Spy::ingestFredCsv($resp->body());
                if ($map !== []) {
                    $existing = Meta::json('spy_by_date', []);
                    Meta::putValue('spy_by_date', json_encode(array_merge($existing, $map)));
                }
            }
        } catch (\Throwable) {
            // keep seed
        }

        $n = ActivityModel::query()->count();
        $synced = now('UTC')->toIso8601String();
        Meta::putValue('synced_at', $synced);
        Meta::putValue('ws_synced_at', $synced);
        Meta::putValue('ws_sync_step', '');
        Meta::putValue('ws_sync_phase', 'done');
        Meta::putValue('ws_sync_scratch', '{}');
        Meta::putValue('ws_sync_error', '');
        try { MarketImport::import(); } catch (\Throwable $e) { /* market optional */ }
        BookCache::flush();
        unset($n);
    }

    private static function publicError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $msg = preg_replace('/(?i)(access_token|refresh_token)\s*[:=]\s*\S+/', '$1=[redacted]', $msg) ?? $msg;
        $msg = preg_replace('/(?i)(bearer|access_token|refresh_token)/', '[redacted]', $msg) ?? $msg;

        return $msg;
    }
}
