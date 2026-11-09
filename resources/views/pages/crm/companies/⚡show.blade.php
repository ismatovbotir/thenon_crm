<?php

use App\Models\Company;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    public Company $company;

    #[Url]
    public string $tab = 'items';

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (! in_array($this->tab, ['items', 'locations', 'employees'], true)) {
            $this->tab = 'items';
        }
    }

    public function rendering($view): void
    {
        $view->title($this->company->name);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['items', 'locations', 'employees'], true)) {
            $this->tab = $tab;
        }
    }
};
?>

<div class="w-full space-y-6">
    <div>
        <a href="{{ route('crm.companies.index') }}" wire:navigate class="text-sm font-medium text-navy-600 hover:text-navy-950">&larr; {{ __('crm.companies.title') }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h2 class="text-2xl font-semibold text-navy-800">{{ $company->name }}</h2>
            <span @class(['crm-badge', 'bg-emerald-100 text-emerald-700' => $company->is_active, 'bg-navy-100 text-navy-600' => ! $company->is_active])>
                {{ $company->is_active ? __('crm.common.active') : __('crm.common.inactive') }}
            </span>
        </div>
        @if ($company->notes)
            <p class="mt-1 text-sm text-navy-600">{{ $company->notes }}</p>
        @endif
    </div>

    <div class="flex gap-1 overflow-x-auto border-b border-navy-100">
        @foreach (['items', 'locations', 'employees'] as $key)
            <button type="button" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                    @class([
                        '-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                        'border-gold-400 text-navy-800' => $tab === $key,
                        'border-transparent text-navy-600 hover:text-navy-950' => $tab !== $key,
                    ])>
                {{ __('crm.companies.'.$key) }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'items')
        <livewire:crm.company-items :company-id="$company->id" :key="'items-'.$company->id" />
    @elseif ($tab === 'locations')
        <livewire:crm.company-locations :company-id="$company->id" :key="'locations-'.$company->id" />
    @else
        <livewire:crm.company-employees :company-id="$company->id" :key="'employees-'.$company->id" />
    @endif
</div>
