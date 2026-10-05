<?php

namespace Database\Seeders;

use App\Models\Cabinet;
use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Illuminate\Database\Seeder;

/**
 * 演示数据:三个社区站点,柜机 1:1,柜位与雨伞按约 2/3 填充。
 * 幂等:重复执行不会产生重复数据。
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $plan = [
            ['code' => 'ST-WUTONG', 'name' => '梧桐里站', 'address' => '梧桐里社区东门', 'slots' => 6, 'umbrellas' => 4],
            ['code' => 'ST-HEPAN', 'name' => '河畔站', 'address' => '滨河步道 3 号口', 'slots' => 4, 'umbrellas' => 3],
            ['code' => 'ST-ZHONGLOU', 'name' => '钟楼站', 'address' => '钟楼广场北侧', 'slots' => 8, 'umbrellas' => 5],
        ];

        $umbrellaSeq = 1001;

        foreach ($plan as $item) {
            $station = Station::firstOrCreate(
                ['code' => $item['code']],
                ['name' => $item['name'], 'address' => $item['address']]
            );

            $cabinet = Cabinet::firstOrCreate(
                ['station_id' => $station->id],
                ['code' => 'CAB-'.$item['code'], 'online' => true]
            );

            for ($i = 1; $i <= $item['slots']; $i++) {
                $slot = Slot::firstOrCreate(
                    ['cabinet_id' => $cabinet->id, 'label' => 'A'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)],
                    ['state' => Slot::STATE_EMPTY]
                );

                if ($i <= $item['umbrellas'] && $slot->state === Slot::STATE_EMPTY && $slot->umbrella_id === null) {
                    $umbrella = Umbrella::firstOrCreate(
                        ['code' => 'UMB-'.$umbrellaSeq],
                        ['status' => Umbrella::STATUS_IN_SLOT, 'slot_id' => $slot->id]
                    );
                    $slot->state = Slot::STATE_OCCUPIED;
                    $slot->umbrella_id = $umbrella->id;
                    $slot->save();
                    $umbrellaSeq++;
                }
            }
        }
    }
}
