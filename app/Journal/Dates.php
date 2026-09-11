<?php

namespace App\Journal;

final class Dates
{
    public static function daysBetween(string $a, string $b): int
    {
        $start = strtotime(substr($a, 0, 10).'T00:00:00Z');
        $end = strtotime(substr($b, 0, 10).'T00:00:00Z');
        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, (int) round(($end - $start) / 86400));
    }

    public static function isoDayDiff(string $from, string $to): float
    {
        $a = strtotime(substr($from, 0, 10).'T12:00:00');
        $b = strtotime(substr($to, 0, 10).'T12:00:00');
        if ($a === false || $b === false) {
            return 0;
        }

        return ($b - $a) / 86400;
    }

    public static function shiftIsoDate(string $iso, int $days): string
    {
        $d = substr($iso, 0, 10);
        $t = strtotime($d.'T12:00:00');
        if ($t === false) {
            return $d;
        }

        return gmdate('Y-m-d', $t + ($days * 86400));
    }

    public static function clampToToday(string $iso): string
    {
        $today = gmdate('Y-m-d');

        return $iso > $today ? $today : $iso;
    }

    public static function activityWhen(array $a): string
    {
        $raw = trim((string) ($a['occurredAt'] ?? $a['transactionDate'] ?? ''));
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, 'T')) {
            $day = substr($raw, 0, 10);
            $clock = substr($raw, 11, 5);

            return $clock !== '' ? $day.' '.$clock : $day;
        }

        return substr($raw, 0, 10);
    }

    public static function yearFromRange(string $from, string $to): string
    {
        if (preg_match('/^(\d{4})-01-01$/', $from, $a) && preg_match('/^(\d{4})-12-31$/', $to, $b) && $a[1] === $b[1]) {
            return $a[1];
        }

        return '';
    }
}
