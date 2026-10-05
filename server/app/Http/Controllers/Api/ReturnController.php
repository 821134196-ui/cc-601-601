<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnSession;
use App\Services\ExpirationService;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(
        private ReturnService $returns,
        private ExpirationService $expiration,
    ) {}

    /**
     * 发起还伞:在目标站预留空柜位。
     * 满柜时返回 409 + 备选站点;换站后订单计费起点不变。
     */
    public function store(Request $request): JsonResponse
    {
        $this->expiration->sweep();

        $data = $request->validate([
            'order_no' => ['required', 'string'],
            'station_code' => ['required', 'string'],
        ]);

        ['session' => $session, 'slot' => $slot, 'reused' => $reused] =
            $this->returns->createReturnSession($data['order_no'], $data['station_code']);

        return response()->json([
            'session' => $this->serialize($session->fresh()),
            'slot_label' => $slot->label,
            'reused' => $reused,
            'message' => "柜门 {$slot->label} 即将打开,请放入雨伞并关好柜门",
        ], $reused ? 200 : 201);
    }

    public function show(string $sessionNo): JsonResponse
    {
        $this->expiration->sweep();

        $session = ReturnSession::where('session_no', $sessionNo)->firstOrFail();

        return response()->json(['session' => $this->serialize($session)]);
    }

    private function serialize(ReturnSession $session): array
    {
        $session->loadMissing(['station', 'slot', 'order']);

        return [
            'session_no' => $session->session_no,
            'status' => $session->status,
            'station_code' => $session->station->code,
            'station_name' => $session->station->name,
            'slot_label' => $session->slot?->label,
            'order_no' => $session->order->order_no,
            'order_status' => $session->order->status,
            'inserted_at' => $session->inserted_at?->toIso8601String(),
            'door_closed_at' => $session->door_closed_at?->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }
}
