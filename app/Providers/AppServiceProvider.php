<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEmployeeAccess;
use App\Services\OneC\FakeOneCClient;
use App\Services\OneC\OneCClient;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OneCClient::class, FakeOneCClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $proxies = config('app.trusted_proxies');

        if ($proxies) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        Livewire::addPersistentMiddleware([EnsureEmployeeAccess::class]);
    }
}
