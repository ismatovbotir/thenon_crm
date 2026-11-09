<?php

use App\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::crm')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $notes = '';

    public function rendering($view): void
    {
        $view->title(__('crm.companies.title'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Company>
     */
    #[Computed]
    public function companies(): LengthAwarePaginator
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return Company::query()
            ->withCount(['locations', 'employees', 'items'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', $like))
            ->orderBy('name')
            ->paginate(15);
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'notes');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $company = Company::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $company->id;
        $this->name = $company->name;
        $this->notes = (string) $company->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($this->editingId)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['notes'] = $data['notes'] ?: null;

        if ($this->editingId) {
            Company::query()->findOrFail($this->editingId)->update($data);
        } else {
            Company::query()->create($data);
        }

        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $company = Company::query()->findOrFail($id);
        $company->update(['is_active' => ! $company->is_active]);
    }
};
?>

<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.companies.title') }}</h2>
        <button type="button" wire:click="create" class="crm-btn-gold">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            {{ __('crm.companies.add') }}
        </button>
    </div>

    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('crm.companies.search') }}" class="crm-input max-w-md">

    <div class="crm-card overflow-hidden">
        @if ($this->companies->isEmpty())
            <p class="p-10 text-center text-navy-600">{{ __('crm.companies.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-navy-100">
                    <thead class="bg-navy-50/60">
                        <tr>
                            <th class="crm-th">{{ __('crm.common.name') }}</th>
                            <th class="crm-th">{{ __('crm.companies.locations') }}</th>
                            <th class="crm-th">{{ __('crm.companies.employees') }}</th>
                            <th class="crm-th">{{ __('crm.companies.items') }}</th>
                            <th class="crm-th">{{ __('crm.common.status') }}</th>
                            <th class="crm-th text-right">{{ __('crm.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-100">
                        @foreach ($this->companies as $company)
                            <tr wire:key="company-{{ $company->id }}" class="hover:bg-navy-50/40">
                                <td class="crm-td font-medium">
                                    <a href="{{ route('crm.companies.show', $company) }}" wire:navigate class="text-navy-800 hover:text-gold-600">{{ $company->name }}</a>
                                </td>
                                <td class="crm-td">{{ $company->locations_count }}</td>
                                <td class="crm-td">{{ $company->employees_count }}</td>
                                <td class="crm-td">{{ $company->items_count }}</td>
                                <td class="crm-td">
                                    <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $company->is_active, 'bg-navy-100 text-navy-600' => ! $company->is_active])>
                                        {{ $company->is_active ? __('crm.common.active') : __('crm.common.inactive') }}
                                    </span>
                                </td>
                                <td class="crm-td">
                                    <div class="flex justify-end gap-1">
                                        <a href="{{ route('crm.companies.show', $company) }}" wire:navigate class="crm-btn-ghost">{{ __('crm.companies.open') }}</a>
                                        <button type="button" wire:click="edit({{ $company->id }})" class="crm-btn-ghost">{{ __('crm.common.edit') }}</button>
                                        <button type="button" wire:click="toggleActive({{ $company->id }})" class="crm-btn-ghost">
                                            {{ $company->is_active ? __('crm.common.deactivate') : __('crm.common.activate') }}
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

    {{ $this->companies->links() }}

    @if ($showForm)
        <x-crm.modal :title="$editingId ? __('crm.companies.edit') : __('crm.companies.add')">
            <form wire:submit="save" class="space-y-4">
                <x-crm.field name="name" :label="__('crm.common.name')">
                    <input id="name" type="text" wire:model="name" class="crm-input" autofocus>
                </x-crm.field>
                <x-crm.field name="notes" :label="__('crm.common.notes')">
                    <textarea id="notes" wire:model="notes" rows="3" class="crm-input"></textarea>
                </x-crm.field>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeForm" class="crm-btn-secondary">{{ __('crm.common.cancel') }}</button>
                    <button type="submit" class="crm-btn-primary">{{ __('crm.common.save') }}</button>
                </div>
            </form>
        </x-crm.modal>
    @endif
</div>
