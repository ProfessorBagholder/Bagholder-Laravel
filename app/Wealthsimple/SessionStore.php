<?php

namespace App\Wealthsimple;

use App\Journal\BookCache;
use App\Models\Meta;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class SessionStore
{
    /** Marker access_token for local draft / figma proof — never sent to Wealthsimple. */
    public const DRY_ACCESS = 'dry-local';

    public static function load(): array
    {
        try {
            $row = DB::table('ws_sessions')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return [];
        }
        if (! $row) {
            return [];
        }
        try {
            $json = Crypt::decryptString($row->payload);
            $data = json_decode($json, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Alias used by dashboard refresh/sync paths. */
    public static function read(): array
    {
        return self::load();
    }

    public static function save(array $sess): void
    {
        $payload = Crypt::encryptString(json_encode($sess));
        $existing = DB::table('ws_sessions')->orderByDesc('id')->first();
        if ($existing) {
            DB::table('ws_sessions')->where('id', $existing->id)->update([
                'payload' => $payload,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('ws_sessions')->insert([
                'payload' => $payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (! empty($sess['client_id'])) {
            ClientIdScraper::save((string) $sess['client_id']);
        }
        if (! empty($sess['user_agent'])) {
            Meta::putValue('ws_user_agent', (string) $sess['user_agent']);
        }
    }

    /**
     * Seed a dry connected session for local draft / figma (no real WS tokens).
     */
    public static function saveDryConnected(): void
    {
        self::save([
            'access_token' => self::DRY_ACCESS,
            'refresh_token' => '',
            'dry' => true,
        ]);
        Meta::putValue('ws_connected', '1');
        BookCache::flush();
    }

    public static function isDry(?array $sess = null): bool
    {
        $sess ??= self::load();
        if (! empty($sess['dry'])) {
            return true;
        }
        $access = trim((string) ($sess['access_token'] ?? ''));

        return $access === self::DRY_ACCESS || str_starts_with($access, 'dry-');
    }

    public static function forget(): void
    {
        DB::table('ws_sessions')->delete();
        Meta::putValue('ws_connected', '0');
        BookCache::flush();
    }

    public static function hasRefresh(): bool
    {
        $sess = self::load();
        if (self::isDry($sess)) {
            return false;
        }

        return trim((string) ($sess['refresh_token'] ?? '')) !== '';
    }

    public static function connected(): bool
    {
        $sess = self::load();
        if (trim((string) ($sess['refresh_token'] ?? '')) === '' && trim((string) ($sess['access_token'] ?? '')) === '') {
            return false;
        }

        return Meta::getValue('ws_connected', '0') === '1' || ! empty($sess['access_token']);
    }

    public static function identityFrom(array $obj): string
    {
        foreach ([
            'identity_canonical_id',
            'identityCanonicalId',
            'canonical_id',
            'identity_id',
            'resource_owner_id',
            'sub',
        ] as $k) {
            $v = trim((string) ($obj[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
