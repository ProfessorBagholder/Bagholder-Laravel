<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Table(key: 'id', keyType: 'string', incrementing: false, timestamps: false)]
#[RouteKey('id')]
#[Guarded([])]
class Activity extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
            'commission' => 'float',
            'net_cash_amount' => 'float',
            'balance' => 'float',
        ];
    }

    #[Scope]
    protected function newest(Builder $query): void
    {
        $query->orderByDesc('transaction_date')->orderByDesc('occurred_at');
    }

    public function toJournal(): array
    {
        return [
            'id' => $this->id,
            'canonicalId' => $this->canonical_id,
            'occurredAt' => $this->occurred_at ?: '',
            'transactionDate' => $this->transaction_date ?: '',
            'settlementDate' => $this->settlement_date ?: $this->transaction_date ?: '',
            'accountId' => $this->account_id ?: '',
            'bookId' => $this->book_id ?: $this->account_id ?: '',
            'fifoId' => $this->fifo_id ?: $this->account_id ?: '',
            'accountType' => $this->account_type ?: '',
            'activityType' => $this->activity_type ?: '',
            'activitySubType' => $this->activity_sub_type ?: '',
            'description' => $this->description ?: '',
            'direction' => $this->direction ?: '',
            'symbol' => $this->symbol ?: '',
            'name' => $this->name ?: '',
            'currency' => $this->currency ?: '',
            'quantity' => $this->quantity,
            'unitPrice' => $this->unit_price,
            'commission' => $this->commission,
            'netCashAmount' => $this->net_cash_amount,
            'category' => $this->category ?: '',
            'balance' => $this->balance,
            'source' => $this->source ?: '',
            'rawType' => $this->raw_type ?: '',
            'aftType' => $this->aft_type ?: '',
            'counterSymbol' => $this->counter_symbol ?: '',
            'securityId' => $this->security_id,
        ];
    }
}
