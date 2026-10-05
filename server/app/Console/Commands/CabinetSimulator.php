<?php

namespace App\Console\Commands;

use App\Models\Cabinet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 柜机模拟器(本地模拟硬件)。
 *
 * 行为:
 * - 轮询服务端获取待执行命令(开门),按真实时序产生设备事件:
 *   借伞:door_opened → umbrella_dispensed
 *   还伞:door_opened → umbrella_inserted → door_closed
 * - 柜机"离线"时(管理端切换 online=false),事件写入本地 outbox 文件;
 *   恢复在线后,把缓存事件按 device_seq 批量补传,服务端核对去重。
 *
 * 事件携带:event_uid(幂等键)、device_seq(柜机本地序号)、occurred_at(设备时钟)。
 */
class CabinetSimulator extends Command
{
    protected $signature = 'cabinet:run {--once : 只跑一轮(调试用)}';

    protected $description = '运行柜机模拟器:执行开门命令、产生设备事件、支持离线缓存与补传';

    /** @var array<string, array{seq: int, outbox: array}> 每柜机的本地状态 */
    private array $state = [];

    /** @var array<int, array{fire_at: float, cabinet: string, event: array}> 待触发事件队列 */
    private array $queue = [];

    private string $apiBase;
    private string $stateDir;

    public function handle(): int
    {
        $this->apiBase = rtrim(config('umbrella.simulator.api_base'), '/');
        $this->stateDir = config('umbrella.simulator.state_dir')
            ?: storage_path('app/simulator');
        if (! is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0755, true);
        }

        $cabinets = Cabinet::orderBy('code')->pluck('code')->all();
        if (empty($cabinets)) {
            $this->error('没有柜机,请先迁移并执行 db:seed');

            return self::FAILURE;
        }

        foreach ($cabinets as $code) {
            $this->loadState($code);
        }

        $this->info('柜机模拟器已启动: '.implode(', ', $cabinets));

        $tickMs = (int) config('umbrella.simulator.tick_ms', 500);
        $tick = 0;

        do {
            $started = microtime(true);

            foreach ($cabinets as $code) {
                $this->pollCabinet($code);
            }
            $this->fireDueEvents();

            $tick++;

            if ($this->option('once')) {
                break;
            }

            $elapsedMs = (microtime(true) - $started) * 1000;
            usleep((int) max(50_000, ($tickMs * 1000) - ($elapsedMs * 1000)));
        } while (true);

        return self::SUCCESS;
    }

    /** 轮询一台柜机:刷新在线状态、领取新命令 */
    private function pollCabinet(string $code): void
    {
        $response = $this->get('/device/poll', ['cabinet_code' => $code]);
        if (! $response) {
            return; // 服务端暂不可达,下轮再试
        }

        $online = (bool) ($response['online'] ?? true);

        // 在线且 outbox 有缓存 → 先批量补传离线期间的事件
        if ($online && ! empty($this->state[$code]['outbox'])) {
            $this->flushOutbox($code);
        }

        foreach ($response['commands'] ?? [] as $command) {
            $this->scheduleCommand($code, $command);
            $this->post("/device/commands/{$command['id']}/ack");
        }
    }

    /** 把命令编排成一组带触发时间的设备事件 */
    private function scheduleCommand(string $code, array $command): void
    {
        $cfg = config('umbrella.simulator');
        $now = microtime(true);
        $slotLabel = $command['slot_label'];
        $umbrellaCode = $command['payload']['umbrella_code'] ?? null;
        $isReturn = ($command['ref_type'] ?? null) === 'return_session';

        $events = [
            ['delay_ms' => $cfg['door_open_delay_ms'], 'type' => 'door_opened', 'umbrella_code' => null],
        ];

        if ($isReturn) {
            // 用户放入雨伞 → RFID 确认入柜 → 关门,归还才成立
            $events[] = ['delay_ms' => $cfg['insert_delay_ms'], 'type' => 'umbrella_inserted', 'umbrella_code' => $umbrellaCode];
            $events[] = ['delay_ms' => $cfg['close_delay_ms'], 'type' => 'door_closed', 'umbrella_code' => null];
        } else {
            // 用户取走雨伞 → 借出成立
            $events[] = ['delay_ms' => $cfg['take_delay_ms'], 'type' => 'umbrella_dispensed', 'umbrella_code' => $umbrellaCode];
        }

        foreach ($events as $event) {
            $this->queue[] = [
                'fire_at' => $now + ($event['delay_ms'] / 1000),
                'cabinet' => $code,
                'event' => [
                    'type' => $event['type'],
                    'slot_label' => $slotLabel,
                    'umbrella_code' => $event['umbrella_code'],
                ],
            ];
        }

        $this->line("  [{$code}] 命令 #{$command['id']} 已受理(柜门 {$slotLabel})");
    }

    /** 触发到期事件:在线直传,离线缓存 */
    private function fireDueEvents(): void
    {
        $now = microtime(true);

        foreach ($this->queue as $i => $item) {
            if ($item['fire_at'] > $now) {
                continue;
            }
            unset($this->queue[$i]);

            $code = $item['cabinet'];
            $state = &$this->state[$code];
            $state['seq']++;

            $event = array_merge($item['event'], [
                'event_uid' => 'E'.strtoupper(Str::random(12)),
                'cabinet_code' => $code,
                'device_seq' => $state['seq'],
                'occurred_at' => now()->toIso8601String(),
            ]);

            if ($this->cabinetOnline($code)) {
                $result = $this->post('/device/events', $event);
                $this->line(sprintf(
                    '  [%s] 事件 #%d %s → %s',
                    $code, $event['device_seq'], $event['type'], $result['result'] ?? '发送失败'
                ));
            } else {
                $state['outbox'][] = $event;
                $this->line("  [{$code}] 离线,事件 #{$event['device_seq']} {$event['type']} 已缓存(outbox: ".count($state['outbox']).')');
            }

            $this->saveState($code);
        }

        $this->queue = array_values($this->queue);
    }

    /** 恢复在线:把离线缓存的事件批量补传,成功后清空 outbox */
    private function flushOutbox(string $code): void
    {
        $outbox = $this->state[$code]['outbox'];
        $result = $this->post('/device/events/batch', ['events' => $outbox]);

        if ($result !== null) {
            $count = count($outbox);
            $this->state[$code]['outbox'] = [];
            $this->saveState($code);
            $this->line("  [{$code}] 已补传 {$count} 条离线事件");
        }
    }

    private function cabinetOnline(string $code): bool
    {
        // 每次以服务端记录为准(管理端可切换);poll 已刷新,这里直接查库以简化
        return (bool) Cabinet::where('code', $code)->value('online');
    }

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = Http::timeout(3)->get($this->apiBase.$path, $query);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function post(string $path, array $data = []): ?array
    {
        try {
            $response = Http::timeout(3)->post($this->apiBase.$path, $data);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function statePath(string $code): string
    {
        return $this->stateDir.'/'.$code.'.json';
    }

    private function loadState(string $code): void
    {
        $path = $this->statePath($code);
        $this->state[$code] = is_file($path)
            ? (json_decode(file_get_contents($path), true) ?: ['seq' => 0, 'outbox' => []])
            : ['seq' => 0, 'outbox' => []];
    }

    private function saveState(string $code): void
    {
        file_put_contents($this->statePath($code), json_encode($this->state[$code], JSON_PRETTY_PRINT));
    }
}
