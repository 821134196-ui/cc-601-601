<?php

namespace App\Services;

use Carbon\CarbonInterface;

class PricingService
{
    /** 免费时长（分钟） */
    public const FREE_MINUTES = 30;

    /** 每小时费率（分），不足一小时按一小时计 */
    public const HOURLY_CENTS = 100;

    /** 单笔订单封顶（分） */
    public const CAP_CENTS = 2000;

    /**
     * 计费：以柜门实际放出雨伞的事件时间为起点、柜门关闭确认入柜的事件时间为终点。
     */
    public function feeFor(CarbonInterface $borrowedAt, CarbonInterface $returnedAt): int
    {
        $minutes = max(0, $borrowedAt->diffInMinutes($returnedAt, false));
        if ($minutes <= self::FREE_MINUTES) {
            return 0;
        }
        $fee = (int) (ceil($minutes / 60) * self::HOURLY_CENTS);

        return min($fee, self::CAP_CENTS);
    }
}
