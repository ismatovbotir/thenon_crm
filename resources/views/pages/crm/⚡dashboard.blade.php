<?php

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    public function rendering($view): void
    {
        $view->title(__('crm.dashboard.title'));
    }

    /**
     * @return array<string, array{count: int, route: string}>
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'orders' => ['count' => Order::query()->whereDate('order_date', today())->where('status', '!=', OrderStatus::Draft->value)->count(), 'route' => route('crm.orders.index')],
            'companies' => ['count' => Company::query()->where('is_active', true)->count(), 'route' => route('crm.companies.index')],
            'locations' => ['count' => Location::query()->where('is_active', true)->count(), 'route' => route('crm.companies.index')],
            'employees' => ['count' => Employee::query()->where('is_active', true)->count(), 'route' => route('crm.companies.index')],
            'items' => ['count' => Item::query()->where('is_active', true)->count(), 'route' => route('crm.items.index')],
        ];
    }
};
?>

<div class="mx-auto max-w-6xl space-y-8">
    <section class="relative overflow-hidden rounded-3xl bg-navy-900 p-8 text-white sm:p-10">
        <img src="{{ asset('images/brand/photo3.jpg') }}" alt="" class="absolute inset-0 size-full object-cover opacity-15">
        <div class="relative">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gold-400">{{ auth()->user()->role->label() }}</p>
            <h2 class="mt-3 text-2xl font-semibold sm:text-3xl">{{ __('crm.dashboard.welcome', ['name' => auth()->user()->name]) }}</h2>
            <p class="mt-2 max-w-xl text-navy-100">{{ __('crm.dashboard.subtitle') }}</p>
        </div>
    </section>

    <section>
        <h3 class="text-lg font-semibold text-navy-800">{{ __('crm.dashboard.stats') }}</h3>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($this->stats as $key => $stat)
                <a href="{{ $stat['route'] }}" wire:navigate wire:key="stat-{{ $key }}" class="crm-card p-5 transition hover:ring-gold-400">
                    <p class="text-sm text-navy-600">{{ __('crm.nav.'.$key) }}</p>
                    <p class="mt-2 text-3xl font-semibold text-navy-800">{{ number_format($stat['count'], 0, '', ' ') }}</p>
                </a>
            @endforeach
        </div>
    </section>
</div>
