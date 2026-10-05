<?php

namespace App\Services;

use App\Models\CabinetCommand;
use App\Models\Order;
use App\Models\ReturnSession;
use App\Models\Umbrella;
use Illuminate\Support\Facades\DB;

/**
 * 过期清理:借伞开门命令超时未执行 → 订单取消、雨伞恢复在架;
 * 还伞会话超时 → 释放柜位预留、订单回到借出状态。
 */
class ExpirationService
{
    public function __construct(private ReturnService $returns) {}

    public function sweep(): void
    {
        $this->expireBorrowCommands();
        $this->expireReturnSessions();
    }

    private function expireBorrowCommands(): void
    {
        $commands = CabinetCommand::where('status', CabinetCommand::STATUS_PENDING)
            ->where('ref_type', 'order')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($commands as $command) {
            DB::transaction(function () use ($command) {
                $command->status = CabinetCommand::STATUS_EXPIRED;
                $command->save();

                $order = Order::whereKey($command->ref_id)->lockForUpdate()->first();
                if ($order && $order->status === Order::STATUS_DISPENSING) {
                    $order->status = Order::STATUS_CANCELLED;
                    $order->save();

                    // 伞没放出去,恢复在架可借
                    Umbrella::whereKey($order->umbrella_id)
                        ->where('status', Umbrella::STATUS_DISPATCHING)
                        ->update(['status' => Umbrella::STATUS_IN_SLOT]);
                }
            });
        }
    }

    private function expireReturnSessions(): void
    {
        $sessions = ReturnSession::where('status', ReturnSession::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($sessions as $session) {
            DB::transaction(function () use ($session) {
                $this->returns->releaseSession(
                    ReturnSession::whereKey($session->id)->lockForUpdate()->firstOrFail(),
                    ReturnSession::STATUS_EXPIRED
                );

                CabinetCommand::where('ref_type', 'return_session')
                    ->where('ref_id', $session->id)
                    ->where('status', CabinetCommand::STATUS_PENDING)
                    ->update(['status' => CabinetCommand::STATUS_EXPIRED]);
            });
        }
    }
}
