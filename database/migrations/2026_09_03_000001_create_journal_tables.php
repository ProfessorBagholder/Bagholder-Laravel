<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('canonical_id')->nullable();
            $table->string('occurred_at')->nullable();
            $table->string('transaction_date');
            $table->string('settlement_date')->nullable();
            $table->string('account_id')->nullable();
            $table->string('book_id')->nullable();
            $table->string('fifo_id')->nullable();
            $table->string('account_type')->nullable();
            $table->string('activity_type')->nullable();
            $table->string('activity_sub_type')->nullable();
            $table->text('description')->nullable();
            $table->string('direction')->nullable();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('currency')->nullable();
            $table->double('quantity')->nullable();
            $table->double('unit_price')->nullable();
            $table->double('commission')->nullable();
            $table->double('net_cash_amount')->nullable();
            $table->string('category')->nullable();
            $table->double('balance')->nullable();
            $table->string('source')->nullable();
            $table->string('raw_type')->nullable();
            $table->string('aft_type')->nullable();
            $table->string('counter_symbol')->nullable();
            $table->string('security_id')->nullable();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('nickname')->nullable();
            $table->string('unified_account_type')->nullable();
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->double('net_liquidation_value')->nullable();
        });

        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->string('account_id')->nullable();
            $table->string('custodian_account_id')->nullable();
            $table->string('security_id')->nullable();
            $table->double('quantity')->nullable();
        });

        Schema::create('nav_history', function (Blueprint $table) {
            $table->string('account_id')->default('');
            $table->string('date');
            $table->double('equity')->nullable();
            $table->string('currency')->nullable();
            $table->double('net_deposits')->nullable();
            $table->primary(['account_id', 'date']);
        });

        Schema::create('securities', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->string('primary_exchange')->nullable();
            $table->string('primary_mic')->nullable();
            $table->string('currency')->nullable();
            $table->string('underlying_id')->nullable();
            $table->string('fetched_at')->nullable();
        });

        Schema::create('ws_sessions', function (Blueprint $table) {
            $table->id();
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_sessions');
        Schema::dropIfExists('securities');
        Schema::dropIfExists('nav_history');
        Schema::dropIfExists('balances');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('meta');
    }
};
