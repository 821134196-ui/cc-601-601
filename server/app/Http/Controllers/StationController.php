<?php

namespace App\Http\Controllers;

use App\Models\Station;

class StationController extends Controller
{
    /** 站点列表：可借雨伞数 + 空柜位数（均由柜位/雨伞实际状态实时推导，与柜机事件一致） */
    public function index()
    {
        $stations = Station::withCount([
            'slots as empty_slots' => fn ($q) => $q->where('status', 'empty'),
            'slots as total_slots',
        ])->orderBy('code')->get();

        return $stations->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'address' => $s->address,
            'cabinet_online' => $s->cabinet_online,
            'available_umbrellas' => $s->availableCount(),
            'empty_slots' => $s->empty_slots,
            'total_slots' => $s->total_slots,
        ]);
    }
}
