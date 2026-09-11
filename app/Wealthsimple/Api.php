<?php

namespace App\Wealthsimple;

use App\Journal\Fx;
use Illuminate\Support\Facades\Http;

final class Api
{
    public const OAUTH = 'https://api.production.wealthsimple.com/v1/oauth/v2';

    public const GRAPHQL = 'https://my.wealthsimple.com/graphql';

    public const GRAPHQL_VERSION = '12';

    public const WS_CLIENT = '@wealthsimple/wealthsimple';

    public static function refresh(array $sess): array
    {
        $rt = $sess['refresh_token'] ?? '';
        if ($rt === '') {
            throw new \RuntimeException('missing refresh token');
        }
        $cid = ClientIdScraper::resolve($sess['client_id'] ?? null);
        if ($cid === '') {
            $cid = ClientIdScraper::scrapedProductionFallback();
            ClientIdScraper::save($cid);
        }
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $rt,
            'client_id' => $cid,
        ];
        $resp = Http::timeout(30)
            ->withHeaders(self::sessionHeaders($sess, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-wealthsimple-client' => self::WS_CLIENT,
                'x-ws-profile' => 'invest',
            ]))
            ->post(self::OAUTH.'/token', $body);
        $data = $resp->json() ?: [];
        if (! $resp->successful() || empty($data['access_token'])) {
            $err = $data['error'] ?? ('HTTP '.$resp->status());
            throw new \RuntimeException('Wealthsimple token refresh failed: '.$err);
        }
        $sess['access_token'] = $data['access_token'];
        if (! empty($data['refresh_token'])) {
            $sess['refresh_token'] = $data['refresh_token'];
        }
        if (! empty($data['expires_in'])) {
            $sess['expires_at'] = time() + (int) $data['expires_in'];
        }
        $sess['client_id'] = $cid;
        SessionStore::save($sess);

        return $sess;
    }

    public static function tokenInfo(array $sess): array
    {
        $token = $sess['access_token'] ?? '';
        if ($token === '') {
            return [];
        }
        $resp = Http::timeout(20)
            ->withHeaders(self::sessionHeaders($sess, [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'x-wealthsimple-client' => self::WS_CLIENT,
            ]))
            ->get(self::OAUTH.'/token/info');

        return $resp->json() ?: [];
    }

    public static function graphql(array $sess, string $operation, array $variables, string $query): array
    {
        $token = $sess['access_token'] ?? '';
        $resp = Http::timeout(60)
            ->withHeaders(self::sessionHeaders($sess, [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-wealthsimple-client' => self::WS_CLIENT,
                'x-ws-profile' => 'trade',
                'x-ws-api-version' => self::GRAPHQL_VERSION,
                'x-ws-client' => self::WS_CLIENT,
                'x-ws-locale' => 'en-CA',
                'x-platform-os' => 'web',
                'Origin' => 'https://my.wealthsimple.com',
                'Referer' => 'https://my.wealthsimple.com/app/trade',
            ]))
            ->post(self::GRAPHQL, [
                'operationName' => $operation,
                'variables' => $variables,
                'query' => $query,
            ]);
        $data = $resp->json() ?: [];
        if (! empty($data['errors'])) {
            $first = is_array($data['errors']) ? ($data['errors'][0] ?? []) : $data['errors'];
            $msg = is_array($first) ? ($first['message'] ?? json_encode($first)) : (string) $first;
            throw new \RuntimeException($operation.': '.$msg);
        }
        if (! isset($data['data'])) {
            throw new \RuntimeException('graphql failed: '.$operation);
        }

        return $data['data'];
    }

    public static function sessionHeaders(array $sess, array $headers): array
    {
        if (! empty($sess['wssdi'])) {
            $headers['x-ws-device-id'] = $sess['wssdi'];
        }
        if (! empty($sess['session_id'])) {
            $headers['x-ws-session-id'] = $sess['session_id'];
        }
        if (! empty($sess['user_agent'])) {
            $headers['User-Agent'] = $sess['user_agent'];
        }

        return $headers;
    }

    public static function clientIdFromTokenInfo(array $info): string
    {
        foreach (['application_uid', 'applicationUid'] as $k) {
            if (! empty($info[$k])) {
                return (string) $info[$k];
            }
        }
        $app = $info['application'] ?? [];
        if (is_array($app) && ! empty($app['uid'])) {
            return (string) $app['uid'];
        }

        return '';
    }

    public static function fetchAllAccounts(array $sess, string $identityId): array
    {
        $accounts = [];
        $cursor = null;
        do {
            $data = self::graphql($sess, 'FetchAllAccountFinancials', [
                'identityId' => $identityId,
                'pageSize' => 25,
                'startDate' => '2015-01-01',
                'cursor' => $cursor,
            ], Queries::Q_FETCH_ALL_ACCOUNT_FINANCIALS);
            $conn = $data['identity']['accounts'] ?? [];
            foreach ($conn['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node) && ! empty($node['id'])) {
                    $accounts[] = $node;
                }
            }
            $page = $conn['pageInfo'] ?? [];
            $cursor = ! empty($page['hasNextPage']) ? ($page['endCursor'] ?? null) : null;
        } while ($cursor);

        return $accounts;
    }

    public static function fetchActivitiesForAccount(array $sess, string $accountId, ?string $startDate = null): array
    {
        $items = [];
        $cursor = null;
        $end = now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z');
        $cond = [
            'endDate' => $end,
            'accountIds' => [$accountId],
        ];
        if ($startDate) {
            $raw = trim($startDate);
            if ($raw !== '' && ! str_contains($raw, 'T')) {
                $raw = substr($raw, 0, 10).'T00:00:00.000Z';
            }
            if ($raw !== '') {
                $cond['startDate'] = $raw;
            }
        }
        do {
            $vars = [
                'first' => 100,
                'orderBy' => 'OCCURRED_AT_DESC',
                'condition' => $cond,
            ];
            if ($cursor) {
                $vars['cursor'] = $cursor;
            }
            $data = self::graphql($sess, 'FetchActivityFeedItems', $vars, Queries::Q_FETCH_ACTIVITY_FEED_ITEMS);
            $feed = $data['activityFeedItems'] ?? [];
            foreach ($feed['edges'] ?? [] as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node)) {
                    $items[] = $node;
                }
            }
            $page = $feed['pageInfo'] ?? [];
            $cursor = ! empty($page['hasNextPage']) ? ($page['endCursor'] ?? null) : null;
        } while ($cursor);

        return $items;
    }

    public static function fetchNavHistory(array $sess, string $identityId, ?string $sinceDate = null): array
    {
        return self::paginateNav(
            $sess,
            'IdentityHistoricalFinancialsQuery',
            [
                'identityId' => $identityId,
                'currency' => 'CAD',
                'limit' => 400,
                'includeNetDeposits' => true,
            ],
            Queries::Q_IDENTITY_HISTORICAL_FINANCIALS,
            $sinceDate
        );
    }

    public static function fetchAccountNavHistory(array $sess, string $accountId, ?string $sinceDate = null): array
    {
        $aid = trim($accountId);
        if ($aid === '') {
            return [];
        }

        return self::paginateNav(
            $sess,
            'FetchAccountHistoricalFinancials',
            [
                'id' => $aid,
                'currency' => 'CAD',
                'resolution' => 'DAILY',
                'first' => 400,
            ],
            Queries::Q_FETCH_ACCOUNT_HISTORICAL_FINANCIALS,
            $sinceDate
        );
    }

    public static function fetchBalances(array $sess, array $accountIds): array
    {
        $balances = [];
        $ids = array_values(array_filter($accountIds));
        foreach (array_chunk($ids, 20) as $chunk) {
            $data = self::graphql($sess, 'FetchAccountsWithBalance', [
                'ids' => $chunk,
                'type' => 'TRADING',
            ], Queries::Q_FETCH_ACCOUNTS_WITH_BALANCE);
            foreach ($data['accounts'] ?? [] as $acc) {
                $aid = $acc['id'] ?? null;
                foreach ($acc['custodianAccounts'] ?? [] as $ca) {
                    $bals = $ca['financials']['balance'] ?? [];
                    if (isset($bals['quantity']) || isset($bals['securityId'])) {
                        $bals = [$bals];
                    }
                    foreach ($bals as $b) {
                        $balances[] = [
                            'account_id' => $aid,
                            'custodian_account_id' => $ca['id'] ?? null,
                            'security_id' => $b['securityId'] ?? null,
                            'quantity' => $b['quantity'] ?? null,
                        ];
                    }
                }
            }
        }

        return $balances;
    }

    public static function fetchSecurity(array $sess, string $securityId): ?array
    {
        $id = trim($securityId);
        if ($id === '') {
            return null;
        }
        try {
            $data = self::graphql($sess, 'FetchSecurity', ['securityId' => $id], Queries::Q_FETCH_SECURITY);

            return $data['security'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function fetchUsdCad(string $start, string $end): array
    {
        $resp = Http::timeout(20)->get('https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json', [
            'start_date' => $start,
            'end_date' => $end,
        ]);
        if (! $resp->successful()) {
            return [];
        }
        $obs = $resp->json('observations') ?? [];

        return Fx::ingestBankOfCanada(is_array($obs) ? $obs : []);
    }

    private static function paginateNav(array $sess, string $operation, array $extra, string $query, ?string $sinceDate): array
    {
        $today = gmdate('Y-m-d');
        $since = $sinceDate ? substr($sinceDate, 0, 10) : '';
        if ($since !== '' && $since > $today) {
            return [];
        }
        $year0 = $since !== '' ? (int) substr($since, 0, 4) : 2020;
        $year1 = (int) substr($today, 0, 4);
        $points = [];
        for ($year = $year0; $year <= $year1; $year++) {
            $start = $year.'-01-01';
            if ($since !== '' && $start < $since) {
                $start = $since;
            }
            $end = $year === $year1 ? $today : $year.'-12-31';
            if ($start > $end) {
                continue;
            }
            $cursor = null;
            for ($i = 0; $i < 8; $i++) {
                $vars = array_merge($extra, [
                    'startDate' => $start,
                    'endDate' => $end,
                    'cursor' => $cursor,
                ]);
                $data = self::graphql($sess, $operation, $vars, $query);
                [$pagePoints, $page] = self::navPointsFromPayload($data);
                array_push($points, ...$pagePoints);
                if (empty($page['hasNextPage']) || empty($page['endCursor'])) {
                    break;
                }
                $cursor = $page['endCursor'];
            }
        }
        $byDate = [];
        foreach ($points as $rec) {
            $byDate[$rec['date']] = $rec;
        }
        ksort($byDate);

        return array_values($byDate);
    }

    private static function navPointsFromPayload(array $data): array
    {
        $points = [];
        $ident = $data['identity'] ?? [];
        $acc = $data['account'] ?? [];
        $fin = $ident['financials'] ?? $acc['financials'] ?? [];
        $hist = $fin['historicalDaily'] ?? [];
        foreach ($hist['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            [$amt, $cur] = self::moneyAmount($node, 'netLiquidationValue', 'netLiquidationValueV2');
            $d = substr((string) ($node['date'] ?? ''), 0, 10);
            if ($d === '' || $amt === null) {
                continue;
            }
            $rec = ['date' => $d, 'equity' => $amt, 'currency' => $cur ?: 'CAD'];
            [$nd] = self::moneyAmount($node, 'netDeposits', 'netDepositsV2');
            if ($nd !== null) {
                $rec['netDeposits'] = $nd;
            }
            $points[] = $rec;
        }

        return [$points, $hist['pageInfo'] ?? []];
    }

    private static function moneyAmount(array $node, string ...$keys): array
    {
        foreach ($keys as $key) {
            $money = $node[$key] ?? null;
            if (! is_array($money) || ! isset($money['amount'])) {
                continue;
            }

            return [(float) $money['amount'], $money['currency'] ?? 'CAD'];
        }

        return [null, null];
    }
}
