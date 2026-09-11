<?php

namespace App\Journal;

final class Fx
{
    public const FALLBACK = 1.35;

    /** @var array<string, float> */
    public static array $byDate = [];

    public static function boot(array $byDate): void
    {
        self::$byDate = $byDate;
    }

    public static function rateOn(?string $date): float
    {
        $d = substr((string) $date, 0, 10);
        if ($d === '') {
            return self::FALLBACK;
        }
        for ($i = 0; $i < 12; $i++) {
            $r = self::$byDate[$d] ?? null;
            if ($r && $r > 0) {
                return (float) $r;
            }
            $d = Dates::shiftIsoDate($d, -1);
        }

        return self::FALLBACK;
    }

    public static function toCad(float $amount, string $currency, ?string $date): float
    {
        if (strtoupper($currency) !== 'USD') {
            return $amount;
        }

        return $amount * self::rateOn($date);
    }

    public static function apply(array $trades): array
    {
        return array_map(function ($t) {
            $ccy = strtoupper((string) ($t['currency'] ?? 'CAD'));
            if ($ccy !== 'USD') {
                $t['pnlCad'] = $t['pnl'] ?? 0;

                return $t;
            }
            $qty = (float) ($t['quantity'] ?? 0);
            $entryC = (float) ($t['entryCommission'] ?? 0);
            $exitC = (float) ($t['exitCommission'] ?? 0);
            $mult = Symbols::optionMultiplier($t['symbol'] ?? '');
            $entryNotional = ((float) $t['entryPrice']) * $qty * $mult;
            $exitNotional = ((float) $t['exitPrice']) * $qty * $mult;
            if (($t['openDirection'] ?? '') === 'SHORT') {
                $pnlCad = self::toCad($entryNotional - $entryC, $ccy, $t['entryDate'] ?? null)
                    - self::toCad($exitNotional + $exitC, $ccy, $t['exitDate'] ?? null);
            } else {
                $pnlCad = self::toCad($exitNotional - $exitC, $ccy, $t['exitDate'] ?? null)
                    - self::toCad($entryNotional + $entryC, $ccy, $t['entryDate'] ?? null);
            }
            $t['pnlCad'] = $pnlCad;

            return $t;
        }, $trades);
    }

    public static function ingestBankOfCanada(array $observations): array
    {
        $map = self::$byDate;
        foreach ($observations as $ob) {
            $v = isset($ob['FXUSDCAD']['v']) ? (float) $ob['FXUSDCAD']['v'] : 0;
            $d = $ob['d'] ?? '';
            if ($d && $v > 0) {
                $map[$d] = $v;
            }
        }

        return $map;
    }
}
