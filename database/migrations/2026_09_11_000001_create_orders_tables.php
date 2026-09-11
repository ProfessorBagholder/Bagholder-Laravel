<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('created_at');
            $table->string('account_id');
            $table->string('account')->nullable();
            $table->string('security_id');
            $table->string('symbol')->nullable();
            $table->string('currency')->nullable();
            $table->string('side');
            $table->string('type');
            $table->double('quantity');
            $table->double('limit_price')->nullable();
            $table->double('stop_price')->nullable();
            $table->string('tif');
            $table->text('stop_loss')->nullable();
            $table->text('take_profit')->nullable();
            $table->string('status');
            $table->string('ws_order_id')->nullable();
            $table->text('error')->nullable();
            $table->text('request')->nullable();
            $table->string('updated_at')->nullable();
            $table->string('source')->nullable();
            $table->string('ws_status')->nullable();
            $table->double('filled_qty')->nullable();
            $table->double('avg_fill')->nullable();
            $table->string('submitted_at')->nullable();
            $table->string('expires_at')->nullable();
            $table->string('parent_id')->nullable();
            $table->string('role')->nullable();
            $table->string('exchange')->nullable();
        });

        Schema::create('brackets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('created_at');
            $table->string('account_id');
            $table->string('security_id');
            $table->string('symbol')->nullable();
            $table->string('currency')->nullable();
            $table->double('quantity')->nullable();
            $table->string('tif')->nullable();
            $table->string('sl_kind')->nullable();
            $table->double('sl_price')->nullable();
            $table->double('sl_trail')->nullable();
            $table->string('sl_trail_unit')->nullable();
            $table->string('sl_order_id')->nullable();
            $table->integer('sl_native')->nullable();
            $table->string('sl_mode')->nullable();
            $table->double('high_water')->nullable();
            $table->double('tp_price')->nullable();
            $table->string('tp_order_id')->nullable();
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->nullable();
            $table->string('moved_at')->nullable();
            $table->string('armed_at')->nullable();
            $table->integer('seen_held')->nullable();
            $table->string('missed_at')->nullable();
            $table->string('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brackets');
        Schema::dropIfExists('orders');
    }
};
