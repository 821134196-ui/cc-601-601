<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

// 居民端
Route::get('/stations', [StationController::class, 'index']);
Route::get('/stations/{code}', [StationController::class, 'show']);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{orderNo}', [OrderController::class, 'show']);

Route::post('/returns', [ReturnController::class, 'store']);
Route::get('/returns/{sessionNo}', [ReturnController::class, 'show']);

// 支付(本地模拟网关 + 回执 webhook)
Route::post('/payments/{paymentNo}/mock-pay', [PaymentController::class, 'mockPay']);
Route::get('/payments/{paymentNo}', [PaymentController::class, 'show']);
Route::post('/webhooks/payment', [PaymentController::class, 'webhook']);

// 设备侧(柜机模拟器)
Route::post('/device/events', [DeviceController::class, 'event']);
Route::post('/device/events/batch', [DeviceController::class, 'batch']);
Route::get('/device/poll', [DeviceController::class, 'poll']);
Route::post('/device/commands/{commandId}/ack', [DeviceController::class, 'ack']);
Route::post('/device/sweep', [DeviceController::class, 'sweep']);

// 管理端
Route::get('/admin/umbrellas/{code}', [AdminController::class, 'umbrella']);
Route::post('/admin/umbrellas/{code}/action', [AdminController::class, 'umbrellaAction']);
Route::get('/admin/cabinets', [AdminController::class, 'cabinets']);
Route::post('/admin/cabinets/{code}/connectivity', [AdminController::class, 'cabinetConnectivity']);
Route::get('/admin/events', [AdminController::class, 'events']);
Route::get('/admin/reconcile', [AdminController::class, 'reconcile']);
