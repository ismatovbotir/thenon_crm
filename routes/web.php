<?php

use App\Http\Controllers\DevTelegramController;
use App\Http\Controllers\EmployeeAppController;
use App\Http\Controllers\ItemImageController;
use App\Http\Controllers\OrderPreviewController;
use App\Http\Controllers\OrderPrintController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::get('/lang/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 404);

    $request->session()->put('locale', $locale);

    return redirect()->back(fallback: route('landing'));
})->name('locale.switch');

Route::prefix('crm')->name('crm.')->group(function () {
    Route::livewire('login', 'pages::crm.login')->middleware('guest')->name('login');

    Route::middleware(['auth', 'crm'])->group(function () {
        Route::livewire('/', 'pages::crm.dashboard')->name('dashboard');

        Route::livewire('companies', 'pages::crm.companies.index')->name('companies.index');
        Route::livewire('companies/{company}', 'pages::crm.companies.show')->name('companies.show');
        Route::livewire('items', 'pages::crm.items.index')->name('items.index');
        Route::livewire('orders', 'pages::crm.orders.index')->name('orders.index');
        Route::livewire('reports', 'pages::crm.reports')->name('reports');
        Route::livewire('reports/sales', 'pages::crm.reports.sales')->name('reports.sales');
        Route::livewire('reports/daily', 'pages::crm.reports.daily')->name('reports.daily');
        Route::livewire('reports/compare', 'pages::crm.reports.compare')->name('reports.compare');
        Route::livewire('reports/missing', 'pages::crm.reports.missing')->name('reports.missing');
        Route::get('reports/export', ReportExportController::class)->name('reports.export');
        Route::livewire('settings', 'pages::crm.settings')->name('settings');
        Route::livewire('system', 'pages::crm.system')->name('system');
        Route::livewire('tv', 'pages::crm.tv')->name('tv');
        Route::livewire('orders/{order}', 'pages::crm.orders.show')->name('orders.show');
        Route::get('orders/{order}/print', OrderPrintController::class)->name('orders.print');

        Route::post('logout', function (Request $request) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('crm.login');
        })->name('logout');
    });
});

Route::prefix('dev/telegram')->name('dev.telegram')->group(function () {
    Route::get('/', [DevTelegramController::class, 'index']);
    Route::post('stop', [DevTelegramController::class, 'stop'])->name('.stop');
    Route::post('employees/{employee}', [DevTelegramController::class, 'select'])->name('.select');
});

Route::get('media/items/{item}', ItemImageController::class)->middleware('throttle:240,1')->name('media.item');

Route::livewire('tv/{token}', 'pages::tv.board')->middleware('throttle:120,1')->name('tv.board');

Route::get('o/{order:uuid}', OrderPreviewController::class)->middleware('throttle:60,1')->name('orders.preview');

Route::post('telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

Route::prefix('app')->name('app.')->group(function () {
    Route::post('auth', [EmployeeAppController::class, 'authenticate'])->middleware('throttle:30,1')->name('auth');

    Route::middleware('employee')->group(function () {
        Route::livewire('/', 'pages::app.catalog')->name('home');
    });
});
