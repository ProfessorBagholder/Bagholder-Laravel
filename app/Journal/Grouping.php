<?php

namespace App\Journal;

final class Grouping
{
    public static function sliceMemberKey(array $t): string
    {
        $buy = (string) ($t['buyActivityId'] ?? '');
        $sell = (string) ($t['sellActivityId'] ?? '');
        if ($buy !== '' && $sell !== '') {
            return implode('|', [$buy, $sell, number_format((float) ($t['quantity'] ?? 0), 8, '.', '')]);
        }

        return (string) ($t['sliceKey'] ?? $t['id'] ?? '');
    }

    public static function groupLaneKey(array $t): string
    {
        return implode('|', [$t['accountId'] ?? '', $t['symbol'] ?? '', $t['currency'] ?? '']);
    }

    public static function groupIdForKeys(array $keys): string
    {
        $s = implode("\n", $keys);
        sort($keys);
        $s = implode("\n", $keys);
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            $h = self::imul($h, 16777619);
        }

        return 'g_'.dechex($h & 0xFFFFFFFF).'_'.count($keys);
    }

    private static function imul(int $a, int $b): int
    {
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;
        $high = (($ah * $bl) + ($al * $bh)) & 0xFFFF;

        return (($high << 16) + ($al * $bl)) & 0xFFFFFFFF;
    }

    public static function collapseClosedGroup(array $slices, string $id, bool $locked): ?array
    {
        if ($slices === []) {
            return null;
        }
        $t0 = $slices[0];
        $g = [
            'id' => $id,
            'locked' => $locked,
            'accountId' => $t0['accountId'] ?? '',
            'accountType' => $t0['accountType'] ?? '',
            'symbol' => $t0['symbol'] ?? '',
            'name' => $t0['name'] ?? '',
            'currency' => $t0['currency'] ?? '',
            'side' => $t0['side'] ?? '',
            'quantity' => 0.0,
            'entryPrice' => 0.0,
            'exitPrice' => 0.0,
            'entryDate' => $t0['entryDate'] ?? '',
            'exitDate' => $t0['exitDate'] ?? '',
            'holdDays' => $t0['holdDays'] ?? 0,
            'commission' => 0.0,
            'pnl' => 0.0,
            'pnlCad' => 0.0,
            'openDirection' => $t0['openDirection'] ?? '',
            'slices' => array_values($slices),
            'members' => array_map(fn ($s) => self::sliceMemberKey($s), $slices),
        ];
        $entryNotional = 0.0;
        $exitNotional = 0.0;
        foreach ($slices as $s) {
            $g['quantity'] += (float) $s['quantity'];
            $g['pnl'] += (float) $s['pnl'];
            $g['pnlCad'] += (float) $s['pnlCad'];
            $g['commission'] += (float) ($s['commission'] ?? 0);
            $entryNotional += ((float) $s['entryPrice']) * ((float) $s['quantity']);
            $exitNotional += ((float) $s['exitPrice']) * ((float) $s['quantity']);
            if (($s['entryDate'] ?? '') < $g['entryDate']) {
                $g['entryDate'] = $s['entryDate'];
            }
            if (($s['exitDate'] ?? '') > $g['exitDate']) {
                $g['exitDate'] = $s['exitDate'];
            }
        }
        $g['entryPrice'] = $g['quantity'] ? $entryNotional / $g['quantity'] : 0;
        $g['exitPrice'] = $g['quantity'] ? $exitNotional / $g['quantity'] : (float) ($t0['exitPrice'] ?? 0);
        $g['holdDays'] = Dates::daysBetween($g['entryDate'], $g['exitDate']);

        return $g;
    }

    /**
     * One row per close — feed this into computeMetrics, monthly P&L, win rate.
     */
    public static function groupClosedByClose(array $trades): array
    {
        $map = [];
        foreach ($trades as $s) {
            $k = implode('|', [
                $s['symbol'] ?? '',
                $s['currency'] ?? '',
                $s['exitDate'] ?? '',
                number_format((float) ($s['exitPrice'] ?? 0), 8, '.', ''),
                $s['side'] ?? '',
                $s['openDirection'] ?? '',
            ]);
            if (! isset($map[$k])) {
                $map[$k] = [
                    'id' => $s['id'] ?? '',
                    'accountId' => $s['accountId'] ?? '',
                    'accountType' => $s['accountType'] ?? '',
                    'symbol' => $s['symbol'] ?? '',
                    'name' => $s['name'] ?? '',
                    'currency' => $s['currency'] ?? '',
                    'side' => $s['side'] ?? '',
                    'quantity' => 0.0,
                    'entryPrice' => 0.0,
                    'exitPrice' => (float) ($s['exitPrice'] ?? 0),
                    'entryDate' => $s['entryDate'] ?? '',
                    'exitDate' => $s['exitDate'] ?? '',
                    'holdDays' => $s['holdDays'] ?? 0,
                    'commission' => 0.0,
                    'pnl' => 0.0,
                    'pnlCad' => 0.0,
                    'openDirection' => $s['openDirection'] ?? '',
                    '_entryNotional' => 0.0,
                ];
            }
            $map[$k]['quantity'] += (float) $s['quantity'];
            $map[$k]['pnl'] += (float) $s['pnl'];
            $map[$k]['pnlCad'] += (float) $s['pnlCad'];
            $map[$k]['commission'] += (float) ($s['commission'] ?? 0);
            $map[$k]['_entryNotional'] += ((float) $s['entryPrice']) * ((float) $s['quantity']);
            if (($s['entryDate'] ?? '') < $map[$k]['entryDate']) {
                $map[$k]['entryDate'] = $s['entryDate'];
            }
        }
        $out = [];
        foreach ($map as $g) {
            $g['entryPrice'] = $g['quantity'] ? $g['_entryNotional'] / $g['quantity'] : 0;
            $g['holdDays'] = Dates::daysBetween($g['entryDate'], $g['exitDate']);
            unset($g['_entryNotional']);
            $out[] = $g;
        }

        return $out;
    }

    /**
     * Desktop build_trades default: one blotter row per round-trip id (rt:…).
     * Partial exits share the opening fill's rt until the book goes flat.
     */
    public static function groupByRoundTrip(array $slices): array
    {
        $byRt = [];
        $order = [];
        foreach ($slices as $s) {
            $rt = (string) ($s['rt'] ?? '');
            if ($rt === '') {
                $buy = (string) ($s['buyActivityId'] ?? '');
                $rt = $buy !== '' ? 'rt:'.$buy : 'rt:'.self::sliceMemberKey($s);
            }
            if (! isset($byRt[$rt])) {
                $byRt[$rt] = [];
                $order[] = $rt;
            }
            $byRt[$rt][] = $s;
        }
        $out = [];
        foreach ($order as $rt) {
            $g = self::collapseClosedGroup($byRt[$rt], $rt, false);
            if ($g !== null) {
                $out[] = $g;
            }
        }

        return $out;
    }

    public static function defaultGroupsUntilSideChange(array $slices): array
    {
        $lanes = [];
        foreach ($slices as $s) {
            $lanes[self::groupLaneKey($s)][] = $s;
        }
        $out = [];
        foreach ($lanes as $list) {
            usort($list, function ($a, $b) {
                $d = strcmp((string) ($a['exitDate'] ?? ''), (string) ($b['exitDate'] ?? ''));
                if ($d) {
                    return $d;
                }
                $d = strcmp((string) ($a['entryDate'] ?? ''), (string) ($b['entryDate'] ?? ''));
                if ($d) {
                    return $d;
                }

                return strcasecmp(self::sliceMemberKey($a), self::sliceMemberKey($b));
            });
            $cur = [];
            $dir = null;
            $flush = function () use (&$cur, &$out) {
                if ($cur === []) {
                    return;
                }
                $keys = array_map(fn ($s) => self::sliceMemberKey($s), $cur);
                $out[] = self::collapseClosedGroup($cur, self::groupIdForKeys($keys), false);
                $cur = [];
            };
            foreach ($list as $s) {
                if ($dir !== null && ($s['openDirection'] ?? '') !== $dir) {
                    $flush();
                }
                $dir = $s['openDirection'] ?? null;
                $cur[] = $s;
            }
            $flush();
        }

        return array_values(array_filter($out));
    }

    public static function applySavedTradeGroups(array $slices, array $saved): array
    {
        $byKey = [];
        foreach ($slices as $s) {
            $byKey[self::sliceMemberKey($s)] = $s;
        }
        $used = [];
        $out = [];
        foreach ($saved as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            $members = [];
            foreach (($rec['members'] ?? []) as $key) {
                $s = $byKey[(string) $key] ?? null;
                $mk = $s ? self::sliceMemberKey($s) : '';
                if ($s && empty($used[$mk])) {
                    $members[] = $s;
                    $used[$mk] = true;
                }
            }
            if ($members === []) {
                continue;
            }
            $id = (string) ($rec['id'] ?? self::groupIdForKeys(array_map(fn ($m) => self::sliceMemberKey($m), $members)));
            $out[] = self::collapseClosedGroup($members, $id, true);
        }
        $rest = [];
        foreach ($slices as $s) {
            if (empty($used[self::sliceMemberKey($s)])) {
                $rest[] = $s;
            }
        }

        return array_merge($out, self::groupByRoundTrip($rest));
    }
}
