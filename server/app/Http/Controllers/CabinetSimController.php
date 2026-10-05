<?php

namespace App\Http\Controllers;

use App\Models\DoorCommand;
use App\Models\SimOutbox;
use App\Models\Station;
use App\Services\SimCabinet;
use Illuminate\Http\Request;

/**
 * 柜机模拟器接口：模拟柜机侧的物理动作与网络状态。
 */
class CabinetSimController extends Controller
{
    public function __construct(private SimCabinet $cabinet) {}

    /** 柜机状态：在线与否、待处理的开门指令、离线队列长度 */
    public function state(Station $station)
    {
        return [
            'station' => $station->only(['id', 'name', 'code', 'cabinet_online']),
            'pending_commands' => DoorCommand::with(['slot:id,label', 'order:id,order_no,umbrella_id'])
                ->where('station_id', $station->id)->where('status', 'pending')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'purpose' => $c->purpose,
                    'slot_label' => $c->slot?->label,
                    'slot_id' => $c->slot_id,
                    'order_no' => $c->order?->order_no,
                ]),
            'queued_events' => SimOutbox::where('station_id', $station->id)->whereNull('sent_at')->count(),
        ];
    }

    /** 切换柜机在线/离线 */
    public function toggleOnline(Request $request, Station $station)
    {
        $data = $request->validate(['online' => 'required|boolean']);

        return $this->cabinet->setOnline($station, $data['online']);
    }

    /** 模拟用户从打开的柜门取走雨伞 */
    public function take(Request $request, Station $station)
    {
        $data = $request->validate(['slot_id' => 'required|exists:slots,id']);

        try {
            return $this->cabinet->takeUmbrella($station, $data['slot_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => '该柜位没有待取伞的开门指令'], 422);
        }
    }

    /** 模拟用户放入雨伞并关闭柜门（设备确认入柜） */
    public function place(Request $request, Station $station)
    {
        $data = $request->validate(['slot_id' => 'required|exists:slots,id']);

        try {
            return $this->cabinet->placeUmbrella($station, $data['slot_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => '该柜位没有待归还的开门指令'], 422);
        }
    }

    /** 恢复在线后补传离线期间积压的事件，返回逐条处理结果供核对 */
    public function sync(Station $station)
    {
        return ['results' => $this->cabinet->sync($station)];
    }
}
