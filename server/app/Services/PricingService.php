<?php

namespace App\Services;

use App\Models\Order;

class PricingService
{
    /**
     * 按 30 分钟为一个计费单元向上取整。
     * 起点为柜门实际放出雨伞的事件时间,终点为归还确认(入柜+关门)的事件时间。
     */
    public function feeCents(\DateTimeInterface $borrowedAt, \DateTimeInterface $returnedAt): int
    {
        $seconds = max(0, $returnedAt->getTimestamp() - $borrowedAt->getTimestamp());
        $units = (int) max(
            (int) config('umbrella.min_billable_units', 1),
            (int) ceil($seconds / 1800)
        );

        return $units * (int) config('umbrella.rate_per_30min_cents', 50);
    }

    public function quoteForOrder(Order $order, ?\DateTimeInterface $until = null): ?int
    {
        if ($order->borrowed_at === null) {
            return null;
        }

        return $this->feeCents($order->borrowed_at, $until ?? now());
    }
}
