<?php

namespace App\Journal;

final class Spy
{
    /** @var array<string, float> */
    public static array $byDate = [];

    public static function boot(array $byDate): void
    {
        self::$byDate = $byDate;
    }

    public static function parseSeed(string $raw): array
    {
        $map = [];
        foreach (explode(',', $raw) as $p) {
            if (strlen($p) < 10) {
                continue;
            }
            $d = substr($p, 0, 4).'-'.substr($p, 4, 2).'-'.substr($p, 6, 2);
            $px = (float) substr($p, 9);
            if ($px > 0) {
                $map[$d] = $px;
            }
        }

        return $map;
    }

    public static function ingestFredCsv(string $text): array
    {
        $map = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                continue;
            }
            $d = trim($parts[0]);
            $cell = trim($parts[1]);
            if ($cell === '' || $cell === '.') {
                continue;
            }
            $px = (float) $cell;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! ($px > 0)) {
                continue;
            }
            $map[$d] = $px;
        }

        return $map;
    }

    public static function on(?string $date): ?float
    {
        $d = substr((string) $date, 0, 10);
        if ($d === '') {
            return null;
        }
        for ($i = 0; $i < 18; $i++) {
            $v = self::$byDate[$d] ?? null;
            if ($v && $v > 0) {
                return (float) $v;
            }
            $d = Dates::shiftIsoDate($d, -1);
        }

        return null;
    }

    public static function return(?string $from, ?string $to): ?float
    {
        $a = self::on($from);
        $b = self::on($to);
        if (! ($a > 0) || ! ($b > 0)) {
            return null;
        }

        return $b / $a - 1;
    }
}
