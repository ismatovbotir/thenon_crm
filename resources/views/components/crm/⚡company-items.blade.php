<?php

use App\Services\CatalogCache;
use App\Models\Company;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $companyId;

    public string $search = '';

    /** @var list<int|string> */
    public array $selected = [];

    /** @var array<int, string|null> */
    public array $prices = [];

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;
        $this->loadPrices();
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function loadPrices(): void
    {
        $this->prices = $this->company()->items()->get()
            ->mapWithKeys(fn (Item $item) => [$item->id => $item->pivot->price !== null ? (string) (float) $item->pivot->price : ''])
            ->all();
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function assigned(): Collection
    {
        return $this->company()->items()->orderBy('name_ru')->get();
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function available(): Collection
    {
        return Item::query()
            ->where('is_active', true)
            ->whereNotIn('id', $this->assigned->modelKeys())
            ->search($this->search)
            ->orderBy('name_ru')
            ->limit(50)
            ->get();
    }

    public function addSelected(): void
    {
        $ids = Item::query()->whereIn('id', array_map('intval', $this->selected))->pluck('id')->all();

        $this->company()->items()->syncWithoutDetaching($ids);
        app(CatalogCache::class)->flush();

        $this->reset('selected');
        unset($this->assigned, $this->available);
        $this->loadPrices();
    }

    public function savePrice(int $itemId): void
    {
        $value = (string) ($this->prices[$itemId] ?? '');
        $value = str_replace(',', '.', preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? '');

        $this->prices[$itemId] = $value;

        $this->validate(
            ['prices.'.$itemId => ['nullable', 'numeric', 'min:0', 'max:999999999']],
            attributes: ['prices.'.$itemId => __('crm.items.price')],
        );

        $this->company()->items()->updateExistingPivot($itemId, ['price' => $value === '' ? null : $value]);
        app(CatalogCache::class)->flush();
    }

    public function remove(int $itemId): void
    {
        $this->company()->items()->detach($itemId);
        app(CatalogCache::class)->flush();

        unset($this->prices[$itemId], $this->assigned, $this->available);
    }
};
?>

<div class="grid gap-6 lg:grid-cols-5">
    {{-- Assigned items --}}
    <section class="crm-card overflow-hidden lg:col-span-3">
        <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">
            {{ __('crm.items.assigned') }} <span class="text-navy-600">({{ $this->assigned->count() }})</span>
        </h3>

        @if ($this->assigned->isEmpty())
            <p class="p-8 text-center text-sm text-navy-600">{{ __('crm.items.assigned_empty') }}</p>
        @else
            <ul class="divide-y divide-navy-100">
                @foreach ($this->assigned as $item)
                    <li wire:key="assigned-{{ $item->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-navy-800">{{ $item->name }}</p>
                            <p class="text-xs text-navy-600">{{ $item->code }} · {{ $item->unit->label() }} · ×{{ $item->step }}</p>
                        </div>
                        <div class="w-32">
                            <input type="text" inputmode="decimal" wire:model="prices.{{ $item->id }}" wire:change="savePrice({{ $item->id }})"
                                   placeholder="{{ __('crm.items.price_hint') }}" aria-label="{{ __('crm.items.price') }}" class="crm-input py-1.5 text-right">
                            @error('prices.'.$item->id)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="button" wire:click="remove({{ $item->id }})" class="crm-btn-ghost text-red-600 hover:text-red-700">{{ __('crm.items.remove') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Add items --}}
    <section class="crm-card lg:col-span-2">
        <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">{{ __('crm.items.add_title') }}</h3>
        <div class="space-y-3 p-5">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.items.search') }}" class="crm-input">

            @if ($this->available->isEmpty())
                <p class="py-4 text-center text-sm text-navy-600">{{ __('crm.items.nothing_to_add') }}</p>
            @else
                <ul class="max-h-80 divide-y divide-navy-100 overflow-y-auto rounded-xl ring-1 ring-navy-100">
                    @foreach ($this->available as $item)
                        <li wire:key="available-{{ $item->id }}">
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-navy-50">
                                <input type="checkbox" value="{{ $item->id }}" wire:model.live="selected" class="size-4 rounded border-navy-100 text-navy-800 focus:ring-gold-400">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-navy-800">{{ $item->name }}</span>
                                    <span class="block text-xs text-navy-600">{{ $item->code }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>

                <button type="button" wire:click="addSelected" @disabled(count($selected) === 0) class="crm-btn-primary w-full">
                    {{ __('crm.items.add_selected', ['count' => count($selected)]) }}
                </button>
            @endif
        </div>
    </section>
</div>
