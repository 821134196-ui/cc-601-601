<?php

namespace Database\Seeders;

use App\Models\Slot;
use App\Models\Station;
use App\Models\Umbrella;
use Illuminate\Database\Seeder;

class UmbrellaSeeder extends Seeder
{
    public function run(): void
    {
        // [站点名, 柜位数, 初始雨伞数] —— 老街口站初始满柜，便于演示"满柜换站"
        $plan = [
            ['阳光社区站', 'ST-A', '阳光社区服务中心门口', 4, 3],
            ['河畔广场站', 'ST-B', '河畔广场东侧出入口', 3, 1],
            ['老街口站',   'ST-C', '老街与建设路交叉口', 2, 2],
        ];

        $umbrellaNo = 1;
        foreach ($plan as [$name, $code, $address, $slotCount, $umbrellaCount]) {
            $station = Station::create([
                'name' => $name, 'code' => $code, 'address' => $address,
            ]);

            for ($i = 1; $i <= $slotCount; $i++) {
                $label = $code.'-'.str_pad($i, 2, '0', STR_PAD_LEFT);
                if ($i <= $umbrellaCount) {
                    $umbrella = Umbrella::create([
                        'code' => 'UMB-'.str_pad($umbrellaNo++, 4, '0', STR_PAD_LEFT),
                        'status' => Umbrella::STATUS_IN_SLOT,
                        'station_id' => $station->id,
                    ]);
                    Slot::create([
                        'station_id' => $station->id, 'label' => $label,
                        'status' => 'occupied', 'umbrella_id' => $umbrella->id,
                    ]);
                    $umbrella->update(['slot_id' => Slot::where('label', $label)->value('id')]);
                } else {
                    Slot::create([
                        'station_id' => $station->id, 'label' => $label, 'status' => 'empty',
                    ]);
                }
            }
        }
    }
}
