<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->default('');
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            // 柜机在线状态（本地模拟）：离线时事件进入柜机端队列，恢复后补传
            $table->boolean('cabinet_online')->default(true);
            $table->timestamps();
        });

        Schema::create('umbrellas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // 伞号，如 UMB-0001
            $table->string('status')->default('in_slot'); // in_slot / borrowed / cleaning / repair / lost
            $table->foreignId('station_id')->nullable()->constrained('stations');
            $table->foreignId('slot_id')->nullable();  // 当前所在柜位（在柜时）
            $table->timestamps();
        });

        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations');
            $table->string('label');                    // 柜位编号，如 A01
            $table->string('status')->default('empty'); // empty / occupied / reserved / fault
            $table->foreignId('umbrella_id')->nullable()->constrained('umbrellas');
            $table->timestamps();
            $table->unique(['station_id', 'label']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('user_name');
            $table->foreignId('umbrella_id')->constrained('umbrellas');
            $table->foreignId('borrow_station_id')->constrained('stations');
            $table->foreignId('return_station_id')->nullable()->constrained('stations');
            // dispensing=等待柜门放伞 / active=借用中 / returning=等待入柜确认 / completed / cancelled
            $table->string('status');
            $table->timestamp('borrowed_at')->nullable();  // 计费起点：柜门实际放出雨伞的事件时间
            $table->timestamp('returned_at')->nullable();  // 计费终点：柜门关闭且确认入柜的事件时间
            $table->unsignedInteger('fee_cents')->default(0);
            $table->string('payment_status')->default('unpaid'); // unpaid / paid
            $table->timestamps();
        });

        Schema::create('door_commands', function (Blueprint $table)
        {
            $table->id();
            $table->foreignId('station_id')->constrained('stations');
            $table->foreignId('slot_id')->constrained('slots');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('purpose');                   // borrow / return
            $table->string('status')->default('pending'); // pending / done / expired
            $table->timestamps();
        });

        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uid')->unique();       // 柜机侧事件ID：幂等键，补传/重复不会重复处理
            $table->foreignId('station_id')->constrained('stations');
            $table->foreignId('slot_id')->nullable()->constrained('slots');
            $table->foreignId('umbrella_id')->nullable()->constrained('umbrellas');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->string('type');                      // umbrella_dispensed / umbrella_returned
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');            // 柜机侧发生时间（计费以此为准）
            $table->timestamp('processed_at')->nullable();
            $table->string('result')->default('processed'); // processed / duplicate / rejected
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('transaction_id')->unique();  // 支付回执幂等键
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('success');
            $table->json('receipt')->nullable();
            $table->timestamps();
        });

        // 柜机本地模拟：离线期间事件暂存于柜机端，恢复在线后批量补传
        Schema::create('sim_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations');
            $table->string('event_uid')->unique();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_outbox');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('door_commands');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('slots');
        Schema::dropIfExists('umbrellas');
        Schema::dropIfExists('stations');
    }
};
