<?php

namespace App\Journal;

use App\Models\Activity;
use App\Models\Meta;
use Illuminate\Support\Facades\Cache;

final class BookCache
{
    public const TAG = 'bagholder.book';

    /** Bump when Book/Metrics shape or review/grade logic changes (forces cache miss). */
    public const CODE_REV = '2026-09-11-cover-side-open-lots';

    /** Per-request memo — version() hits SQLite/meta often via Livewire recomputes. */
    private static ?string $versionMemo = null;

    /** @var array{hit:bool,ms:float,key:string}|null */
    private static ?array $last = null;

    public static function version(): string
    {
        if (self::$versionMemo !== null) {
            return self::$versionMemo;
        }
        $activityCount = (int) Activity::query()->count();
        $syncedAt = (string) Meta::getValue('synced_at', '');
        $wsSyncedAt = (string) Meta::getValue('ws_synced_at', '');
        $groups = (string) Meta::getValue('trade_groups', '');
        $notes = (string) Meta::getValue('trade_notes', '');
        $fx = (string) Meta::getValue('fx_by_date', '');
        $spy = (string) Meta::getValue('spy_by_date', '');
        $quotes = (string) Meta::getValue('quotes', '');
        $dists = (string) Meta::getValue('distributions', '');
        $exposures = (string) Meta::getValue('exposures', '');
        $bench = (string) Meta::getValue('active_benchmark', 'SP500');
        $bust = (string) Meta::getValue('book_cache_bust', '');

        return self::$versionMemo = hash('xxh128', implode('|', [
            self::CODE_REV,
            (string) $activityCount,
            $syncedAt,
            $wsSyncedAt,
            $bust,
            hash('xxh128', $groups),
            hash('xxh128', $notes),
            (string) strlen($fx),
            hash('xxh128', substr($fx, 0, 64).substr($fx, -64)),
            (string) strlen($spy),
            hash('xxh128', substr($spy, 0, 64).substr($spy, -64)),
            (string) strlen($quotes),
            hash('xxh128', substr($quotes, 0, 64).substr($quotes, -64)),
            (string) strlen($dists),
            hash('xxh128', substr($dists, 0, 64).substr($dists, -64)),
            (string) strlen($exposures),
            hash('xxh128', substr($exposures, 0, 64).substr($exposures, -64)),
            $bench,
        ]));
    }

    public static function key(array $filters, array $sort): string
    {
        $payload = json_encode([
            'f' => self::normalize($filters),
            's' => self::normalize($sort),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'book:'.self::version().':'.hash('xxh128', (string) $payload);
    }

    public static function remember(array $filters, array $sort, callable $builder): array
    {
        $t0 = hrtime(true);
        $key = self::key($filters, $sort);
        // File-cache unserialize of a full book can exceed the default 128M.
        $prev = ini_get('memory_limit');
        if (self::memoryBytes((string) $prev) < self::memoryBytes('512M')) {
            @ini_set('memory_limit', '512M');
        }
        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                self::$last = ['hit' => true, 'ms' => (hrtime(true) - $t0) / 1e6, 'key' => $key];

                return $hit;
            }

            $built = $builder();
            self::$last = ['hit' => false, 'ms' => (hrtime(true) - $t0) / 1e6, 'key' => $key];
            // File cache serializes PHP arrays; TTL long — busted via flush()/version.
            try {
                Cache::put($key, $built, now()->addDays(7));
                Cache::put(self::indexKey(), array_values(array_unique(array_merge(
                    Cache::get(self::indexKey(), []),
                    [$key],
                ))), now()->addDays(7));
            } catch (\Throwable $e) {
                // Leave request working even if the payload is too fat to cache.
                Cache::forget($key);
            }

            return $built;
        } finally {
            if (is_string($prev) && $prev !== '') {
                @ini_set('memory_limit', $prev);
            }
        }
    }

    /**
     * @return array{hit:bool,ms:float,key:string}|null
     */
    public static function last(): ?array
    {
        return self::$last;
    }

    public static function flush(): void
    {
        self::$versionMemo = null;
        self::$last = null;
        $keys = Cache::get(self::indexKey(), []);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
        Cache::forget(self::indexKey());
        // Also bump a stamp so any stale key without index entry still misses.
        Meta::putValue('book_cache_bust', (string) microtime(true));
    }

    private static function indexKey(): string
    {
        return 'book:index';
    }

    private static function memoryBytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($limit, -1));
        $n = (float) $limit;

        return (int) match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => (float) $limit,
        };
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::normalize($v);
        }

        return $value;
    }
}
