<?php

use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $companyId;

    public bool $showForm = false;

    public ?int $scheduleLocationId = null;

    public ?int $editingId = null;

    public string $name = '';

    public string $address = '';

    public string $yandexUrl = '';

    public ?int $defaultEmployeeId = null;

    public function openSchedule(int $id): void
    {
        $this->scheduleLocationId = Location::query()->where('company_id', $this->companyId)->findOrFail($id)->id;
    }

    #[On('schedule-closed')]
    public function closeSchedule(): void
    {
        $this->scheduleLocationId = null;
    }

    public function mount(int $companyId): void
    {
        $this->companyId = $companyId;
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()
            ->where('company_id', $this->companyId)
            ->with('defaultEmployee')
            ->withCount('employees')
            ->orderBy('name')
            ->get();
    }

    /**
     * Employees already connected to the location being edited.
     *
     * @return Collection<int, Employee>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (! $this->editingId) {
            return new Collection;
        }

        return Employee::query()
            ->whereHas('locations', fn ($query) => $query->whereKey($this->editingId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'address', 'yandexUrl', 'defaultEmployeeId');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $location = Location::query()->where('company_id', $this->companyId)->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $location->id;
        $this->name = $location->name;
        $this->address = (string) $location->address;
        $this->yandexUrl = (string) $location->yandex_url;
        $this->defaultEmployeeId = $location->default_employee_id;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('locations', 'name')->where('company_id', $this->companyId)->ignore($this->editingId),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'yandexUrl' => ['nullable', 'url:https', 'max:500', 'regex:~^https://([a-z0-9-]+\.)*yandex\.[a-z]{2,3}(\.[a-z]{2})?/~i'],
            'defaultEmployeeId' => [
                'nullable', 'integer',
                Rule::exists('employee_location', 'employee_id')->where('location_id', $this->editingId ?? 0),
            ],
        ]);

        $attributes = [
            'name' => $data['name'],
            'address' => $data['address'] ?: null,
            'yandex_url' => $data['yandexUrl'] ?: null,
            'default_employee_id' => $data['defaultEmployeeId'] ?? null,
        ];

        if ($this->editingId) {
            Location::query()->where('company_id', $this->companyId)->findOrFail($this->editingId)->update($attributes);
        } else {
            Location::query()->create($attributes + ['company_id' => $this->companyId]);
        }

        $this->showForm = false;
        unset($this->locations);
    }

    public function toggleActive(int $id): void
    {
        $location = Location::query()->where('company_id', $this->companyId)->findOrFail($id);
        $location->update(['is_active' => ! $location->is_active]);

        unset($this->locations);
    }
};
?>

<div class="space-y-4">
    <div class="flex justify-end">
        <button type="button" wire:click="create" class="crm-btn-gold">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.locations.add') }}
        </button>
    </div>

    <div class="crm-card overflow-hidden">
        @if ($this->locations->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.locations.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">{{ __('crm.common.name') }}</th>
                            <th class="crm-th">{{ __('crm.common.address') }}</th>
                            <th class="crm-th">{{ __('crm.locations.default_employee') }}</th>
                            <th class="crm-th">{{ __('crm.locations.employees_count') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->locations as $location)
                            <tr wire:key="location-{{ $location->id }}" class="hover:bg-navy-50/40">
                                <td class="crm-td font-medium">{{ $location->name }}</td>
                                <td class="crm-td">
                                    {{ $location->address ?: __('crm.common.none') }}
                                    @if ($location->yandex_url)
                                        <a href="{{ $location->yandex_url }}" target="_blank" rel="noopener noreferrer" class="ml-1 text-xs font-medium text-gold-600 hover:underline">{{ __('crm.locations.map') }}</a>
                                    @endif
                                </td>
                                <td class="crm-td">{{ $location->defaultEmployee?->name ?? __('crm.common.none') }}</td>
                                <td class="crm-td">{{ $location->employees_count }}</td>
                                <td class="crm-td">
                                    <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $location->is_active, 'bg-navy-100 text-navy-600' => ! $location->is_active])>
                                        {{ $location->is_active ? __('crm.common.active') : __('crm.common.inactive') }}
                                    </span>
                                </td>
                                <td class="crm-td">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="openSchedule({{ $location->id }})" class="crm-btn-ghost">{{ __('crm.schedule.button') }}</button>
                                        <button type="button" wire:click="edit({{ $location->id }})" class="crm-btn-ghost">{{ __('crm.common.edit') }}</button>
                                        <button type="button" wire:click="toggleActive({{ $location->id }})" class="crm-btn-ghost">
                                            {{ $location->is_active ? __('crm.common.deactivate') : __('crm.common.activate') }}
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

    @if ($showForm)
        <x-crm.modal :title="$editingId ? __('crm.locations.edit') : __('crm.locations.add')">
            <form wire:submit="save" class="space-y-4">
                <x-crm.field name="name" :label="__('crm.common.name')">
                    <input id="name" type="text" wire:model="name" class="crm-input" autofocus>
                </x-crm.field>
                <x-crm.field name="address" :label="__('crm.common.address')">
                    <input id="address" type="text" wire:model="address" class="crm-input">
                </x-crm.field>
                <x-crm.field name="yandexUrl" :label="__('crm.locations.yandex_url')" :hint="__('crm.locations.yandex_hint')">
                    <input id="yandexUrl" type="url" wire:model="yandexUrl" placeholder="https://yandex.uz/maps/..." class="crm-input">
                </x-crm.field>
                @if ($editingId)
                    <x-crm.field name="defaultEmployeeId" :label="__('crm.locations.default_employee')" :hint="__('crm.locations.default_hint')">
                        <select id="defaultEmployeeId" wire:model="defaultEmployeeId" class="crm-input">
                            <option value="">{{ __('crm.locations.no_default') }}</option>
                            @foreach ($this->candidates as $candidate)
                                <option value="{{ $candidate->id }}" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->phone }}</option>
                            @endforeach
                        </select>
                    </x-crm.field>
                @endif
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="crm-btn-secondary">{{ __('crm.common.cancel') }}</button>
                    <button type="submit" class="crm-btn-primary">{{ __('crm.common.save') }}</button>
                </div>
            </form>
        </x-crm.modal>
    @endif

    @if ($scheduleLocationId)
        <livewire:crm.location-schedule :location-id="$scheduleLocationId" :key="'schedule-'.$scheduleLocationId" />
    @endif
</div>
