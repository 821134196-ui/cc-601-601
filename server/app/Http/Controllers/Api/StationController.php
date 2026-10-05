<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\ExpirationService;
use App\Services\StationStatsService;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    public function __construct(
        private StationStatsService $stats,
        private ExpirationService $expiration,
    ) {}

    /** 站点总览:各站可借雨伞数与空柜位数 */
    public function index(): JsonResponse
    {
        $this->expiration->sweep();

        return response()->json(['stations' => $this->stats->all()]);
    }

    /** 单站详情:柜位级占用情况 */
    public function show(string $code): JsonResponse
    {
        $this->expiration->sweep();

        $station = Station::where('code', $code)->with('cabinet')->firstOrFail();

        $slots = $station->cabinet
            ? $station->cabinet->slots()->with('umbrella')->orderBy('label')->get()->map(fn ($slot) => [
                'label' => $slot->label,
                'state' => $slot->state,
                'reserved' => $slot->reserved_by_return_id !== null,
                'umbrella' => $slot->umbrella ? [
                    'code' => $slot->umbrella->code,
                    'status' => $slot->umbrella->status,
                ] : null,
            ])
            : [];

        return response()->json([
            'station' => $this->stats->forStation($station),
            'slots' => $slots,
        ]);
    }
}
