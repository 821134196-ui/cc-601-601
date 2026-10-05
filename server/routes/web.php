<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app/'));

// 前端单页应用（React 构建产物）
Route::get('/app/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(file_exists($index), 503, '前端尚未构建，请先运行 cd web && npm run build');

    return response()->file($index);
})->where('path', '^(?!assets/).*');
