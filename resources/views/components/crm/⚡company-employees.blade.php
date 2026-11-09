<?php

use App\Enums\Language;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public int $companyId;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $phone = '';

    public string $language = '';

    /** @var list<int|string> */
    public array $locationIds = [];

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Employee>
     */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Employee::query()
            ->where('company_id', $this->companyId)
            ->with('locations:id,name')
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function companyLocations(): Collection
    {
        return Location::query()->where('company_id', $this->companyId)->orderBy('name')->get(['id', 'name']);
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'phone', 'language', 'locationIds');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $employee = Employee::query()->where('company_id', $this->companyId)->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->phone = $employee->phone;
        $this->language = $employee->language?->value ?? '';
        $this->locationIds = $employee->locations()->pluck('locations.id')->map(fn ($id) => (string) $id)->all();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $this->phone = Employee::normalizePhone($this->phone);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^\+998\d{9}$/', Rule::unique('employees', 'phone')->ignore($this->editingId)],
            'language' => ['nullable', Rule::enum(Language::class)],
            'locationIds' => ['array'],
            'locationIds.*' => ['integer'],
        ], attributes: ['phone' => __('crm.common.phone')]);

        $locationIds = Location::query()
            ->where('company_id', $this->companyId)
            ->whereIn('id', array_map('intval', $data['locationIds'] ?? []))
            ->pluck('id')
            ->all();

        $attributes = [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'language' => $data['language'] ?: null,
        ];

        $employee = $this->editingId
            ? tap(Employee::query()->where('company_id', $this->companyId)->findOrFail($this->editingId))->update($attributes)
            : Employee::query()->create($attributes + ['company_id' => $this->companyId]);

        $employee->locations()->sync($locationIds);

        Location::query()
            ->where('default_employee_id', $employee->id)
            ->whereNotIn('id', $locationIds)
            ->update(['default_employee_id' => null]);

        $this->showForm = false;
        unset($this->employees);
    }

    public function unlinkTelegram(int $id): void
    {
        Employee::query()->where('company_id', $this->companyId)->findOrFail($id)
            ->forceFill(['telegram_id' => null, 'registered_at' => null])->save();

        unset($this->employees);
    }

    public function toggleActive(int $id): void
    {
        $employee = Employee::query()->where('company_id', $this->companyId)->findOrFail($id);
        $employee->update(['is_active' => ! $employee->is_active]);

        if (! $employee->is_active) {
            Location::query()->where('default_employee_id', $employee->id)->update(['default_employee_id' => null]);
        }

        unset($this->employees);
    }
};
?>

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.employees.search') }}" class="crm-input sm:max-w-sm">
        <button type="button" wire:click="create" class="crm-btn-gold">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.employees.add') }}
        </button>
    </div>

    <div class="crm-card overflow-hidden">
        @if ($this->employees->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.employees.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">{{ __('crm.common.name') }}</th>
                            <th class="crm-th">{{ __('crm.common.phone') }}</th>
                            <th class="crm-th">{{ __('crm.employees.locations') }}</th>
                            <th class="crm-th">{{ __('crm.employees.telegram') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->employees as $employee)
                            <tr wire:key="employee-{{ $employee->id }}" class="hover:bg-navy-50/40">
                                <td class="crm-td font-medium">{{ $employee->name }}</td>
                                <td class="crm-td whitespace-nowrap">{{ $employee->phone }}</td>
                                <td class="crm-td">
                                    @forelse ($employee->locations as $location)
                                        <span wire:key="el-{{ $employee->id }}-{{ $location->id }}" class="crm-badge mr-1 bg-navy-50 text-navy-700">{{ $location->name }}</span>
                                    @empty
                                        <span class="text-navy-600">{{ __('crm.employees.no_locations') }}</span>
                                    @endforelse
                                </td>
                                <td class="crm-td">
                                    <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $employee->isRegistered(), 'bg-gold-400/20 text-gold-600' => ! $employee->isRegistered()])>
                                        {{ $employee->isRegistered() ? __('crm.employees.registered') : __('crm.employees.not_registered') }}
                                    </span>
                                </td>
                                <td class="crm-td">
                                    <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $employee->is_active, 'bg-navy-100 text-navy-600' => ! $employee->is_active])>
                                        {{ $employee->is_active ? __('crm.common.active') : __('crm.common.inactive') }}
                                    </span>
                                </td>
                                <td class="crm-td">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="edit({{ $employee->id }})" class="crm-btn-ghost">{{ __('crm.common.edit') }}</button>
                                        @if ($employee->isRegistered())
                                            <button type="button" wire:click="unlinkTelegram({{ $employee->id }})" wire:confirm="{{ __('crm.employees.unlink_confirm') }}" class="crm-btn-ghost">{{ __('crm.employees.unlink') }}</button>
                                        @endif
                                        <button type="button" wire:click="toggleActive({{ $employee->id }})" class="crm-btn-ghost">
                                            {{ $employee->is_active ? __('crm.common.deactivate') : __('crm.common.activate') }}
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

    {{ $this->employees->links() }}

    @if ($showForm)
        <x-crm.modal :title="$editingId ? __('crm.employees.edit') : __('crm.employees.add')">
            <form wire:submit="save" class="space-y-4">
                <x-crm.field name="name" :label="__('crm.common.name')">
                    <input id="name" type="text" wire:model="name" class="crm-input" autofocus>
                </x-crm.field>
                <x-crm.field name="phone" :label="__('crm.common.phone')" :hint="__('crm.employees.phone_hint')">
                    <input id="phone" type="tel" wire:model="phone" placeholder="+998 90 123 45 67" class="crm-input">
                </x-crm.field>
                <x-crm.field name="language" :label="__('crm.employees.language')">
                    <select id="language" wire:model="language" class="crm-input">
                        <option value="">{{ __('crm.employees.language_unset') }}</option>
                        @foreach (\App\Enums\Language::cases() as $option)
                            <option value="{{ $option->value }}" wire:key="lang-{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-crm.field>
                <x-crm.field name="locationIds" :label="__('crm.employees.locations')" :hint="__('crm.employees.locations_hint')">
                    <div class="max-h-44 space-y-1 overflow-y-auto rounded-xl border border-navy-100 p-2">
                        @forelse ($this->companyLocations as $location)
                            <label wire:key="pick-{{ $location->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-navy-50">
                                <input type="checkbox" value="{{ $location->id }}" wire:model="locationIds" class="size-4 rounded border-navy-100 text-navy-800 focus:ring-gold-400">
                                <span class="text-sm text-navy-800">{{ $location->name }}</span>
                            </label>
                        @empty
                            <p class="px-2 py-1.5 text-sm text-navy-600">{{ __('crm.locations.empty') }}</p>
                        @endforelse
                    </div>
                </x-crm.field>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="crm-btn-secondary">{{ __('crm.common.cancel') }}</button>
                    <button type="submit" class="crm-btn-primary">{{ __('crm.common.save') }}</button>
                </div>
            </form>
        </x-crm.modal>
    @endif
</div>
