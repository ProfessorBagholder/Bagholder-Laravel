<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoJournalSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('accounts')->delete();
        DB::table('securities')->delete();
        DB::table('nav_history')->delete();
        DB::table('balances')->delete();
        Activity::query()->delete();

        DB::table('accounts')->insert([
            ['id' => 'tfsa-1', 'nickname' => 'TFSA', 'unified_account_type' => 'TFSA', 'currency' => 'CAD', 'status' => 'open', 'type' => 'SELF_DIRECTED', 'net_liquidation_value' => 125000],
            ['id' => 'rrsp-1', 'nickname' => 'RRSP', 'unified_account_type' => 'RRSP', 'currency' => 'CAD', 'status' => 'open', 'type' => 'SELF_DIRECTED', 'net_liquidation_value' => 88000],
        ]);

        DB::table('securities')->insert([
            ['id' => 'sec-shop', 'symbol' => 'SHOP.TO', 'name' => 'Shopify Inc.', 'primary_exchange' => 'TSX', 'primary_mic' => 'XTSX', 'currency' => 'CAD', 'underlying_id' => null, 'fetched_at' => now()->toIso8601String()],
            ['id' => 'sec-cnq', 'symbol' => 'CNQ.TO', 'name' => 'Canadian Natural Resources Limited', 'primary_exchange' => 'TSX', 'primary_mic' => 'XTSX', 'currency' => 'CAD', 'underlying_id' => null, 'fetched_at' => now()->toIso8601String()],
            ['id' => 'sec-aapl', 'symbol' => 'AAPL', 'name' => 'Apple Inc.', 'primary_exchange' => 'NASDAQ', 'primary_mic' => 'XNAS', 'currency' => 'USD', 'underlying_id' => null, 'fetched_at' => now()->toIso8601String()],
            ['id' => 'sec-weed', 'symbol' => 'WEED.TO', 'name' => 'Canopy Growth Corporation', 'primary_exchange' => 'TSX', 'primary_mic' => 'XTSX', 'currency' => 'CAD', 'underlying_id' => null, 'fetched_at' => now()->toIso8601String()],
        ]);

        $rows = [
            $this->trade('shop-buy-24', '2024-03-01T14:32:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'BUY', 20, 80, -1600, 'sec-shop'),
            $this->trade('shop-sell-24', '2024-06-15T15:01:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'SELL', -20, 120, 2400, 'sec-shop'),
            $this->trade('cnq-buy', '2024-04-01T13:10:00Z', 'rrsp-1', 'RRSP', 'CNQ.TO', 'Canadian Natural Resources Limited', 'CAD', 'BUY', 50, 45, -2250, 'sec-cnq'),
            $this->trade('cnq-sell', '2024-08-01T16:40:00Z', 'rrsp-1', 'RRSP', 'CNQ.TO', 'Canadian Natural Resources Limited', 'CAD', 'SELL', -50, 40, 2000, 'sec-cnq'),
            $this->trade('weed-short', '2024-05-01T14:00:00Z', 'tfsa-1', 'TFSA', 'WEED.TO', 'Canopy Growth Corporation', 'CAD', 'SELLTOOPEN', -100, 12, 1200, 'sec-weed'),
            $this->trade('weed-cover', '2024-07-10T15:22:00Z', 'tfsa-1', 'TFSA', 'WEED.TO', 'Canopy Growth Corporation', 'CAD', 'BUYTOCLOSE', 100, 8, -800, 'sec-weed'),
            $this->trade('aapl-buy', '2025-01-10T14:55:00Z', 'tfsa-1', 'TFSA', 'AAPL', 'Apple Inc.', 'USD', 'BUY', 10, 180, -1800, 'sec-aapl'),
            $this->trade('aapl-sell', '2025-09-01T15:05:00Z', 'tfsa-1', 'TFSA', 'AAPL', 'Apple Inc.', 'USD', 'SELL', -10, 210, 2100, 'sec-aapl'),
            $this->trade('shop-buy-25', '2025-02-01T14:12:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'BUY', 30, 100, -3000, 'sec-shop'),
            $this->trade('shop-sell-25a', '2025-03-01T15:00:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'SELL', -15, 90, 1350, 'sec-shop'),
            $this->trade('shop-sell-25b', '2025-04-01T15:00:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'SELL', -15, 110, 1650, 'sec-shop'),
            $this->trade('shop-open', '2026-01-15T14:44:00Z', 'tfsa-1', 'TFSA', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'BUY', 5, 95, -475, 'sec-shop'),
            $this->trade('shop-buy-23', '2023-02-10T14:00:00Z', 'rrsp-1', 'RRSP', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'BUY', 8, 60, -480, 'sec-shop'),
            $this->trade('shop-sell-23', '2023-11-20T15:00:00Z', 'rrsp-1', 'RRSP', 'SHOP.TO', 'Shopify Inc.', 'CAD', 'SELL', -8, 70, 560, 'sec-shop'),
        ];

        $rows[] = [
            'id' => 'div-shop',
            'canonical_id' => 'div-shop',
            'occurred_at' => '2025-06-20T12:00:00Z',
            'transaction_date' => '2025-06-20',
            'settlement_date' => '2025-06-20',
            'account_id' => 'tfsa-1',
            'book_id' => 'tfsa-1',
            'fifo_id' => 'tfsa-1',
            'account_type' => 'TFSA',
            'activity_type' => 'Dividend',
            'activity_sub_type' => 'dividend',
            'description' => 'SHOP dividend',
            'direction' => 'CREDIT',
            'symbol' => 'SHOP.TO',
            'name' => 'Shopify Inc.',
            'currency' => 'CAD',
            'quantity' => null,
            'unit_price' => null,
            'commission' => 0,
            'net_cash_amount' => 42.5,
            'category' => 'dividend',
            'balance' => null,
            'source' => 'demo',
            'raw_type' => 'DIVIDEND',
            'aft_type' => null,
            'counter_symbol' => null,
            'security_id' => 'sec-shop',
        ];

        foreach ($rows as $row) {
            Activity::query()->create($row);
        }

        $nav = [];
        $start = strtotime('2023-01-03 UTC');
        $end = strtotime('2026-09-03 UTC');
        $equity = 72000.0;
        for ($t = $start; $t <= $end; $t += 86400) {
            $dow = (int) gmdate('N', $t);
            if ($dow >= 6) {
                continue;
            }
            $equity += 18 + sin($t / 86400 / 12) * 40;
            $d = gmdate('Y-m-d', $t);
            $nav[] = [
                'account_id' => '',
                'date' => $d,
                'equity' => round($equity, 2),
                'currency' => 'CAD',
                'net_deposits' => 50000,
            ];
        }
        foreach (array_chunk($nav, 400) as $chunk) {
            DB::table('nav_history')->insert($chunk);
        }

        $fx = [];
        for ($t = strtotime('2024-01-01 UTC'); $t <= strtotime('2026-09-03 UTC'); $t += 86400) {
            $fx[gmdate('Y-m-d', $t)] = 1.35 + 0.02 * sin($t / 86400 / 20);
        }
        DB::table('meta')->updateOrInsert(['key' => 'fx_by_date'], ['value' => json_encode($fx)]);
        DB::table('meta')->updateOrInsert(['key' => 'synced_at'], ['value' => now()->toIso8601String()]);
    }

    private function trade(
        string $id,
        string $occurred,
        string $account,
        string $type,
        string $symbol,
        string $name,
        string $ccy,
        string $side,
        float $qty,
        float $px,
        float $cash,
        string $secId,
    ): array {
        $day = substr($occurred, 0, 10);

        return [
            'id' => $id,
            'canonical_id' => $id,
            'occurred_at' => $occurred,
            'transaction_date' => $day,
            'settlement_date' => $day,
            'account_id' => $account,
            'book_id' => $account,
            'fifo_id' => $account,
            'account_type' => $type,
            'activity_type' => 'Trade',
            'activity_sub_type' => $side,
            'description' => $side.' '.$symbol,
            'direction' => $cash < 0 ? 'DEBIT' : 'CREDIT',
            'symbol' => $symbol,
            'name' => $name,
            'currency' => $ccy,
            'quantity' => $qty,
            'unit_price' => $px,
            'commission' => 0,
            'net_cash_amount' => $cash,
            'category' => 'trade',
            'balance' => null,
            'source' => 'demo',
            'raw_type' => $side,
            'aft_type' => null,
            'counter_symbol' => null,
            'security_id' => $secId,
        ];
    }
}
