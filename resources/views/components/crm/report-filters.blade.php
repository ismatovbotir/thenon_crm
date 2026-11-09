@props(['companies', 'valid' => true, 'company' => true, 'export' => false])

{{-- Period + company filter bar shared by the report pages. Binds to the page's from / to / companyId. --}}
<div class="space-y-3">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <label class="block text-sm text-navy-600">{{ __('crm.reports.from') }}
            <input type="date" wire:model.live="from" class="crm-input mt-1 w-44">
        </label>
        <label class="block text-sm text-navy-600">{{ __('crm.reports.to') }}
            <input type="date" wire:model.live="to" class="crm-input mt-1 w-44">
        </label>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="setRange('today')" class="crm-btn-secondary">{{ __('crm.orders.today') }}</button>
            <button type="button" wire:click="setRange('week')" class="crm-btn-secondary">{{ __('crm.reports.range_week') }}</button>
            <button type="button" wire:click="setRange('month')" class="crm-btn-secondary">{{ __('crm.reports.range_month') }}</button>
            <button type="button" wire:click="setRange('last_month')" class="crm-btn-secondary">{{ __('crm.reports.range_last_month') }}</button>
        </div>
        @if ($company)
            <select wire:model.live="companyId" aria-label="{{ __('crm.nav.companies') }}" class="crm-input sm:w-56">
                <option value="">{{ __('crm.orders.all_companies') }}</option>
                @foreach ($companies as $item)
                    <option value="{{ $item->id }}" wire:key="company-{{ $item->id }}">{{ $item->name }}</option>
                @endforeach
            </select>
        @endif
        @if ($export && $valid)
            <button type="button" wire:click="export" class="crm-btn-gold sm:ml-auto">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                {{ __('crm.reports.export') }}
            </button>
        @endif
    </div>

    @unless ($valid)
        <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ __('crm.reports.invalid_range') }}</p>
    @endunless
</div>
