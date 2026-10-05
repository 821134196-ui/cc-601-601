<?php

namespace App\Http\Controllers;

use App\Models\DeviceEvent;
use App\Services\EventProcessor;
use Illuminate\Http\Request;

class DeviceEventController extends Controller
{
    public function __construct(private EventProcessor $processor) {}

    /** 柜机单条事件上报（在线时） */
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_uid' => 'required|string|max:100',
            'type' => 'required|in:umbrella_dispensed,umbrella_returned',
            'station_id' => 'required|exists:stations,id',
            'payload' => 'array',
            'occurred_at' => 'required|date',
        ]);

        $result = $this->processor->process(
            $data['event_uid'], $data['type'], $data['station_id'],
            $data['payload'] ?? [], $data['occurred_at']
        );

        return response()->json([
            'duplicate' => $result['duplicate'],
            'result' => $result['event']->result,
        ], $result['duplicate'] ? 200 : 201);
    }

    /** 柜机离线恢复后批量补传：逐条幂等处理并回报结果，供柜机端核对事件记录 */
    public function batch(Request $request)
    {
        $data = $request->validate([
            'events' => 'required|array|max:500',
            'events.*.event_uid' => 'required|string|max:100',
            'events.*.type' => 'required|in:umbrella_dispensed,umbrella_returned',
            'events.*.station_id' => 'required|exists:stations,id',
            'events.*.payload' => 'array',
            'events.*.occurred_at' => 'required|date',
        ]);

        return ['results' => $this->processor->processBatch($data['events'])];
    }

    /** 事件记录（管理端核对用） */
    public function index()
    {
        return DeviceEvent::orderByDesc('id')->limit(100)->get();
    }
}
