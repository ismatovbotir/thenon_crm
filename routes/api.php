<?php

use App\Http\Controllers\Api\V1\OneCController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['api.token', 'throttle:120,1'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('summary', [OrderController::class, 'summary'])->name('summary');

    Route::post('1c/items', [OneCController::class, 'items'])->name('1c.items');
    Route::post('1c/orders/{order:uuid}', [OneCController::class, 'order'])->name('1c.order');
});
