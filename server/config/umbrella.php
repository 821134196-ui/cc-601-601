<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 计费规则
    |--------------------------------------------------------------------------
    | 计费起点 = 柜门实际放出雨伞的设备事件时间(orders.borrowed_at),
    | 与用户在哪个站归还无关 —— 满柜换站后计费起点继续保留。
    */
    'rate_per_30min_cents' => env('UMBRELLA_RATE_PER_30MIN_CENTS', 50), // 每 30 分钟 0.5 元
    'min_billable_units' => env('UMBRELLA_MIN_BILLABLE_UNITS', 1),      // 起步计费单元
    'lost_fee_cents' => env('UMBRELLA_LOST_FEE_CENTS', 2000),           // 丢失赔偿金

    /*
    |--------------------------------------------------------------------------
    | 超时
    |--------------------------------------------------------------------------
    */
    'borrow_command_ttl_seconds' => env('UMBRELLA_BORROW_TTL', 120),   // 借伞开门命令有效期
    'return_session_ttl_seconds' => env('UMBRELLA_RETURN_TTL', 600),   // 还伞会话有效期

    /*
    |--------------------------------------------------------------------------
    | 柜机模拟器
    |--------------------------------------------------------------------------
    */
    'simulator' => [
        'api_base' => env('SIMULATOR_API_BASE', 'http://127.0.0.1:8000/api'),
        'state_dir' => env('SIMULATOR_STATE_DIR', ''),                  // 默认 storage/app/simulator
        'tick_ms' => env('SIMULATOR_TICK_MS', 500),                     // 轮询间隔
        'door_open_delay_ms' => 300,    // 收到开门命令到门开
        'take_delay_ms' => 1500,        // 门开到用户取走伞
        'insert_delay_ms' => 1500,      // 门开到用户放入伞
        'close_delay_ms' => 2200,       // 放入伞到关门
    ],
];
