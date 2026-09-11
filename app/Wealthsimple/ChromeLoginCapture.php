<?php

namespace App\Wealthsimple;

use App\Models\Meta;

/**
 * Wealthsimple login via the same Chrome command as Bagholder (profile ~/.bagholder/chrome, port 18765).
 * CDP websocket polling lives in scripts/ws_chrome_login.py (called from PHP).
 */
final class ChromeLoginCapture
{
    public const LOGIN_URL = 'https://my.wealthsimple.com/app/login';

    public const OAUTH_COOKIE = '_oauth2_access_v2';

    public const DEVICE_COOKIE = 'wssdi';

    public const DEBUG_PORT = 18765;

    public const WAIT_SEC = 180;

    public static function bagholderPy(): string
    {
        $env = trim((string) getenv('BAGHOLDER_PY'));
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: ''), '/');
        foreach ([
            '/Users/md/dev/Bagholder/bagholder.py',
            $home.'/dev/Bagholder/bagholder.py',
        ] as $p) {
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }

        return '/Users/md/dev/Bagholder/bagholder.py';
    }
    public static function scriptPath(): string
    {
        return base_path('scripts/ws_chrome_login.py');
    }

    public static function statusPath(): string
    {
        return storage_path('app/private/ws_capture_status.json');
    }

    public static function outPath(): string
    {
        return storage_path('app/private/ws_capture_session.json');
    }

    public static function profilePath(): string
    {
        // Same profile Bagholder uses: ~/.bagholder/chrome
        $bagholder = rtrim((string) (getenv('BAGHOLDER_HOME') ?: ''), '/');
        if ($bagholder === '') {
            $bagholder = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: ''), '/').'/.bagholder';
        }

        return $bagholder.'/chrome';
    }

    public static function pythonBin(): string
    {
        foreach (['python3', 'python'] as $bin) {
            $which = trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
            if ($which !== '' && is_executable($which)) {
                return $which;
            }
        }

        foreach ([
            '/Library/Frameworks/Python.framework/Versions/3.12/bin/python3',
            '/usr/local/bin/python3',
            '/opt/homebrew/bin/python3',
            '/usr/bin/python3',
        ] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'python3';
    }

    /** @return list<string> */
    public static function browserCandidates(): array
    {
        // Same order as bagholder.find_chrome — Chrome, then Edge. Not Brave.
        return [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        ];
    }

    public static function findChrome(): string
    {
        return self::findBrowser();
    }

    public static function findBrowser(): string
    {
        foreach (self::browserCandidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $script = self::scriptPath();
        if (! is_file($script)) {
            return '';
        }

        $cmd = escapeshellarg(self::pythonBin()).' '.escapeshellarg($script).' find-browser';
        $raw = trim((string) shell_exec($cmd.' 2>/dev/null'));
        if ($raw === '') {
            return '';
        }
        $line = trim(explode("\n", $raw)[0] ?? '');
        $data = json_decode($line, true);
        if (! is_array($data) || empty($data['ok']) || empty($data['browser'])) {
            return '';
        }

        return (string) $data['browser'];
    }

    public static function capturing(): bool
    {
        return Meta::getValue('ws_capturing', '0') === '1';
    }

    /**
     * @return array{ok: bool, error?: string, state?: string, testing?: bool, pid?: int}
     */
    public static function start(): array
    {
        if (self::capturing()) {
            $st = self::readStatusFile();
            if (($st['state'] ?? '') === 'capturing' && self::debugPortOpen()) {
                return ['ok' => true, 'state' => 'capturing'];
            }
            // Stale wait (window closed or capture died). Clear it — do not reopen Chrome here.
            self::cancel();
            $error = (string) ($st['error'] ?? '');
            if ($error === '') {
                $error = 'Sign-in canceled';
            }
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        $browser = self::findBrowser();
        if ($browser === '') {
            $error = 'Install Chrome. Passkey login has to happen on Wealthsimple’s site.';
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        if (app()->environment('testing') || app()->runningUnitTests()) {
            if (! filter_var(env('WS_CAPTURE_ALLOW_IN_TESTS', false), FILTER_VALIDATE_BOOL)) {
                Meta::putValue('ws_capturing', '1');
                Meta::putValue('ws_capture_error', '');
                Meta::putValue('ws_capture_pid', '0');
                Meta::putValue('ws_capture_started', (string) time());
                Meta::putValue('ws_capture_port', (string) self::DEBUG_PORT);
                self::writeStatusFile([
                    'ok' => true,
                    'state' => 'capturing',
                    'pid' => 0,
                    'browser' => $browser,
                    'error' => '',
                    'testing' => true,
                ]);

                return ['ok' => true, 'state' => 'capturing', 'testing' => true];
            }
        }

        $script = self::scriptPath();
        if (! is_file($script)) {
            $error = 'Missing scripts/ws_chrome_login.py';
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        @mkdir(dirname(self::statusPath()), 0700, true);
        // Do not create a separate Laravel Chrome profile — reuse Bagholder's ~/.bagholder/chrome.
        @unlink(self::statusPath());
        @unlink(self::outPath());

        Meta::putValue('ws_capturing', '1');
        Meta::putValue('ws_capture_error', '');
        Meta::putValue('ws_capture_started', (string) time());

        $log = storage_path('logs/ws_chrome_login.log');
        @mkdir(dirname($log), 0755, true);

        $cmd = 'BAGHOLDER_PY='.escapeshellarg(self::bagholderPy()).' '
            .escapeshellarg(self::pythonBin()).' '
            .escapeshellarg($script).' capture'
            .' --out '.escapeshellarg(self::outPath())
            .' --status '.escapeshellarg(self::statusPath())
            .' >> '.escapeshellarg($log).' 2>&1 & echo $!';

        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            $error = 'Could not start Chrome login capture.';
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        Meta::putValue('ws_capture_pid', (string) $pid);
        Meta::putValue('ws_capture_port', (string) self::DEBUG_PORT);
        self::writeStatusFile([
            'ok' => true,
            'state' => 'capturing',
            'pid' => $pid,
            'browser' => $browser,
            'error' => '',
        ]);

        return ['ok' => true, 'state' => 'capturing', 'pid' => $pid];
    }

    /**
     * Poll capture progress. On success: SessionStore::save, ws_connected=1, kick SyncService.
     *
     * @return array{state: string, error: string, connected?: bool, done?: bool}
     */
    public static function status(): array
    {
        $tick = self::tick();
        if (! empty($tick['captured'])) {
            return ['done' => true, 'state' => 'done', 'connected' => true, 'error' => ''];
        }
        if (! empty($tick['error'])) {
            return ['done' => true, 'state' => 'error', 'connected' => false, 'error' => (string) $tick['error']];
        }
        if (self::capturing()) {
            return ['done' => false, 'state' => 'capturing', 'error' => ''];
        }

        return ['done' => true, 'state' => 'idle', 'error' => (string) Meta::getValue('ws_capture_error', '')];
    }

    /**
     * @return array{ok: bool, captured?: bool, error?: string}
     */
    public static function tick(): array
    {
        if (! self::capturing()) {
            $err = (string) Meta::getValue('ws_capture_error', '');

            return $err !== '' ? ['ok' => false, 'error' => $err] : ['ok' => true];
        }

        $st = self::readStatusFile();
        $state = (string) ($st['state'] ?? 'capturing');
        $error = (string) ($st['error'] ?? '');

        // Honor done/error even under phpunit (no Chrome/CDP needed for handoff).
        if ($state === 'done') {
            $session = self::readCapturedSession();
            if ($session === [] || empty($session['access_token'])) {
                // Race: Python deleted/moved out file after writing done, but SessionStore already has tokens.
                if (SessionStore::hasRefresh() || SessionStore::connected()) {
                    Meta::putValue('ws_connected', '1');
                    Meta::putValue('ws_capturing', '0');
                    Meta::putValue('ws_capture_error', '');
                    @unlink(self::outPath());
                    SyncService::start();

                    return ['ok' => true, 'captured' => true];
                }
                $error = $error !== '' ? $error : 'Capture finished but session file was empty.';
                Meta::putValue('ws_capturing', '0');
                Meta::putValue('ws_capture_error', $error);

                return ['ok' => false, 'error' => $error];
            }

            SessionStore::save($session);
            Meta::putValue('ws_connected', '1');
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', '');
            @unlink(self::outPath());
            SyncService::start();

            return ['ok' => true, 'captured' => true];
        }

        if ($state === 'error' || $state === 'cancelled') {
            Meta::putValue('ws_capturing', '0');
            if ($error !== '') {
                Meta::putValue('ws_capture_error', $error);
            }

            return ['ok' => false, 'error' => $error !== '' ? $error : 'Capture cancelled.'];
        }

        if ((app()->environment('testing') || app()->runningUnitTests())
            && ! filter_var(env('WS_CAPTURE_ALLOW_IN_TESTS', false), FILTER_VALIDATE_BOOL)) {
            return ['ok' => true];
        }

        if ($state === 'capturing' && ! self::debugPortOpen()) {
            // Chrome quit / user closed the window. Never relaunch from tick.
            usleep(350_000);
            $st = self::readStatusFile();
            $state = (string) ($st['state'] ?? 'capturing');
            $error = (string) ($st['error'] ?? $error);
            if ($state === 'done') {
                return self::tick();
            }
            // Session file may land a beat after port closes — prefer success over "canceled".
            $session = self::readCapturedSession();
            if ($session !== [] && ! empty($session['access_token'])) {
                self::writeStatusFile(array_merge($st, ['ok' => true, 'state' => 'done', 'error' => '']));

                return self::tick();
            }
            if ($error === '') {
                $error = 'Sign-in canceled';
            }
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', '');
            Meta::putValue('ws_signin_canceled_at', (string) time());
            self::writeStatusFile(['ok' => false, 'state' => 'cancelled', 'error' => 'Sign-in canceled']);

            return ['ok' => false, 'error' => 'Sign-in canceled', 'canceled' => true];
        }

        if ($state === 'capturing' && ! self::processAlive()) {
            usleep(200_000);
            $st = self::readStatusFile();
            $state = (string) ($st['state'] ?? 'capturing');
            if ($state === 'done') {
                return self::tick();
            }
            if ($state === 'error' || $state === 'cancelled') {
                Meta::putValue('ws_capturing', '0');
                $error = (string) ($st['error'] ?? $error);

                return ['ok' => false, 'error' => $error];
            }
            $error = $error !== '' ? $error : 'Sign-in canceled';
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        $started = (int) Meta::getValue('ws_capture_started', '0');
        if ($started > 0 && time() - $started > self::WAIT_SEC) {
            $error = 'No session yet. Finish login in the Chrome window, then wait a few seconds.';
            Meta::putValue('ws_capturing', '0');
            Meta::putValue('ws_capture_error', $error);

            return ['ok' => false, 'error' => $error];
        }

        return ['ok' => true];
    }

    public static function cancel(): void
    {
        $script = self::scriptPath();
        if (is_file($script) && ! app()->runningUnitTests()) {
            $cmd = implode(' ', [
                escapeshellarg(self::pythonBin()),
                escapeshellarg($script),
                'cancel',
                '--status', escapeshellarg(self::statusPath()),
            ]);
            @shell_exec($cmd.' 2>/dev/null');
        }

        $st = self::readStatusFile();
        foreach (['chrome_pid', 'pid'] as $key) {
            $pid = (int) ($st[$key] ?? 0);
            if ($pid > 0) {
                @posix_kill($pid, 15);
            }
        }
        $metaPid = (int) Meta::getValue('ws_capture_pid', '0');
        if ($metaPid > 0) {
            @posix_kill($metaPid, 15);
        }

        self::writeStatusFile(['ok' => false, 'state' => 'cancelled', 'cancel' => true, 'error' => '']);
        Meta::putValue('ws_capturing', '0');
        Meta::putValue('ws_capture_error', '');
        Meta::putValue('ws_capture_pid', '');
        Meta::putValue('ws_signin_canceled_at', (string) time());
    }

    /**
     * Same shape Bagholder extracts from CDP cookie lists.
     *
     * @param  list<array<string, mixed>>  $cookies
     * @return array<string, mixed>|null
     */
    public static function tokensFromCookieList(array $cookies): ?array
    {
        if ($cookies === []) {
            return null;
        }
        $body = [];
        $oauth = null;
        $wssdi = '';
        foreach ($cookies as $c) {
            if (! is_array($c)) {
                continue;
            }
            $name = (string) ($c['name'] ?? '');
            $value = (string) ($c['value'] ?? '');
            if ($name === self::DEVICE_COOKIE && $value !== '') {
                $wssdi = $value;
            }
            $parsed = self::jsonWithAccessToken($value);
            if ($parsed !== null) {
                if ($name === self::OAUTH_COOKIE || $oauth === null) {
                    $oauth = $parsed;
                }
            }
        }
        if ($oauth === null || empty($oauth['access_token'])) {
            return null;
        }
        foreach (['access_token', 'refresh_token', 'identity_canonical_id', 'client_id', 'session_id'] as $k) {
            if (! empty($oauth[$k])) {
                $body[$k] = $oauth[$k];
            }
        }
        $ident = SessionStore::identityFrom($oauth);
        if ($ident !== '') {
            $body['identity_canonical_id'] = $ident;
        }
        if (array_key_exists('expires_at', $oauth) && $oauth['expires_at'] !== null) {
            $body['expires_at'] = $oauth['expires_at'];
        }
        if ($wssdi !== '') {
            $body['wssdi'] = $wssdi;
        }

        return $body;
    }

    public static function jsonWithAccessToken(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $cur = trim(is_string($raw) ? $raw : (string) $raw);
        if ($cur === '') {
            return null;
        }
        for ($i = 0; $i < 3; $i++) {
            if (str_contains($cur, 'access_token')) {
                try {
                    $obj = json_decode($cur, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $obj = null;
                }
                if (is_array($obj) && ! empty($obj['access_token'])) {
                    return $obj;
                }
            }
            $nxt = rawurldecode($cur);
            if ($nxt === $cur) {
                break;
            }
            $cur = $nxt;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function readStatusFile(): array
    {
        $path = self::statusPath();
        if (! is_file($path)) {
            return [];
        }
        try {
            $raw = trim((string) file_get_contents($path));
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $payload */
    private static function writeStatusFile(array $payload): void
    {
        $path = self::statusPath();
        @mkdir(dirname($path), 0700, true);
        $tmp = $path.'.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES)."\n");
        @rename($tmp, $path);
    }

    /** @return array<string, mixed> */
    private static function readCapturedSession(): array
    {
        $path = self::outPath();
        if (! is_file($path)) {
            return [];
        }
        try {
            $raw = trim((string) file_get_contents($path));
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function debugPortOpen(): bool
    {
        $fp = @fsockopen('127.0.0.1', self::DEBUG_PORT, $errno, $errstr, 0.3);
        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    private static function processAlive(): bool
    {
        $st = self::readStatusFile();
        $pid = (int) ($st['pid'] ?? Meta::getValue('ws_capture_pid', '0'));
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $out = trim((string) shell_exec('ps -p '.escapeshellarg((string) $pid).' -o pid= 2>/dev/null'));

        return $out !== '';
    }
}
