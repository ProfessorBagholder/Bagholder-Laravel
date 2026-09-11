<?php

namespace App\Journal;

final class Sides
{
    public static function tradeSide(array $activity): ?string
    {
        $s = Symbols::compactType($activity['activitySubType'] ?? '');
        if (in_array($s, ['BUY', 'BUYTOOPEN', 'BTO', 'BUYTOCLOSE', 'BTC'], true) || str_starts_with($s, 'BUY')) {
            return 'BUY';
        }
        if (in_array($s, ['SELL', 'SELLTOOPEN', 'STO', 'SELLTOCLOSE', 'STC'], true) || str_starts_with($s, 'SELL')) {
            return 'SELL';
        }
        $t = Symbols::compactType($activity['activityType'] ?? '');
        if (in_array($t, ['BUYTOOPEN', 'BTO', 'BUYTOCLOSE', 'BTC'], true) || str_starts_with($t, 'BUY')) {
            return 'BUY';
        }
        if (in_array($t, ['SELLTOOPEN', 'STO', 'SELLTOCLOSE', 'STC'], true) || str_starts_with($t, 'SELL')) {
            return 'SELL';
        }

        return null;
    }

    public static function displaySide(array $trade): string
    {
        if (($trade['openDirection'] ?? '') === 'SHORT') {
            return 'COVER';
        }

        return (string) ($trade['side'] ?? '');
    }

    /** Activity/execution Side label: BUYTOCLOSE covers display as COVER (desktop closed-trade language). */
    public static function activityDisplaySide(array $activity): string
    {
        $side = self::tradeSide($activity);
        if ($side === 'BUY' && self::isCloseOnly($activity)) {
            return 'COVER';
        }

        return $side ?: (string) ($activity['activitySubType'] ?? '');
    }

    public static function isIntentionalOpen(array $activity): bool
    {
        $fields = [
            Symbols::compactType($activity['activityType'] ?? ''),
            Symbols::compactType($activity['activitySubType'] ?? ''),
        ];
        foreach ($fields as $f) {
            if (str_contains($f, 'TOOPEN') || $f === 'STO' || $f === 'BTO') {
                return true;
            }
        }

        return false;
    }

    public static function isCloseOnly(array $activity): bool
    {
        $fields = [
            Symbols::compactType($activity['activityType'] ?? ''),
            Symbols::compactType($activity['activitySubType'] ?? ''),
        ];
        foreach ($fields as $f) {
            if (str_contains($f, 'TOCLOSE') || $f === 'BTC' || $f === 'STC') {
                return true;
            }
            if (str_contains($f, 'EXPIR') || str_contains($f, 'ASSIGN') || str_contains($f, 'EXERCISE')) {
                return true;
            }
        }

        return false;
    }

    public static function openingDirection(array $activity, string $side): ?string
    {
        if ($side === 'BUY') {
            return self::isCloseOnly($activity) ? null : 'LONG';
        }
        if (self::isIntentionalOpen($activity)) {
            return 'SHORT';
        }

        return null;
    }

    public static function fifoAccount(array $a): string
    {
        $nick = trim((string) ($a['accountType'] ?? ''));
        if ($nick !== '') {
            return $nick;
        }
        if (! empty($a['fifoId'])) {
            return (string) $a['fifoId'];
        }

        return (string) ($a['accountId'] ?? '');
    }
}
