<?php

namespace App\Services;

use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;

/**
 * 站点容量统计:可借雨伞数、空柜位数 —— 居民借还前查询的核心数据。
 */
class StationStatsService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return Station::with('cabinet')->orderBy('code')->get()
            ->map(fn (Station $station) => $this->forStation($station))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forStation(Station $station): array
    {
        $cabinet = $station->cabinet;
        $slots = $cabinet ? Slot::where('cabinet_id', $cabinet->id)->with('umbrella')->get() : collect();

        $occupied = $slots->where('state', Slot::STATE_OCCUPIED);

        return [
            'code' => $station->code,
            'name' => $station->name,
            'address' => $station->address,
            'online' => (bool) ($cabinet?->online),
            'cabinet_code' => $cabinet?->code,
            'total_slots' => $slots->count(),
            // 可借 = 在架且状态为可借的雨伞(维修/清洁中的不占可借数)
            'available_umbrellas' => $occupied->filter(
                fn ($slot) => $slot->umbrella?->status === Umbrella::STATUS_IN_SLOT
            )->count(),
            'maintenance' => $occupied->filter(
                fn ($slot) => in_array($slot->umbrella?->status, [Umbrella::STATUS_CLEANING, Umbrella::STATUS_REPAIR], true)
            )->count(),
            // 空位 = 空柜位且未被还伞会话预留
            'empty_slots' => $slots->filter(fn ($slot) => $slot->isAvailableForReturn())->count(),
            'reserved_slots' => $slots->whereNotNull('reserved_by_return_id')->count(),
        ];
    }
}
