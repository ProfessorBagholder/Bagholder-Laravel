<?php

namespace App\Journal;

final class Listings
{
    /** @var array<string, array> */
    public static array $securities = [];

    /** @var array<string, array> */
    public static array $byId = [];

    public static function boot(array $securities): void
    {
        self::$securities = $securities;
        self::$byId = [];
        foreach ($securities as $sec) {
            if (! empty($sec['id'])) {
                self::$byId[(string) $sec['id']] = $sec;
            }
        }
    }

    public static function securityById(?string $id): ?array
    {
        $sid = (string) $id;
        if ($sid === '') {
            return null;
        }

        return self::$byId[$sid] ?? null;
    }

    public static function exchangeLabel(?array $sec): string
    {
        $raw = (string) ($sec['primaryExchange'] ?? '');
        $up = strtoupper($raw);
        $alias = [
            'TSX VENTURE' => 'TSX-V',
            'TSX-V' => 'TSX-V',
            'VENTURE' => 'TSX-V',
            'TORONTO' => 'TSX',
            'TSX' => 'TSX',
        ];
        if (isset($alias[$up])) {
            return $alias[$up];
        }
        if ($raw !== '') {
            return $raw;
        }
        $mic = strtoupper((string) ($sec['primaryMic'] ?? ''));
        $micMap = [
            'XTSV' => 'TSX-V',
            'XTSX' => 'TSX',
            'XNAS' => 'NASDAQ',
            'XNYS' => 'NYSE',
            'XASE' => 'NYSE American',
            'ARCX' => 'NYSE Arca',
        ];

        return $micMap[$mic] ?? '';
    }

    public static function isAlphaVenue(?array $sec): bool
    {
        $exch = strtoupper((string) ($sec['primaryExchange'] ?? ''));
        $mic = strtoupper((string) ($sec['primaryMic'] ?? ''));

        return $exch === 'ALPHA EXCHANGE' || $exch === 'ALPHA' || $mic === 'XATS';
    }

    public static function preferredListing(?array $sec): ?array
    {
        if (! $sec || ! self::isAlphaVenue($sec)) {
            return $sec;
        }
        $sym = Symbols::listingTicker($sec['symbol'] ?? '');
        $ccy = (string) ($sec['currency'] ?? '');
        if ($sym === '') {
            return $sec;
        }
        foreach (self::$securities as $other) {
            if (! $other || ($other['id'] ?? '') === ($sec['id'] ?? '')) {
                continue;
            }
            if (! empty($other['underlyingId'])) {
                continue;
            }
            if (Symbols::listingTicker($other['symbol'] ?? '') !== $sym) {
                continue;
            }
            if ($ccy !== '' && (string) ($other['currency'] ?? '') !== $ccy) {
                continue;
            }
            $label = self::exchangeLabel($other);
            if ($label === 'TSX-V' || $label === 'TSX') {
                return $other;
            }
        }

        return $sec;
    }

    public static function activitySecurityId(array $t, array $activities = []): ?string
    {
        if (! empty($t['securityId'])) {
            return (string) $t['securityId'];
        }
        $ids = [];
        if (! empty($t['buyActivityId'])) {
            $ids[] = $t['buyActivityId'];
        }
        if (! empty($t['sellActivityId'])) {
            $ids[] = $t['sellActivityId'];
        }
        foreach ($t['slices'] ?? [] as $s) {
            if (! empty($s['buyActivityId'])) {
                $ids[] = $s['buyActivityId'];
            }
            if (! empty($s['sellActivityId'])) {
                $ids[] = $s['sellActivityId'];
            }
        }
        foreach ($activities as $a) {
            if (in_array($a['id'] ?? '', $ids, true) && ! empty($a['securityId'])) {
                return (string) $a['securityId'];
            }
        }

        return $t['securityId'] ?? null;
    }

    public static function listingSecurity(array $t, array $activities = []): ?array
    {
        $sid = self::activitySecurityId($t, $activities);
        $sec = self::securityById($sid);
        if ($sec && ! empty($sec['underlyingId'])) {
            $under = self::securityById($sec['underlyingId']);
            if ($under) {
                $sec = $under;
            }
        }

        return self::preferredListing($sec);
    }

    public static function listingExchange(array $t, array $activities = []): string
    {
        return self::exchangeLabel(self::listingSecurity($t, $activities));
    }

    public static function listingLine(array $t, array $activities = []): string
    {
        $sec = self::listingSecurity($t, $activities);
        $fallback = (string) ($t['symbol'] ?? '');
        if (! $sec || empty($sec['name'])) {
            return $fallback;
        }
        $exch = self::exchangeLabel($sec);
        $sym = Symbols::listingTicker($sec['symbol'] ?? '');
        if ($exch && $sym) {
            return $sec['name'].' · '.$exch.': '.$sym;
        }
        if ($exch) {
            return $sec['name'].' · '.$exch;
        }

        return (string) $sec['name'];
    }

    public static function knownExchanges(): array
    {
        $set = [];
        foreach (self::$securities as $sec) {
            if ($sec && ! empty($sec['underlyingId'])) {
                $under = self::securityById($sec['underlyingId']);
                if ($under) {
                    $sec = $under;
                }
            }
            $sec = self::preferredListing($sec);
            $e = self::exchangeLabel($sec);
            if ($e) {
                $set[$e] = true;
            }
        }
        $keys = array_keys($set);
        sort($keys);

        return $keys;
    }
}
