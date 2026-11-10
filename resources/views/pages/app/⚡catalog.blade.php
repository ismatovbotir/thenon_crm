<?php

use App\Enums\OrderStatus;
use App\Http\Middleware\EnsureEmployeeAccess;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use App\Services\CatalogCache;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    private const MAX_QUANTITY = 100000;

    #[Locked]
    public ?int $locationId = null;

    public string $step = 'setup';

    public string $orderDate = '';

    public string $tab = 'catalog';

    public string $search = '';

    public string $category = '';

    /** @var array<int, int> */
    public array $quantities = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $this->locationId = $this->locations->first()?->id;
        $this->orderDate = $this->defaultDate->toDateString();
        $this->loadQuantities();
    }

    public function rendering($view): void
    {
        $view->title(__('order.title'));
    }

    /**
     * The cart is a public property, so whatever the browser sends is cleaned the moment it arrives.
     */
    public function updatedQuantities(): void
    {
        $this->sanitizeQuantities();
    }

    #[Computed]
    public function employee(): Employee
    {
        return Employee::query()
            ->whereKey(session(EnsureEmployeeAccess::SESSION_KEY.'.id'))
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * The date the order form starts on (tomorrow once the company's evening time has passed).
     */
    #[Computed]
    public function defaultDate(): CarbonImmutable
    {
        return $this->employee->company->earliestOrderDate(now());
    }

    /**
     * First date that can still be ordered for: the default date, or the day before it while that
     * day is still open because the location's day runs past midnight (e.g. until 06:00 next morning).
     */
    #[Computed]
    public function earliestDate(): CarbonImmutable
    {
        return $this->location?->stillOpenEarlierDate($this->defaultDate, now()) ?? $this->defaultDate;
    }

    #[Computed]
    public function latestDate(): CarbonImmutable
    {
        return $this->employee->company->latestOrderDate(now());
    }

    /**
     * The order date in effect: the chosen one when it is a valid date (so past orders can be opened to view), otherwise the earliest date.
     */
    #[Computed]
    public function selectedDate(): string
    {
        return CarbonImmutable::canBeCreatedFromFormat($this->orderDate, 'Y-m-d') ? $this->orderDate : $this->defaultDate->toDateString();
    }

    #[Computed]
    public function isToday(): bool
    {
        return $this->selectedDate === today()->toDateString();
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return $this->employee->locations()->where('locations.is_active', true)->with('cutoffs')->orderBy('name')->get();
    }

    #[Computed]
    public function location(): ?Location
    {
        return $this->locations->firstWhere('id', $this->locationId);
    }

    /**
     * Today's order for the current location, if one exists.
     */
    #[Computed]
    public function order(): ?Order
    {
        return $this->location
            ? Order::query()->with('lines.item')->where('location_id', $this->location->id)->whereDate('order_date', $this->selectedDate)->first()
            : null;
    }

    /**
     * Whether the cutoff time has passed (or the day is closed) for the current location.
     */
    #[Computed]
    public function closed(): bool
    {
        return $this->location?->isOrderingClosedFor(CarbonImmutable::parse($this->selectedDate), now()) ?? false;
    }

    /**
     * Today's cutoff time (HH:MM) when ordering is still open and a limit is set.
     */
    #[Computed]
    public function cutoffLabel(): ?string
    {
        $rule = $this->location?->cutoffFor(CarbonImmutable::parse($this->selectedDate));

        return $rule?->is_open && ! $this->closed ? $rule->cutoffTimeLabel() : null;
    }

    /**
     * Whether the selected date's deadline falls on the next calendar day.
     */
    #[Computed]
    public function cutoffNextDay(): bool
    {
        return (bool) $this->location?->cutoffFor(CarbonImmutable::parse($this->selectedDate))?->ends_next_day;
    }

    #[Computed]
    public function locked(): bool
    {
        return $this->order !== null && $this->order->status !== OrderStatus::Draft;
    }

    /**
     * Only a draft on a date that can still be ordered for may be edited; everything else is view-only.
     */
    #[Computed]
    public function readOnly(): bool
    {
        return $this->locked || ! $this->dateIsAllowed($this->selectedDate);
    }

    /**
     * Active items this company may order.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function catalogItems(): Collection
    {
        return app(CatalogCache::class)->forCompany($this->employee->company);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function visibleItems(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $this->catalogItems->filter(function (Item $item) use ($search) {
            if ($this->category !== '' && $item->category !== $this->category) {
                return false;
            }

            return $search === ''
                || str_contains(mb_strtolower($item->name_ru.' '.$item->name_uz.' '.$item->code), $search);
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function categories(): \Illuminate\Support\Collection
    {
        return $this->catalogItems->pluck('category')->filter()->unique()->sort()->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Item>
     */
    #[Computed]
    public function cartItems(): \Illuminate\Support\Collection
    {
        return $this->catalogItems->filter(fn (Item $item) => ($this->quantities[$item->id] ?? 0) > 0)->values();
    }

    /**
     * @return Collection<int, Order>
     */
    #[Computed]
    public function history(): Collection
    {
        return Order::query()->withSum('lines', 'quantity')->withSum('lines as lines_total', DB::raw('price * quantity'))->withCount('lines')
            ->where('location_id', $this->location?->id)
            ->orderByDesc('order_date')->limit(15)->get();
    }

    public function selectLocation(int $id): void
    {
        abort_unless($this->locations->contains('id', $id), 403);

        $this->locationId = $id;
        $this->reset('search', 'category', 'notice');
        $this->forgetCaches();
        $this->loadQuantities();
    }

    /**
     * Confirm the location and date chosen on the first screen and move on to the item list.
     */
    public function startOrder(): void
    {
        abort_unless($this->location, 403);

        $this->validate([
            'orderDate' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$this->earliestDate->toDateString(),
                'before_or_equal:'.$this->latestDate->toDateString(),
            ],
        ], ['orderDate.*' => __('order.date_invalid')]);

        $this->reset('search', 'category', 'notice');
        $this->forgetCaches();
        $this->loadQuantities();
        $this->step = 'items';
    }

    /**
     * Open an order from the history: a draft opens for editing, every other state opens for viewing only.
     */
    public function openOrder(int $orderId): void
    {
        $order = Order::query()->whereKey($orderId)->whereIn('location_id', $this->locations->modelKeys())->firstOrFail();

        $this->locationId = $order->location_id;
        $this->orderDate = $order->order_date->toDateString();
        $this->tab = 'catalog';
        $this->reset('search', 'category', 'notice');
        $this->forgetCaches();
        $this->loadQuantities();
        $this->step = 'items';
    }

    public function backToSetup(): void
    {
        $this->step = 'setup';
    }

    public function increment(int $itemId): void
    {
        $item = $this->assignable($itemId);
        $this->setQuantityFor($item, ($this->quantities[$itemId] ?? 0) + $item->step);
    }

    public function decrement(int $itemId): void
    {
        $item = $this->assignable($itemId);
        $this->setQuantityFor($item, ($this->quantities[$itemId] ?? 0) - $item->step);
    }

    public function setQuantity(int $itemId, int|string|null $value): void
    {
        $this->setQuantityFor($this->assignable($itemId), (int) $value);
    }

    public function clearCart(): void
    {
        $this->ensureEditable();
        $this->quantities = [];
        $this->persist();
    }

    public function saveDraft(): void
    {
        $this->ensureEditable();
        $this->persist();
        $this->notice = __('order.draft_saved');
    }

    public function complete(): void
    {
        $this->ensureEditable();
        $this->sanitizeQuantities();

        if ($this->quantities === []) {
            $this->addError('cart', __('order.empty_cart'));

            return;
        }

        $this->persist();

        DB::transaction(function () {
            $order = Order::query()->where('location_id', $this->locationId)->whereDate('order_date', $this->selectedDate)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Draft) {
                return;
            }

            $order->update([
                'status' => OrderStatus::Completed,
                'employee_id' => $this->employee->id,
                'completed_at' => now(),
            ]);
        });

        $this->forgetCaches();
        $this->notice = __('order.completed_ok');
    }

    public function confirmDelivery(int $orderId): void
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->whereIn('location_id', $this->locations->modelKeys())
            ->where('status', OrderStatus::Sent->value)
            ->firstOrFail();

        $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        $this->forgetCaches();
        $this->notice = __('order.delivery_confirmed');
    }

    public function copyLast(): void
    {
        $this->ensureEditable();

        $last = Order::query()->with('lines')
            ->where('location_id', $this->locationId)
            ->whereDate('order_date', '<', $this->selectedDate)
            ->where('status', '!=', OrderStatus::Draft->value)
            ->orderByDesc('order_date')->first();

        $copied = [];

        foreach ($last?->lines ?? [] as $line) {
            $item = $this->catalogItems->firstWhere('id', $line->item_id);

            if ($item) {
                $copied[$item->id] = $this->normalize($item, $line->quantity);
            }
        }

        if ($copied === []) {
            $this->notice = __('order.copy_none');

            return;
        }

        $this->quantities = $copied;
        $this->persist();
        $this->notice = __('order.copied');
    }

    private function assignable(int $itemId): Item
    {
        $this->ensureEditable();

        return $this->catalogItems->firstWhere('id', $itemId) ?? abort(403);
    }

    private function ensureEditable(): void
    {
        abort_unless($this->location, 403);
        abort_unless($this->dateIsAllowed($this->orderDate), 403);
        abort_if($this->locked, 403);
        abort_if($this->closed, 403);
    }

    private function dateIsAllowed(string $date): bool
    {
        if (! CarbonImmutable::canBeCreatedFromFormat($date, 'Y-m-d')) {
            return false;
        }

        $day = CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay();

        return $day->gte($this->earliestDate) && $day->lte($this->latestDate);
    }

    /**
     * Public properties can be changed from the browser, so the cart is rebuilt from what the company may order:
     * unknown items are dropped and every quantity goes through the item's step and the maximum.
     */
    private function sanitizeQuantities(): void
    {
        $clean = [];

        foreach ($this->quantities as $itemId => $quantity) {
            $item = $this->catalogItems->firstWhere('id', (int) $itemId);

            if ($item === null || ! is_numeric($quantity)) {
                continue;
            }

            $normalized = $this->normalize($item, (int) $quantity);

            if ($normalized > 0) {
                $clean[$item->id] = $normalized;
            }
        }

        $this->quantities = $clean;
    }

    private function normalize(Item $item, int $quantity): int
    {
        $step = max(1, $item->step);

        $quantity = min(self::MAX_QUANTITY, max(0, $quantity));

        return min(self::MAX_QUANTITY, (int) (ceil($quantity / $step) * $step));
    }

    private function setQuantityFor(Item $item, int $quantity): void
    {
        $quantity = $this->normalize($item, $quantity);

        if ($quantity === 0) {
            unset($this->quantities[$item->id]);
        } else {
            $this->quantities[$item->id] = $quantity;
        }

        $this->notice = null;
        $this->resetErrorBag('cart');
        $this->persist();
    }

    /**
     * Save the cart as today's draft for the current location.
     */
    private function persist(): void
    {
        $this->sanitizeQuantities();

        DB::transaction(function () {
            $order = Order::query()->where('location_id', $this->locationId)->whereDate('order_date', $this->selectedDate)->lockForUpdate()->first();

            if (! $order && $this->quantities === []) {
                return;
            }

            $order ??= Order::query()->create([
                'location_id' => $this->locationId,
                'employee_id' => $this->employee->id,
                'order_date' => $this->selectedDate,
                'status' => OrderStatus::Draft,
            ]);

            abort_if($order->status !== OrderStatus::Draft, 403);

            $order->lines()->whereNotIn('item_id', array_keys($this->quantities))->delete();

            foreach ($this->quantities as $itemId => $quantity) {
                $order->lines()->updateOrCreate(
                    ['item_id' => $itemId],
                    ['quantity' => $quantity, 'price' => $this->catalogItems->firstWhere('id', $itemId)?->pivot->price],
                );
            }
        });

        $this->forgetCaches();
    }

    private function loadQuantities(): void
    {
        $this->quantities = [];

        if ($this->order && $this->order->status === OrderStatus::Draft) {
            $allowed = $this->catalogItems->modelKeys();

            foreach ($this->order->lines as $line) {
                if (in_array($line->item_id, $allowed, true)) {
                    $this->quantities[$line->item_id] = $line->quantity;
                }
            }
        }
    }

    private function forgetCaches(): void
    {
        unset($this->earliestDate, $this->selectedDate, $this->isToday, $this->order, $this->locked, $this->readOnly, $this->closed, $this->cutoffLabel, $this->cartItems, $this->history);
    }

    public function money(mixed $price): string
    {
        return Money::format($price);
    }

    /**
     * Total of the cart at the company's current prices (items without a price count as zero).
     */
    #[Computed]
    public function cartTotal(): float
    {
        return (float) $this->cartItems->sum(fn (Item $item) => (float) $item->pivot->price * ($this->quantities[$item->id] ?? 0));
    }
};
?>

<div x-data="{ cart: false }" class="pb-32">
    <header class="sticky top-0 z-30 bg-navy-900 px-4 pb-3 pt-4 text-white shadow-md">
        <div class="mx-auto flex max-w-2xl items-center gap-3">
            <img src="{{ asset('images/brand/logo.jpg') }}" alt="THE NON" class="size-10 shrink-0 rounded-full ring-2 ring-gold-400/40">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ $this->location?->name ?? __('order.no_location') }}</p>
                <p class="truncate text-xs text-navy-100">{{ $this->employee->company->name }} · {{ $this->employee->name }}</p>
                @if ($step === 'items' && $tab === 'catalog' && $this->location)
                    <p class="truncate text-xs font-semibold text-gold-300">{{ __('order.for_date', ['date' => \Carbon\Carbon::parse($this->selectedDate)->format('d.m.Y')]) }}</p>
                @endif
            </div>
            @if ($step === 'items' && $tab === 'catalog' && $this->location)
                <button type="button" wire:click="backToSetup" class="shrink-0 rounded-lg bg-navy-800 px-3 py-1.5 text-xs font-semibold text-white focus:ring-2 focus:ring-gold-400">{{ __('order.change') }}</button>
            @endif
        </div>

        <nav class="mx-auto mt-3 flex max-w-2xl gap-1 rounded-xl bg-navy-800 p-1 text-sm font-semibold">
            @foreach (['catalog' => 'order.tab_catalog', 'history' => 'order.tab_history'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                    'flex-1 rounded-lg px-3 py-1.5 transition',
                    'bg-gold-400 text-navy-950' => $tab === $key,
                    'text-navy-100' => $tab !== $key,
                ])>{{ __($label) }}</button>
            @endforeach
        </nav>
    </header>

    <main class="mx-auto max-w-2xl space-y-4 px-4 py-4">
        @if (! $this->location)
            <p class="crm-card px-4 py-8 text-center text-sm text-navy-600">{{ __('order.no_location_text') }}</p>
        @elseif ($tab === 'history')
            @forelse ($this->history as $past)
                <div wire:key="history-{{ $past->id }}" class="crm-card flex items-center justify-between gap-3 px-4 py-3">
                    <button type="button" wire:click="openOrder({{ $past->id }})" class="min-w-0 flex-1 text-left">
                        <span class="block font-medium text-navy-800">{{ $past->order_date->format('d.m.Y') }}</span>
                        <span class="block text-xs text-navy-600">{{ __('order.positions', ['count' => $past->lines_count]) }} · {{ (int) $past->lines_sum_quantity }}@if ($past->lines_total > 0) · {{ $this->money($past->lines_total) }}@endif</span>
                    </button>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span class="crm-badge bg-navy-100 text-navy-800">{{ $past->status->label() }}</span>
                        @if ($past->status === \App\Enums\OrderStatus::Sent)
                            <button type="button" wire:click="confirmDelivery({{ $past->id }})" class="crm-btn-gold px-3 py-1.5 text-xs">{{ __('order.confirm_delivery') }}</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="crm-card px-4 py-8 text-center text-sm text-navy-600">{{ __('order.no_history') }}</p>
            @endforelse
        @elseif ($step === 'setup')
            <h2 class="text-lg font-semibold text-navy-800">{{ __('order.new_order') }}</h2>

            <div class="space-y-2">
                <p class="text-sm font-medium text-navy-800">{{ __('order.choose_location') }}</p>
                @foreach ($this->locations as $option)
                    <div wire:key="setup-location-{{ $option->id }}" @class(['crm-card p-3', 'ring-2 ring-gold-400' => $option->id === $locationId])>
                        <button type="button" wire:click="selectLocation({{ $option->id }})" class="block w-full text-left">
                            <span class="block font-medium text-navy-800">{{ $option->name }}</span>
                            @if ($option->address)
                                <span class="mt-0.5 block text-xs text-navy-600">{{ $option->address }}</span>
                            @endif
                        </button>
                        @if ($option->yandex_url)
                            <a href="{{ $option->yandex_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-gold-600 hover:underline">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                {{ __('order.open_map') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="space-y-1.5">
                <label for="orderDate" class="block text-sm font-medium text-navy-800">{{ __('order.order_date') }}</label>
                <input id="orderDate" type="date" wire:model="orderDate" min="{{ $this->earliestDate->toDateString() }}" max="{{ $this->latestDate->toDateString() }}" class="crm-input">
                <p class="text-xs text-navy-600">{{ __('order.order_date_hint', ['from' => $this->earliestDate->format('d.m.Y'), 'to' => $this->latestDate->format('d.m.Y')]) }}</p>
                @if ($this->employee->company->next_day_enabled)
                    <p class="text-xs text-navy-600">{{ __('order.next_day_notice', ['time' => $this->employee->company->next_day_from]) }}</p>
                @endif
                @error('orderDate')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="button" wire:click="startOrder" wire:loading.attr="disabled" class="crm-btn-gold w-full justify-center">{{ __('order.continue') }}</button>
        @elseif ($this->readOnly || $this->closed)
            <div class="rounded-2xl bg-gold-300/25 px-4 py-4 text-sm text-crust-700">
                @if ($this->locked)
                    <p class="font-semibold">{{ __('order.already_title') }}</p>
                    <p class="mt-1">{{ __('order.already_text', ['phone' => config('crm.support_phone')]) }}</p>
                    <p class="mt-2"><span class="crm-badge bg-white text-navy-800">{{ $this->order->status->label() }}</span></p>
                    @if ($this->order->status === \App\Enums\OrderStatus::Sent)
                        <button type="button" wire:click="confirmDelivery({{ $this->order->id }})" class="crm-btn-gold mt-3">{{ __('order.confirm_delivery') }}</button>
                    @endif
                @elseif ($this->readOnly)
                    <p class="font-semibold">{{ __('order.past_title') }}</p>
                    <p class="mt-1">{{ __('order.past_text', ['phone' => config('crm.support_phone')]) }}</p>
                @else
                    <p class="font-semibold">{{ __('order.closed_title') }}</p>
                    <p class="mt-1">{{ __('order.closed_text', ['phone' => config('crm.support_phone')]) }}</p>
                @endif
            </div>

            @if ($notice)
                <p class="rounded-xl bg-emerald-100 px-4 py-2.5 text-sm text-emerald-800">{{ $notice }}</p>
            @endif

            @if ($this->order && $this->order->lines->isNotEmpty())
                <div class="crm-card divide-y divide-navy-100">
                    @foreach ($this->order->lines as $line)
                        <div wire:key="line-{{ $line->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-navy-800">{{ $line->item->name }}</p>
                                @if ($line->price !== null)
                                    <p class="text-xs text-navy-600">{{ $this->money($line->price) }} × {{ $line->quantity }}</p>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold">{{ $line->quantity }} <span class="font-normal text-navy-600">{{ $line->item->unit->label() }}</span></p>
                                @if ($line->total() !== null)
                                    <p class="text-xs font-semibold text-navy-800">{{ $this->money($line->total()) }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($this->order->total() > 0)
                        <div class="flex items-center justify-between gap-3 bg-navy-50 px-4 py-3">
                            <span class="text-sm font-semibold text-navy-800">{{ __('order.total') }}</span>
                            <span class="text-base font-bold text-navy-800">{{ $this->money($this->order->total()) }}</span>
                        </div>
                    @endif
                </div>
            @endif
        @else
            @if ($notice)
                <p class="rounded-xl bg-emerald-100 px-4 py-2.5 text-sm text-emerald-800">{{ $notice }}</p>
            @endif

            @if ($this->cutoffLabel)
                <p class="rounded-xl bg-cream-100 px-4 py-2 text-sm text-crust-700">{{ __($this->cutoffNextDay ? 'order.cutoff_info_next_day' : 'order.cutoff_info', ['time' => $this->cutoffLabel]) }}</p>
            @endif

            <div class="flex gap-2">
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ __('order.search') }}" class="crm-input">
                <button type="button" wire:click="copyLast" class="crm-btn-secondary shrink-0 px-3" title="{{ __('order.copy_last') }}">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zM16 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h2"/></svg>
                    <span class="hidden text-xs sm:inline">{{ __('order.copy_last') }}</span>
                </button>
            </div>

            @if ($this->categories->isNotEmpty())
                <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
                    @foreach ($this->categories->prepend('') as $name)
                        <button type="button" wire:key="cat-{{ $loop->index }}" wire:click="$set('category', '{{ addslashes($name) }}')" @class([
                            'shrink-0 rounded-full px-4 py-1.5 text-sm font-medium ring-1 transition',
                            'bg-navy-800 text-white ring-navy-800' => $category === $name,
                            'bg-white text-navy-700 ring-navy-100' => $category !== $name,
                        ])>{{ $name === '' ? __('order.all') : $name }}</button>
                    @endforeach
                </div>
            @endif

            @forelse ($this->visibleItems as $item)
                @php($qty = $quantities[$item->id] ?? 0)
                <div wire:key="item-{{ $item->id }}" @class(['crm-card flex items-center gap-3 p-3', 'ring-2 ring-gold-400' => $qty > 0])>
                    @if ($item->image_url)
                        <img src="{{ $item->image_url }}" alt="" loading="lazy" class="size-16 shrink-0 rounded-xl object-cover">
                    @else
                        <span class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-cream-100 text-crust-500">
                            <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8"/></svg>
                        </span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="font-medium leading-tight text-navy-800">{{ $item->name }}</p>
                        <p class="mt-0.5 text-xs text-navy-600">
                            {{ $item->unit->label() }} · {{ __('order.step', ['step' => $item->step]) }}
                            @if ($item->pivot->price !== null)
                                · {{ $this->money($item->pivot->price) }}
                            @endif
                        </p>
                    </div>

                    @if ($qty === 0)
                        <button type="button" wire:click="increment({{ $item->id }})" aria-label="{{ __('order.add') }}" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gold-400 text-navy-950 active:bg-gold-300">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                        </button>
                    @else
                        <div class="flex shrink-0 items-center gap-1">
                            <button type="button" wire:click="decrement({{ $item->id }})" aria-label="−" class="flex size-10 items-center justify-center rounded-full bg-navy-50 text-navy-800 active:bg-navy-100">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                            </button>
                            <input type="number" inputmode="numeric" min="0" step="{{ $item->step }}" value="{{ $qty }}"
                                   wire:change="setQuantity({{ $item->id }}, $event.target.value)" aria-label="{{ __('order.quantity') }}"
                                   class="w-14 rounded-lg border-0 bg-transparent p-0 text-center text-base font-semibold [appearance:textfield] focus:ring-2 focus:ring-gold-400">
                            <button type="button" wire:click="increment({{ $item->id }})" aria-label="+" class="flex size-10 items-center justify-center rounded-full bg-gold-400 text-navy-950 active:bg-gold-300">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                            </button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="crm-card px-4 py-8 text-center text-sm text-navy-600">
                    {{ $this->catalogItems->isEmpty() ? __('order.nothing_assigned') : __('order.no_results') }}
                </p>
            @endforelse
        @endif
    </main>

    @if ($this->location && $step === 'items' && ! $this->readOnly && ! $this->closed && $tab === 'catalog')
        {{-- Sticky cart bar --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-navy-100 bg-white/95 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur">
            <div class="mx-auto flex max-w-2xl items-center gap-3">
                <button type="button" @click="cart = true" class="min-w-0 flex-1 text-left">
                    <span class="block text-xs text-navy-600">{{ __('order.cart') }}</span>
                    <span class="block truncate font-semibold text-navy-800">
                        {{ __('order.positions', ['count' => $this->cartItems->count()]) }} · {{ array_sum($quantities) }}@if ($this->cartTotal > 0) · {{ $this->money($this->cartTotal) }}@endif
                    </span>
                </button>
                <button type="button" @click="cart = true" class="crm-btn-primary" @disabled($this->cartItems->isEmpty())>{{ __('order.open_cart') }}</button>
            </div>
        </div>

        {{-- Cart sheet --}}
        <div x-cloak x-show="cart" class="fixed inset-0 z-50" @keydown.escape.window="cart = false">
            <div class="absolute inset-0 bg-navy-950/60" @click="cart = false"></div>
            <div class="absolute inset-x-0 bottom-0 mx-auto flex max-h-[85vh] max-w-2xl flex-col rounded-t-3xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-navy-100 px-5 py-4">
                    <h3 class="font-semibold text-navy-800">{{ __('order.cart') }}</h3>
                    <button type="button" @click="cart = false" class="crm-btn-ghost">{{ __('order.close') }}</button>
                </div>

                <div class="flex-1 divide-y divide-navy-100 overflow-y-auto px-5">
                    @forelse ($this->cartItems as $item)
                        <div wire:key="cart-{{ $item->id }}" class="flex items-center gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-navy-800">{{ $item->name }}</p>
                                @if ($item->pivot->price !== null)
                                    <p class="text-xs text-navy-600">{{ $this->money($item->pivot->price) }} × {{ $quantities[$item->id] }} = <span class="font-semibold text-navy-800">{{ $this->money($item->pivot->price * $quantities[$item->id]) }}</span></p>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="decrement({{ $item->id }})" aria-label="−" class="flex size-9 items-center justify-center rounded-full bg-navy-50 text-navy-800">−</button>
                                <span class="w-12 text-center font-semibold">{{ $quantities[$item->id] }}</span>
                                <button type="button" wire:click="increment({{ $item->id }})" aria-label="+" class="flex size-9 items-center justify-center rounded-full bg-gold-400 text-navy-950">+</button>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-navy-600">{{ __('order.empty_cart') }}</p>
                    @endforelse
                </div>

                <div class="space-y-3 border-t border-navy-100 px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4">
                    @if ($this->cartTotal > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-navy-800">{{ __('order.total') }}</span>
                            <span class="text-lg font-bold text-navy-800">{{ $this->money($this->cartTotal) }}</span>
                        </div>
                    @endif
                    @error('cart')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @if ($notice)
                        <p class="text-sm text-emerald-700">{{ $notice }}</p>
                    @endif
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="saveDraft" class="crm-btn-secondary">{{ __('order.save_draft') }}</button>
                        <button type="button" wire:click="complete" wire:loading.attr="disabled" @disabled($this->cartItems->isEmpty()) class="crm-btn-gold">{{ __('order.complete') }}</button>
                    </div>
                    @if ($this->cartItems->isNotEmpty())
                        <button type="button" wire:click="clearCart" class="w-full text-center text-sm text-red-600">{{ __('order.clear') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
