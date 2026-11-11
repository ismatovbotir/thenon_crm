<?php

use App\Enums\ItemUnit;
use App\Models\Item;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts::crm')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $nameUz = '';

    public string $nameRu = '';

    public string $unit = 'pcs';

    public int|string $step = 1;

    public string $category = '';

    public $image = null;

    public ?string $currentImage = null;

    public bool $removeImage = false;

    public function rendering($view): void
    {
        $view->title(__('crm.items.title'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedImage(): void
    {
        try {
            $this->validateOnly('image', ['image' => ['nullable', 'image', 'max:5120']]);
        } catch (ValidationException $exception) {
            $this->reset('image');

            throw $exception;
        }
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return Item::query()->search($this->search)->orderBy('name_ru')->paginate(20);
    }

    public function create(): void
    {
        $this->reset('editingId', 'code', 'nameUz', 'nameRu', 'category', 'image', 'currentImage', 'removeImage');
        $this->unit = ItemUnit::Pcs->value;
        $this->step = 1;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $item = Item::query()->findOrFail($id);

        $this->resetValidation();
        $this->reset('image', 'removeImage');
        $this->editingId = $item->id;
        $this->code = $item->code;
        $this->nameUz = $item->name_uz;
        $this->nameRu = $item->name_ru;
        $this->unit = $item->unit->value;
        $this->step = $item->step;
        $this->category = (string) $item->category;
        $this->currentImage = $item->image_url;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->code = trim($this->code);

        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('items', 'code')->ignore($this->editingId)],
            'nameUz' => ['required', 'string', 'max:255'],
            'nameRu' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(ItemUnit::class)],
            'step' => ['required', 'integer', 'min:1', 'max:100000'],
            'category' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'max:5120'],
        ], attributes: [
            'code' => __('crm.items.code'),
            'nameUz' => __('crm.items.name_uz'),
            'nameRu' => __('crm.items.name_ru'),
            'step' => __('crm.items.step'),
        ]);

        $attributes = [
            'code' => $data['code'],
            'name_uz' => $data['nameUz'],
            'name_ru' => $data['nameRu'],
            'unit' => $data['unit'],
            'step' => (int) $data['step'],
            'category' => $data['category'] ?: null,
        ];

        $item = $this->editingId ? Item::query()->findOrFail($this->editingId) : new Item;

        if ($this->image) {
            $this->discardStoredImage($item);
            $attributes['image_path'] = $this->image->store('items', 'public');
        } elseif ($this->removeImage) {
            $this->discardStoredImage($item);
            $attributes['image_path'] = null;
        }

        $item->fill($attributes)->save();

        $this->showForm = false;
        $this->dispatch('notify', message: __('crm.common.saved'));
        $this->reset('image', 'removeImage');
    }

    public function toggleActive(int $id): void
    {
        $item = Item::query()->findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
    }

    private function discardStoredImage(Item $item): void
    {
        if ($item->image_path) {
            Storage::disk('public')->delete($item->image_path);
        }
    }
};
?>

<div class="w-full space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.items.title') }}</h2>
        <button type="button" wire:click="create" class="crm-btn-gold">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.items.add') }}
        </button>
    </div>

    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.items.search') }}" class="crm-input max-w-md">

    <div class="crm-card overflow-hidden">
        @if ($this->items->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.items.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">{{ __('crm.items.image') }}</th>
                            <th class="crm-th">{{ __('crm.items.code') }}</th>
                            <th class="crm-th">{{ __('crm.common.name') }}</th>
                            <th class="crm-th">{{ __('crm.items.category') }}</th>
                            <th class="crm-th">{{ __('crm.items.unit') }}</th>
                            <th class="crm-th">{{ __('crm.items.step') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->items as $item)
                            <tr wire:key="item-{{ $item->id }}" class="hover:bg-navy-50/40">
                                <td class="crm-td">
                                    @if ($item->image_url)
                                        <img src="{{ $item->image_url }}" alt="" loading="lazy" class="size-10 rounded-lg object-cover">
                                    @else
                                        <span class="flex size-10 items-center justify-center rounded-lg bg-cream-100 text-crust-500">
                                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8"/></svg>
                                        </span>
                                    @endif
                                </td>
                                <td class="crm-td font-mono text-xs">{{ $item->code }}</td>
                                <td class="crm-td">
                                    <p class="font-medium">{{ $item->name_ru }}</p>
                                    <p class="text-xs text-navy-600">{{ $item->name_uz }}</p>
                                </td>
                                <td class="crm-td">{{ $item->category ?: __('crm.common.none') }}</td>
                                <td class="crm-td">{{ $item->unit->label() }}</td>
                                <td class="crm-td">×{{ $item->step }}</td>
                                <td class="crm-td">
                                    <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $item->is_active, 'bg-navy-100 text-navy-600' => ! $item->is_active])>
                                        {{ $item->is_active ? __('crm.common.active') : __('crm.common.inactive') }}
                                    </span>
                                </td>
                                <td class="crm-td">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="edit({{ $item->id }})" class="crm-btn-ghost">{{ __('crm.common.edit') }}</button>
                                        <button type="button" wire:click="toggleActive({{ $item->id }})" class="crm-btn-ghost">
                                            {{ $item->is_active ? __('crm.common.deactivate') : __('crm.common.activate') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $this->items->links() }}

    @if ($showForm)
        <x-crm.modal :title="$editingId ? __('crm.items.edit') : __('crm.items.add')" max-width="max-w-xl">
            <form wire:submit="save" class="space-y-4">
                <x-crm.field name="code" :label="__('crm.items.code')" :hint="__('crm.items.code_hint')">
                    <input id="code" type="text" wire:model="code" class="crm-input" autofocus>
                </x-crm.field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-crm.field name="nameRu" :label="__('crm.items.name_ru')">
                        <input id="nameRu" type="text" wire:model="nameRu" class="crm-input">
                    </x-crm.field>
                    <x-crm.field name="nameUz" :label="__('crm.items.name_uz')">
                        <input id="nameUz" type="text" wire:model="nameUz" class="crm-input">
                    </x-crm.field>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-crm.field name="unit" :label="__('crm.items.unit')">
                        <select id="unit" wire:model="unit" class="crm-input">
                            @foreach (\App\Enums\ItemUnit::cases() as $option)
                                <option value="{{ $option->value }}" wire:key="unit-{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-crm.field>
                    <x-crm.field name="step" :label="__('crm.items.step')" class="sm:col-span-1">
                        <input id="step" type="number" min="1" wire:model="step" class="crm-input">
                    </x-crm.field>
                    <x-crm.field name="category" :label="__('crm.items.category')">
                        <input id="category" type="text" wire:model="category" class="crm-input">
                    </x-crm.field>
                </div>
                <p class="-mt-2 text-xs text-navy-600">{{ __('crm.items.step_hint') }}</p>

                <x-crm.field name="image" :label="__('crm.items.image')" :hint="__('crm.items.image_hint')">
                    <div class="flex items-center gap-4">
                        @if ($image)
                            <img src="{{ $image->temporaryUrl() }}" alt="" class="size-16 rounded-xl object-cover">
                        @elseif ($currentImage && ! $removeImage)
                            <img src="{{ $currentImage }}" alt="" class="size-16 rounded-xl object-cover">
                        @endif
                        <input id="image" type="file" wire:model="image" accept="image/*" class="block w-full text-sm text-navy-700 file:mr-3 file:rounded-lg file:border-0 file:bg-navy-50 file:px-3 file:py-2 file:font-medium file:text-navy-800 hover:file:bg-navy-100">
                    </div>
                    @if ($currentImage && ! $image)
                        <label class="mt-2 flex items-center gap-2 text-sm text-navy-700">
                            <input type="checkbox" wire:model="removeImage" class="size-4 rounded border-navy-100 text-navy-800 focus:ring-gold-400">
                            {{ __('crm.items.remove_image') }}
                        </label>
                    @endif
                </x-crm.field>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="crm-btn-secondary">{{ __('crm.common.cancel') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="image,save" class="crm-btn-primary">{{ __('crm.common.save') }}</button>
                </div>
            </form>
        </x-crm.modal>
    @endif
</div>
