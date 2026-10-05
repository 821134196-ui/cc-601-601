<?php

namespace Tests\Feature;

use App\Models\Slot;
use App\Models\Umbrella;
use Tests\TestCase;

/**
 * 对账:柜位占用、雨伞位置、订单状态三方一致;
 * 人为制造镜像漂移时,对账必须能报出异常。
 */
class ReconcileTest extends TestCase
{
    public function test_consistent_after_mixed_operations(): void
    {
        ['station' => $stationA, 'cabinet' => $cabinetA, 'umbrellas' => $umbrellasA] = $this->makeStation('ST-A', 4, 3);
        ['station' => $stationB, 'cabinet' => $cabinetB] = $this->makeStation('ST-B', 3, 0);

        // 借两把(按柜位顺序分配前两把):一把还到 B,一把仍借出;剩下那把登记维修
        ['order_no' => $o1, 'umbrella_code' => $u1] = $this->borrowUmbrella($stationA, $cabinetA, '用户一');
        ['order_no' => $o2] = $this->borrowUmbrella($stationA, $cabinetA, '用户二');
        $this->returnUmbrella($o1, $stationB, $cabinetB, $u1);

        $remaining = $umbrellasA[2];
        $this->postJson("/api/admin/umbrellas/{$remaining->code}/action", ['action' => 'start_repair'])->assertOk();

        $report = $this->getJson('/api/admin/reconcile')->assertOk()->json();
        $this->assertTrue($report['consistent'], implode(';', $report['anomalies']));

        // 站点计数与对账口径一致(A 站 4 柜位:维修占 1、借出腾空 2、原空 1)
        $stats = collect($this->getJson('/api/stations')->json('stations'));
        $this->assertSame(0, $stats->firstWhere('code', 'ST-A')['available_umbrellas']);
        $this->assertSame(1, $stats->firstWhere('code', 'ST-A')['maintenance']);
        $this->assertSame(3, $stats->firstWhere('code', 'ST-A')['empty_slots']);
        $this->assertSame(1, $stats->firstWhere('code', 'ST-B')['available_umbrellas']);
        $this->assertSame(2, $stats->firstWhere('code', 'ST-B')['empty_slots']);
    }

    public function test_mirror_drift_is_flagged(): void
    {
        ['cabinet' => $cabinet, 'umbrellas' => [$umbrella]] = $this->makeStation('ST-A', 2, 1);

        // 人为破坏镜像:柜位标空但雨伞仍登记在该柜位
        Slot::where('cabinet_id', $cabinet->id)->where('label', 'A01')
            ->update(['state' => 'empty', 'umbrella_id' => null]);

        $report = $this->getJson('/api/admin/reconcile')->assertOk()->json();
        $this->assertFalse($report['consistent']);
        $this->assertNotEmpty($report['anomalies']);
    }
}
