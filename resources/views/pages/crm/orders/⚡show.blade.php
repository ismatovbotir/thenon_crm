<?php

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    #[Locked]
    public int $orderId;

    /** @var array<int, int|string> */
    public array $quantities = [];

    /** @var array<int, string> */
    public array $comments = [];

    public string $newItemId = '';

    public int|string $newQuantity = '';

    public function mount(Order $order): void
    {
        $this->orderId = $order->id;
        $this->loadQuantities();
    }

    public function rendering($view): void
    {
        $view->title(__('crm.orders.order_no', ['id' => $this->orderId]));
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['location.company', 'employee', 'lines.item'])->findOrFail($this->orderId);
    }

    #[Computed]
    public function editable(): bool
    {
        return ! in_array($this->order->status, [OrderStatus::Sent, OrderStatus::Delivered], true);
    }

    /**
     * Statuses an operator may move this order to.
     *
     * @return list<OrderStatus>
     */
    #[Computed]
    public function transitions(): array
    {
        return match ($this->order->status) {
            OrderStatus::Delivered => [],
            OrderStatus::Draft => [OrderStatus::Completed],
            default => [OrderStatus::Completed, OrderStatus::Accepted, OrderStatus::Done, OrderStatus::Sent],
        };
    }

    /**
     * Active items of the order's company that are not yet on the order.
     *
     * @return Collection<int, Item>
     */
    #[Computed]
    public function addableItems(): Collection
    {
        return $this->order->location->company->items()
            ->where('items.is_active', true)
            ->whereNotIn('items.id', $this->order->lines->pluck('item_id'))
            ->orderBy('name_ru')
            ->get();
    }

    private function loadQuantities(): void
    {
        $this->quantities = $this->order->lines->mapWithKeys(fn (OrderItem $line) => [$line->id => $line->quantity])->all();
        $this->comments = $this->order->lines->mapWithKeys(fn (OrderItem $line) => [$line->id => (string) $line->comment])->all();
    }

    private function refreshOrder(): void
    {
        unset($this->order, $this->editable, $this->transitions, $this->addableItems);
    }

    public function setStatus(string $status): void
    {
        $target = OrderStatus::from($status);

        abort_unless(in_array($target, $this->transitions, true), 403);

        if ($this->order->status === OrderStatus::Draft && $this->order->lines->isEmpty()) {
            $this->addError('status', __('crm.orders.cannot_complete_empty'));

            return;
        }

        $attributes = ['status' => $target];

        if ($this->order->status === OrderStatus::Draft) {
            $attributes['completed_at'] = now();
        }

        $this->order->update($attributes);
        $this->resetErrorBag();
        $this->refreshOrder();
    }

    public function saveQuantity(int $lineId): void
    {
        abort_unless($this->editable, 403);

        $line = $this->order->lines->firstWhere('id', $lineId) ?? abort(404);

        $this->validate(
            ['quantities.'.$lineId => ['required', 'integer', 'min:1', 'max:100000']],
            attributes: ['quantities.'.$lineId => __('crm.orders.quantity')],
        );

        $line->update(['quantity' => (int) $this->quantities[$lineId]]);
        $this->refreshOrder();
    }

    /**
     * Mark a line active or inactive (for example when 1C cannot supply it). Allowed at any status.
     */
    public function toggleLine(int $lineId): void
    {
        $line = $this->order->lines->firstWhere('id', $lineId) ?? abort(404);

        $line->update(['is_active' => ! $line->is_active]);
        $this->refreshOrder();
    }

    public function saveComment(int $lineId): void
    {
        $line = $this->order->lines->firstWhere('id', $lineId) ?? abort(404);

        $this->validate(
            ['comments.'.$lineId => ['nullable', 'string', 'max:500']],
            attributes: ['comments.'.$lineId => __('crm.orders.comment')],
        );

        $comment = trim((string) ($this->comments[$lineId] ?? ''));
        $line->update(['comment' => $comment === '' ? null : $comment]);
        $this->refreshOrder();
    }

    public function removeLine(int $lineId): void
    {
        abort_unless($this->editable, 403);

        $this->order->lines()->whereKey($lineId)->delete();
        unset($this->quantities[$lineId]);
        $this->refreshOrder();
    }

    public function addLine(): void
    {
        abort_unless($this->editable, 403);

        $item = $this->addableItems->firstWhere('id', (int) $this->newItemId);

        if (! $item) {
            $this->addError('newItemId', __('crm.orders.pick_item'));

            return;
        }

        $this->validate(
            ['newQuantity' => ['required', 'integer', 'min:1', 'max:100000']],
            attributes: ['newQuantity' => __('crm.orders.quantity')],
        );

        DB::transaction(function () use ($item) {
            $this->order->lines()->updateOrCreate(
                ['item_id' => $item->id],
                ['quantity' => (int) $this->newQuantity, 'price' => $item->pivot->price],
            );
        });

        $this->reset('newItemId', 'newQuantity');
        $this->refreshOrder();
        $this->loadQuantities();
    }

    public function updatedNewItemId(): void
    {
        $item = $this->addableItems->firstWhere('id', (int) $this->newItemId);
        $this->newQuantity = $item?->step ?? '';
    }
};
?>

<div class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('crm.orders.index', ['date' => $this->order->order_date->toDateString()]) }}" wire:navigate class="text-sm text-navy-600 hover:text-navy-900">&larr; {{ __('crm.orders.title') }}</a>
            <h2 class="mt-1 text-2xl font-semibold text-navy-800">{{ __('crm.orders.order_no', ['id' => $this->order->id]) }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('crm.orders.print', $this->order) }}" target="_blank" class="crm-btn-secondary">{{ __('crm.orders.print') }}</a>
            <x-crm.sign :on="$this->order->admin_notified_at !== null" :label="__('crm.orders.admin_informed')" />
            <x-crm.sign :on="$this->order->synced_1c_at !== null" :label="__('crm.orders.synced_1c')" />
            <span class="crm-badge bg-navy-100 px-3 py-1.5 text-sm text-navy-800">{{ $this->order->status->label() }}</span>
        </div>
    </div>

    <div class="crm-card grid gap-4 p-5 sm:grid-cols-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.nav.companies') }}</p>
            <p class="font-medium text-navy-800">{{ $this->order->location->company->name }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.orders.location') }}</p>
            <p class="font-medium text-navy-800">{{ $this->order->location->name }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.orders.date') }}</p>
            <p class="font-medium text-navy-800">{{ $this->order->order_date->format('d.m.Y') }}</p>
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.orders.created_by') }}</p>
            <p class="font-medium text-navy-800">{{ $this->order->employee?->name ?? __('crm.common.none') }}</p>
        </div>
    </div>

    {{-- Status --}}
    <div class="crm-card space-y-3 p-5">
        <h3 class="font-semibold text-navy-800">{{ __('crm.orders.change_status') }}</h3>

        @if ($this->transitions === [])
            <p class="text-sm text-navy-600">{{ __('crm.orders.final_status') }}</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($this->transitions as $target)
                    <button type="button" wire:key="status-{{ $target->value }}" wire:click="setStatus('{{ $target->value }}')" @disabled($target === $this->order->status)
                            @class(['crm-btn', 'bg-gold-400 text-navy-950' => $target === $this->order->status, 'border border-navy-100 bg-white text-navy-800 hover:bg-navy-50' => $target !== $this->order->status])>
                        {{ $target->label() }}
                    </button>
                @endforeach
            </div>
            @if ($this->order->status === OrderStatus::Sent)
                <p class="text-sm text-navy-600">{{ __('crm.orders.waiting_delivery') }}</p>
            @endif
        @endif
        @error('status')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- Lines --}}
    <div class="crm-card overflow-hidden">
        <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">{{ __('crm.orders.lines') }}</h3>

        @if ($this->order->lines->isEmpty())
            <p class="p-8 text-center text-sm text-navy-600">{{ __('crm.orders.no_lines') }}</p>
        @else
            <ul class="divide-y divide-navy-100">
                @foreach ($this->order->lines as $line)
                    <li wire:key="line-{{ $line->id }}" @class(['flex flex-wrap items-center gap-3 px-5 py-3', 'bg-navy-50/60 opacity-70' => ! $line->is_active])>
                        <div class="min-w-0 flex-1">
                            <p @class(['truncate font-medium text-navy-800', 'line-through' => ! $line->is_active])>{{ $line->item->name }}</p>
                            @unless ($line->is_active)
                                <span class="crm-badge bg-navy-100 text-navy-600">{{ __('crm.orders.line_inactive') }}</span>
                            @endunless
                            <p class="text-xs text-navy-600">{{ $line->item->code }} · {{ $line->item->unit->label() }} · ×{{ $line->item->step }}</p>
                            <p class="text-xs text-navy-600">
                                @if ($line->price !== null)
                                    {{ Money::format($line->price) }}@if (! $this->editable) × {{ $line->quantity }}@endif
                                @else
                                    {{ __('crm.orders.no_price') }}
                                @endif
                            </p>
                        </div>
                        @if ($this->editable)
                            <div class="w-28">
                                <input type="number" min="1" wire:model="quantities.{{ $line->id }}" wire:change="saveQuantity({{ $line->id }})"
                                       aria-label="{{ __('crm.orders.quantity') }}" class="crm-input py-1.5 text-right">
                                @error('quantities.'.$line->id)
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="button" wire:click="removeLine({{ $line->id }})" class="crm-btn-ghost text-red-600 hover:text-red-700">{{ __('crm.items.remove') }}</button>
                        @else
                            <p class="font-semibold text-navy-800">{{ $line->quantity }}</p>
                        @endif
                        <p @class(['w-32 text-right font-semibold text-navy-800', 'line-through' => ! $line->is_active])>{{ $line->total() !== null ? Money::format($line->total()) : '—' }}</p>
                        <button type="button" wire:click="toggleLine({{ $line->id }})" class="crm-btn-ghost">{{ $line->is_active ? __('crm.orders.line_deactivate') : __('crm.orders.line_activate') }}</button>
                        <div class="w-full">
                            <input type="text" maxlength="500" wire:model="comments.{{ $line->id }}" wire:change="saveComment({{ $line->id }})" placeholder="{{ __('crm.orders.comment') }}" aria-label="{{ __('crm.orders.comment') }}" class="crm-input py-1.5 text-sm">
                            @error('comments.'.$line->id)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-navy-100 bg-navy-50/60 px-5 py-3">
                <span class="font-semibold text-navy-800">{{ __('crm.orders.total') }}</span>
                <span class="text-lg font-bold text-navy-800">{{ Money::format($this->order->total()) }}</span>
                @if ($this->order->hasUnpricedLines())
                    <p class="w-full text-xs text-amber-700">{{ __('crm.orders.partial_total') }}</p>
                @endif
            </div>
        @endif

        @if ($this->editable)
            <form wire:submit="addLine" class="flex flex-wrap items-start gap-3 border-t border-navy-100 bg-navy-50/50 px-5 py-4">
                <div class="min-w-48 flex-1">
                    <select wire:model.live="newItemId" aria-label="{{ __('crm.orders.add_item') }}" class="crm-input">
                        <option value="">{{ __('crm.orders.add_item') }}</option>
                        @foreach ($this->addableItems as $item)
                            <option value="{{ $item->id }}" wire:key="addable-{{ $item->id }}">{{ $item->name }} ({{ $item->code }})</option>
                        @endforeach
                    </select>
                    @error('newItemId')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div class="w-28">
                    <input type="number" min="1" wire:model="newQuantity" aria-label="{{ __('crm.orders.quantity') }}" class="crm-input text-right">
                    @error('newQuantity')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="crm-btn-primary">{{ __('crm.orders.add') }}</button>
            </form>
        @endif
    </div>
</div>
