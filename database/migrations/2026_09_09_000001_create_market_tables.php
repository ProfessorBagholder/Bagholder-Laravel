<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->string('symbol')->primary();
            $table->double('price')->nullable();
            $table->double('price_change')->nullable();
            $table->double('percent_change')->nullable();
            $table->double('prev_close')->nullable();
            $table->double('dividend_amount')->nullable();
            $table->string('dividend_frequency')->nullable();
            $table->string('ex_dividend_date')->nullable();
            $table->string('source')->nullable();
            $table->string('fetched_at')->nullable();
        });

        Schema::create('distributions', function (Blueprint $table) {
            $table->string('symbol');
            $table->string('ex_date');
            $table->string('pay_date')->nullable();
            $table->double('amount');
            $table->string('currency')->nullable();
            $table->string('source')->default('tmx');
            $table->primary(['symbol', 'ex_date', 'source']);
        });

        Schema::create('price_bars', function (Blueprint $table) {
            $table->string('symbol');
            $table->string('tf');
            $table->integer('ts');
            $table->double('open')->nullable();
            $table->double('high')->nullable();
            $table->double('low')->nullable();
            $table->double('close');
            $table->double('volume')->nullable();
            $table->string('source')->default('');
            $table->primary(['symbol', 'tf', 'ts']);
        });

        Schema::create('benchmark_prices', function (Blueprint $table) {
            $table->string('symbol');
            $table->string('date');
            $table->double('close');
            $table->primary(['symbol', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_prices');
        Schema::dropIfExists('price_bars');
        Schema::dropIfExists('distributions');
        Schema::dropIfExists('quotes');
    }
};
