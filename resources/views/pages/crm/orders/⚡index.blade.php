<?php

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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

    #[Url]
    public string $sort = 'date';

    #[Url]
    public string $dir = 'desc';

    public int $perPage = 25;

    /** @var list<int|string> */
    public array $selected = [];

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
        if (in_array($property, ['date', 'status', 'companyId', 'search', 'perPage'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    private const SORTS = [
        'id' => 'id',
        'date' => 'order_date',
        'positions' => 'lines_sum_quantity',
        'total' => 'lines_total',
        'status' => 'status',
    ];

    public function sortBy(string $column): void
    {
        abort_unless(array_key_exists($column, self::SORTS), 422);

        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;
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
                $query->where(fn ($q) => $q->where('one_c_number', 'like', $term)
                    ->orWhereHas('location', fn ($l) => $l->where('name', 'like', $term)
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $term))));
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
            ->withSum('lines as lines_total', DB::raw('price * quantity'))
            ->orderBy(self::SORTS[$this->sort] ?? 'order_date', $this->dir === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    public function selectPage(bool $on): void
    {
        $this->selected = $on ? $this->orders->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
    }

    /**
     * Tick every order that matches the current filters, across all pages (capped at 1000).
     */
    public function selectAllMatching(): void
    {
        $this->selected = $this->filtered()
            ->when(OrderStatus::tryFrom($this->status), fn ($query, $status) => $query->where('status', $status->value))
            ->limit(1000)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Move the ticked orders to a status. Only orders inside the current filter are touched; delivered
     * orders and drafts (which an operator may only complete from the order page) are skipped.
     */
    public function applyStatus(string $status): void
    {
        $target = OrderStatus::tryFrom($status);

        abort_unless(in_array($target, [OrderStatus::Accepted, OrderStatus::Done, OrderStatus::Sent], true), 422);

        $ids = collect($this->selected)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $orders = $this->filtered()->whereIn('id', $ids)
            ->whereNotIn('status', [OrderStatus::Draft->value, OrderStatus::Delivered->value, $target->value])
            ->get();

        foreach ($orders as $order) {
            $order->update(['status' => $target]);
        }

        $this->dispatch('notify', message: __('crm.orders.bulk_done', ['done' => $orders->count(), 'skipped' => $ids->count() - $orders->count()]), type: $orders->isEmpty() ? 'error' : 'success');
        $this->selected = [];
        unset($this->orders, $this->counts);
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

<div class="w-full space-y-6">
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
        <input type="search" data-search wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.orders.search') }} ( / )" class="crm-input sm:max-w-xs">
    </div>

    @if (count($selected) > 0)
        <div class="crm-card sticky bottom-4 z-20 flex flex-wrap items-center gap-2 px-4 py-3 shadow-lg ring-gold-400">
            <span class="text-sm font-semibold text-navy-800">{{ __('crm.orders.selected', ['count' => count($selected)]) }}</span>
            @if (count($selected) < $this->orders->total())
                <button type="button" wire:click="selectAllMatching" class="text-sm font-medium text-navy-700 underline hover:text-navy-950">{{ __('crm.orders.select_all_matching', ['count' => min(1000, $this->orders->total())]) }}</button>
            @endif
            @foreach ([\App\Enums\OrderStatus::Accepted, \App\Enums\OrderStatus::Done, \App\Enums\OrderStatus::Sent] as $target)
                <x-crm.confirm wire:key="bulk-{{ $target->value }}" action="applyStatus('{{ $target->value }}')" :message="__('crm.orders.bulk_confirm', ['count' => count($selected), 'status' => $target->label()])" class="crm-btn-secondary">→ {{ $target->label() }}</x-crm.confirm>
            @endforeach
            <a href="{{ route('crm.orders.print.bulk', ['ids' => implode(',', $selected)]) }}" target="_blank" class="crm-btn-gold">{{ __('crm.orders.print_selected') }}</a>
            <button type="button" wire:click="selectPage(false)" class="crm-btn-ghost ml-auto">{{ __('crm.orders.clear_selection') }}</button>
        </div>
    @endif

    <div class="space-y-3" :class="{ 'crm-dense': dense }" x-data="{ dense: false }"
         x-init="try { dense = localStorage.getItem('crm.dense') === '1' } catch (e) {}; $watch('dense', (value) => { try { localStorage.setItem('crm.dense', value ? '1' : '0') } catch (e) {} })"
         x-on:keydown.window.slash.prevent="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)) { document.querySelector('[data-search]')?.focus() }">
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-navy-600">
        <p>{{ __('crm.orders.found', ['count' => $this->orders->total()]) }}</p>
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2">{{ __('crm.orders.per_page') }}
                <select wire:model.live="perPage" class="crm-input !w-auto !py-1.5">
                    @foreach ([25, 50, 100] as $size)
                        <option value="{{ $size }}">{{ $size }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" x-model="dense"> {{ __('crm.orders.compact') }}</label>
        </div>
    </div>

    <div class="crm-card overflow-hidden">
        @if ($this->orders->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.orders.empty') }}</p>
        @else
            <div class="crm-table-scroll">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th w-10"><input type="checkbox" aria-label="{{ __('crm.orders.select_all') }}" wire:click="selectPage($event.target.checked)" @checked(count($selected) > 0 && count($selected) === $this->orders->count())></th>
                            @foreach (['id' => '#', 'date' => __('crm.orders.date')] as $column => $label)
                                <x-crm.sort-th :column="$column" :sort="$sort" :dir="$dir">{{ $label }}</x-crm.sort-th>
                            @endforeach
                            <th class="crm-th">{{ __('crm.orders.location') }}</th>
                            <x-crm.sort-th column="positions" :sort="$sort" :dir="$dir">{{ __('crm.orders.positions') }}</x-crm.sort-th>
                            <x-crm.sort-th column="total" :sort="$sort" :dir="$dir" class="text-right">{{ __('crm.orders.total') }}</x-crm.sort-th>
                            <x-crm.sort-th column="status" :sort="$sort" :dir="$dir">{{ __('crm.common.status') }}</x-crm.sort-th>
                            <th class="crm-th">{{ __('crm.orders.signs') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->orders as $order)
                            <tr wire:key="order-{{ $order->id }}" @class(['cursor-pointer hover:bg-navy-50/60', 'bg-gold-400/10' => in_array((string) $order->id, array_map('strval', $selected), true)]) x-on:click="if (! $event.target.closest('a, button, input, label')) { Livewire.navigate('{{ route('crm.orders.show', $order) }}') }">
                                <td class="crm-td"><input type="checkbox" value="{{ $order->id }}" wire:model.live="selected" aria-label="{{ __('crm.orders.select') }} #{{ $order->id }}"></td>
                                <td class="crm-td font-mono text-xs">{{ $order->displayNumber() }}@if ($order->one_c_number)<span class="block text-[10px] text-navy-600">#{{ $order->id }}</span>@endif</td>
                                <td class="crm-td">{{ $order->order_date->format('d.m.Y') }}</td>
                                <td class="crm-td">
                                    <p class="font-medium">{{ $order->location->company->name }}</p>
                                    <p class="text-xs text-navy-600">{{ $order->location->name }}</p>
                                </td>
                                <td class="crm-td">{{ $order->lines_count }} · {{ (int) $order->lines_sum_quantity }}</td>
                                <td class="crm-td text-right">{{ $order->lines_total > 0 ? \App\Support\Money::format($order->lines_total) : '—' }}</td>
                                <td class="crm-td"><span class="crm-badge {{ $this->badgeClass($order->status) }}">{{ $order->status->label() }}</span></td>
                                <td class="crm-td">
                                    <div class="flex flex-wrap gap-1">
                                        <x-crm.sign :on="$order->admin_notified_at !== null" :label="__('crm.orders.admin_informed')" />
                                        <x-crm.sign :on="$order->synced_1c_at !== null" :label="__('crm.orders.synced_1c')" />
                                    </div>
                                </td>
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
    </div>

    {{ $this->orders->links() }}
</div>
