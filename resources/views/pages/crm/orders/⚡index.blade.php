<?php

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::crm')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $date = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $companyId = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        if (! request()->has('date')) {
            $this->date = today()->toDateString();
        }
    }

    public function rendering($view): void
    {
        $view->title(__('crm.orders.title'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'status', 'companyId', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
        $this->resetPage();
    }

    /**
     * @return Collection<int, Company>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Company::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private function filtered(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date), fn ($query) => $query->whereDate('order_date', $this->date))
            ->when($this->companyId !== '', fn ($query) => $query->whereHas('location', fn ($q) => $q->where('company_id', (int) $this->companyId)))
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->whereHas('location', fn ($q) => $q->where('name', 'like', $term)
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $term)));
            });
    }

    /**
     * Order counts per status for the current date/company/search filters.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return $this->filtered()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->filtered()
            ->when(OrderStatus::tryFrom($this->status), fn ($query, $status) => $query->where('status', $status->value))
            ->with('location.company')
            ->withCount('lines')
            ->withSum('lines', 'quantity')
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(25);
    }

    public function badgeClass(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'bg-navy-100 text-navy-600',
            OrderStatus::Completed => 'bg-gold-400/25 text-gold-600',
            OrderStatus::Accepted => 'bg-sky-100 text-sky-700',
            OrderStatus::Done => 'bg-violet-100 text-violet-700',
            OrderStatus::Sent => 'bg-orange-100 text-orange-700',
            OrderStatus::Delivered => 'bg-emerald-100 text-emerald-700',
        };
    }
};
?>

<div class="mx-auto max-w-6xl space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.orders.title') }}</h2>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach (\App\Enums\OrderStatus::cases() as $case)
            <button type="button" wire:key="count-{{ $case->value }}" wire:click="$set('status', '{{ $status === $case->value ? '' : $case->value }}')"
                    @class(['crm-card p-4 text-left transition hover:ring-gold-400', 'ring-2 ring-gold-400' => $status === $case->value])>
                <p class="text-2xl font-semibold text-navy-800">{{ $this->counts[$case->value] ?? 0 }}</p>
                <p class="text-xs text-navy-600">{{ $case->label() }}</p>
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="flex items-center gap-2">
            <input type="date" wire:model.live="date" aria-label="{{ __('crm.orders.date') }}" class="crm-input w-44">
            <button type="button" wire:click="today" class="crm-btn-secondary">{{ __('crm.orders.today') }}</button>
        </div>
        <select wire:model.live="companyId" aria-label="{{ __('crm.nav.companies') }}" class="crm-input sm:w-56">
            <option value="">{{ __('crm.orders.all_companies') }}</option>
            @foreach ($this->companies as $company)
                <option value="{{ $company->id }}" wire:key="company-{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.orders.search') }}" class="crm-input sm:max-w-xs">
    </div>

    <div class="crm-card overflow-hidden">
        @if ($this->orders->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.orders.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">#</th>
                            <th class="crm-th">{{ __('crm.orders.date') }}</th>
                            <th class="crm-th">{{ __('crm.orders.location') }}</th>
                            <th class="crm-th">{{ __('crm.orders.positions') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->orders as $order)
                            <tr wire:key="order-{{ $order->id }}" class="hover:bg-navy-50/40">
                                <td class="crm-td font-mono text-xs">{{ $order->id }}</td>
                                <td class="crm-td">{{ $order->order_date->format('d.m.Y') }}</td>
                                <td class="crm-td">
                                    <p class="font-medium">{{ $order->location->company->name }}</p>
                                    <p class="text-xs text-navy-600">{{ $order->location->name }}</p>
                                </td>
                                <td class="crm-td">{{ $order->lines_count }} · {{ (int) $order->lines_sum_quantity }}</td>
                                <td class="crm-td"><span class="crm-badge {{ $this->badgeClass($order->status) }}">{{ $order->status->label() }}</span></td>
                                <td class="crm-td text-right">
                                    <a href="{{ route('crm.orders.show', $order) }}" wire:navigate class="crm-btn-ghost">{{ __('crm.orders.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $this->orders->links() }}
</div>
