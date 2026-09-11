<?php

namespace App\Journal;

final class Executions
{
    public static function forGroup(array $group, array $activities): array
    {
        $byId = [];
        foreach ($activities as $a) {
            $id = (string) ($a['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $a;
            }
        }
        $seen = [];
        $acts = [];
        foreach ($group['slices'] ?? [] as $s) {
            foreach (['buyActivityId', 'sellActivityId'] as $k) {
                $id = (string) ($s[$k] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (isset($byId[$id])) {
                    $acts[] = $byId[$id];
                }
            }
        }
        if ($acts === [] && ! empty($group['buyActivityId'])) {
            foreach (['buyActivityId', 'sellActivityId'] as $k) {
                $id = (string) ($group[$k] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (isset($byId[$id])) {
                    $acts[] = $byId[$id];
                }
            }
        }
        usort($acts, function ($a, $b) {
            $d = strcmp(Dates::activityWhen($a), Dates::activityWhen($b));

            return $d !== 0 ? $d : strcasecmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return $acts;
    }
}
