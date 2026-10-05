<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\CabinetCommand;
use App\Services\EventIngestionService;
use App\Services\ExpirationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 设备侧接口:柜机模拟器轮询命令、上报事件(含离线补传)。
 */
class DeviceController extends Controller
{
    public function __construct(
        private EventIngestionService $ingestion,
        private ExpirationService $expiration,
    ) {}

    /** 单条事件上报 */
    public function event(Request $request): JsonResponse
    {
        $data = $this->validateEvent($request);

        $record = $this->ingestion->applyOne($data);

        return response()->json([
            'event_uid' => $record->event_uid,
            'result' => $record->result,
            'note' => $record->note,
            'duplicate_delivery' => ! $record->wasRecentlyCreated,
        ]);
    }

    /** 批量补传:柜机离线期间缓存的事件,恢复后一次性上传核对 */
    public function batch(Request $request): JsonResponse
    {
        $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.event_uid' => ['required', 'string'],
            'events.*.cabinet_code' => ['required', 'string'],
            'events.*.type' => ['required', 'string'],
            'events.*.device_seq' => ['required', 'integer', 'min:0'],
            'events.*.occurred_at' => ['required', 'string'],
        ]);

        return response()->json([
            'results' => $this->ingestion->applyBatch($request->input('events')),
        ]);
    }

    /** 柜机轮询:返回在线标志(模拟器据此决定直传还是缓存)与待执行命令 */
    public function poll(Request $request): JsonResponse
    {
        $request->validate(['cabinet_code' => ['required', 'string']]);

        $this->expiration->sweep(); // 轮询顺带做过期清理,模拟器无需单独调用

        $cabinet = Cabinet::where('code', $request->query('cabinet_code'))->firstOrFail();

        $commands = CabinetCommand::where('cabinet_id', $cabinet->id)
            ->where('status', CabinetCommand::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->map(fn (CabinetCommand $command) => [
                'id' => $command->id,
                'type' => $command->type,
                'ref_type' => $command->ref_type,
                'slot_label' => $command->slot->label,
                'payload' => $command->payload,
                'expires_at' => $command->expires_at->toIso8601String(),
            ]);

        return response()->json([
            'cabinet_code' => $cabinet->code,
            'online' => $cabinet->online,
            'commands' => $commands,
        ]);
    }

    /** 命令执行回执 */
    public function ack(int $commandId): JsonResponse
    {
        $command = CabinetCommand::findOrFail($commandId);
        if ($command->status === CabinetCommand::STATUS_PENDING) {
            $command->status = CabinetCommand::STATUS_DONE;
            $command->save();
        }

        return response()->json(['ok' => true]);
    }

    /** 过期清理(模拟器每轮调用;各业务接口也会惰性清理) */
    public function sweep(): JsonResponse
    {
        $this->expiration->sweep();

        return response()->json(['ok' => true]);
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'event_uid' => ['required', 'string', 'max:64'],
            'cabinet_code' => ['required', 'string'],
            'type' => ['required', 'string', 'in:door_opened,door_closed,umbrella_dispensed,umbrella_inserted'],
            'slot_label' => ['nullable', 'string'],
            'umbrella_code' => ['nullable', 'string'],
            'device_seq' => ['required', 'integer', 'min:0'],
            'occurred_at' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
        ]);
    }
}
