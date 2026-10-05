<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CabinetSimController;
use App\Http\Controllers\DeviceEventController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StationController;
use Illuminate\Support\Facades\Route;

// 居民端
Route::get('/stations', [StationController::class, 'index']);
Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/{order}/return', [OrderController::class, 'returnOrder']);
Route::post('/orders/{order}/cancel-borrow', [OrderController::class, 'cancelBorrow']);
Route::post('/orders/{order}/cancel-return', [OrderController::class, 'cancelReturn']);

// 支付（本地模拟网关）
Route::post('/payments/checkout', [PaymentController::class, 'checkout']);
Route::post('/payments/confirm', [PaymentController::class, 'confirm']);
Route::get('/payments', [PaymentController::class, 'index']);

// 柜机事件通道（在线上报 / 离线补传 / 事件记录核对）
Route::post('/device/events', [DeviceEventController::class, 'store']);
Route::post('/device/events/batch', [DeviceEventController::class, 'batch']);
Route::get('/device/events', [DeviceEventController::class, 'index']);

// 柜机模拟器
Route::get('/cabinet/{station}/state', [CabinetSimController::class, 'state']);
Route::post('/cabinet/{station}/online', [CabinetSimController::class, 'toggleOnline']);
Route::post('/cabinet/{station}/take', [CabinetSimController::class, 'take']);
Route::post('/cabinet/{station}/place', [CabinetSimController::class, 'place']);
Route::post('/cabinet/{station}/sync', [CabinetSimController::class, 'sync']);

// 管理端
Route::get('/admin/umbrellas', [AdminController::class, 'umbrellas']);
Route::get('/admin/umbrella', [AdminController::class, 'findUmbrella']);
Route::patch('/admin/umbrellas/{umbrella}', [AdminController::class, 'updateUmbrella']);
Route::get('/admin/orders', [AdminController::class, 'orders']);
