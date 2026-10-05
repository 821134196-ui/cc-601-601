<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 社区共享雨伞领域表。
 *
 * 一致性设计要点:
 * - device_events.event_uid 唯一:设备事件(含离线补传)天然幂等,重复投递不产生二次副作用。
 * - payments.order_id 唯一:一笔订单只会有一张支付单,重复回执不会重复收费。
 * - slots.reserved_by_return_id:还伞会话占用空柜位,防止两位用户被分到同一柜位。
 * - umbrellas.status 状态机 + 订单状态机守卫:所有迁移在事务内校验前置状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // ST-WUTONG
            $table->string('name');                    // 梧桐里站
            $table->string('address')->default('');
            $table->timestamps();
        });

        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code')->unique();          // CAB-WUTONG-1
            $table->boolean('online')->default(true);  // 柜机在线状态(管理端可切换,模拟离线)
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->string('label');                   // A01
            $table->string('state')->default('empty'); // empty | occupied
            $table->foreignId('umbrella_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('reserved_by_return_id')->nullable(); // 还伞会话预留
            $table->timestamps();

            $table->unique(['cabinet_id', 'label']);
            $table->index(['state', 'reserved_by_return_id']);
        });

        Schema::create('umbrellas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // UMB-1001 伞号
            // in_slot 在架可借 | dispatching 正在放出 | borrowed 借出中
            // cleaning 清洁中 | repair 维修中 | lost 已丢失
            $table->string('status')->default('in_slot');
            $table->foreignId('slot_id')->nullable()->constrained()->nullOnDelete(); // 所在柜位(在架时)
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('user_name');
            $table->foreignId('umbrella_id')->constrained();
            $table->foreignId('borrow_station_id')->constrained('stations');
            $table->foreignId('return_station_id')->nullable()->constrained('stations');
            // dispensing 待放出 | borrowed 借出中 | returning 还伞中 | completed 已完成 | cancelled 已取消
            $table->string('status');
            $table->timestamp('borrowed_at')->nullable();  // 计费起点 = 柜门实际放出雨伞的事件时间
            $table->timestamp('returned_at')->nullable();  // 归还确认 = 入柜+关门事件确认时间
            $table->unsignedInteger('fee_cents')->nullable();
            $table->timestamps();

            $table->index(['user_name', 'status']);
            $table->index('status');
        });

        Schema::create('return_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_no')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained();
            $table->foreignId('slot_id')->nullable()->constrained();
            // active | completed | expired | cancelled
            $table->string('status')->default('active');
            $table->timestamp('inserted_at')->nullable();    // 设备确认雨伞入柜的事件时间
            $table->timestamp('door_closed_at')->nullable(); // 柜门关闭的事件时间
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('cabinet_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained();
            $table->string('type');                        // open_door
            // pending 待执行 | done 已执行 | expired 已过期
            $table->string('status')->default('pending');
            $table->string('ref_type');                    // order | return_session
            $table->unsignedBigInteger('ref_id');
            $table->json('payload')->nullable();           // {umbrella_code}
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['cabinet_id', 'status']);
        });

        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uid')->unique();         // 设备侧唯一 ID —— 幂等键
            $table->foreignId('cabinet_id')->constrained();
            $table->foreignId('slot_id')->nullable()->constrained();
            $table->foreignId('umbrella_id')->nullable()->constrained();
            $table->string('type');                        // door_opened | door_closed | umbrella_dispensed | umbrella_inserted
            $table->unsignedBigInteger('device_seq');      // 柜机本地序号,离线补传时按此排序
            $table->timestamp('occurred_at');              // 设备本地时钟
            $table->timestamp('received_at');              // 服务端接收时间
            // applied 已应用 | duplicate 重复投递 | conflict 与现有状态冲突(已记录未应用)
            $table->string('result');
            $table->string('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['cabinet_id', 'device_seq']);
            $table->index('type');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no')->unique();
            $table->foreignId('order_id')->unique()->constrained(); // 一单一付,重复回执不会重复收费
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('pending');  // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no')->unique();        // 支付回执幂等键
            $table->string('payment_no');
            $table->unsignedInteger('amount_cents');
            // applied | duplicate | mismatch
            $table->string('result');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('cabinet_commands');
        Schema::dropIfExists('return_sessions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('umbrellas');
        Schema::dropIfExists('slots');
        Schema::dropIfExists('cabinets');
        Schema::dropIfExists('stations');
    }
};
