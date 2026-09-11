<?php

namespace App\Journal;

final class Symbols
{
    public static function compactType(?string $s): string
    {
        return strtoupper(preg_replace('/[\s_\-]/', '', (string) $s) ?? '');
    }

    public static function listingTicker(?string $sym): string
    {
        $s = trim((string) $sym);
        if (preg_match('/^(.+)\.(TO|V|CN|NE)$/i', $s, $m)) {
            return $m[1];
        }

        return $s;
    }

    public static function underlyingSymbol(?string $symbol): string
    {
        $s = trim((string) $symbol);
        if ($s === '') {
            return '—';
        }
        $u = strtoupper(preg_replace('/\s+/', ' ', $s) ?? $s);
        if (preg_match('/\b(PUT|CALL)\b/', $u) || preg_match('/\s[CP]$/', $u)) {
            $tok = explode(' ', $u)[0] ?? $s;

            return $tok !== '' ? $tok : $s;
        }
        if (preg_match('/^([A-Z][A-Z0-9.]{0,9}) \d{6}[CP]\d+/', $u, $m)) {
            return $m[1];
        }
        if (preg_match('/^([A-Z][A-Z0-9.]{0,9}) \d{1,2}[A-Z]{3}\d{2}\b/', $u, $m)) {
            return $m[1];
        }

        return $s;
    }

    public static function isOptionSymbol(?string $symbol): bool
    {
        $u = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $symbol) ?? ''));
        if ($u === '') {
            return false;
        }
        if (preg_match('/\b(PUT|CALL)\b/', $u) || preg_match('/\s[CP]$/', $u)) {
            return true;
        }

        return (bool) preg_match('/^[A-Z][A-Z0-9.]{0,9} \d{6}[CP]\d+/', $u);
    }

    public static function optionMultiplier(?string $symbol): int
    {
        return self::isOptionSymbol($symbol) ? 100 : 1;
    }

    public static function blotterTickerMatch(array $t, string $q): bool
    {
        $qq = strtoupper(trim($q));
        if ($qq === '') {
            return true;
        }
        $s = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($t['symbol'] ?? '')) ?? ''));
        $u = strtoupper((string) self::underlyingSymbol($t['symbol'] ?? ''));
        if ($u === $qq || $s === $qq) {
            return true;
        }

        return str_starts_with($s, $qq.' ');
    }
}
