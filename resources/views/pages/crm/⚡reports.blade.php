<?php

use App\Enums\ItemUnit;
use App\Models\Company;
use App\Services\Reports\OrderReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::crm')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $companyId = '';

    public function mount(): void
    {
        $this->from = $this->validDate($this->from) ?? today()->toDateString();
        $this->to = $this->validDate($this->to) ?? $this->from;
    }

    public function rendering($view): void
    {
        $view->title(__('crm.reports.title'));
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }

    public function today(): void
    {
        $this->from = $this->to = today()->toDateString();
    }

    #[Computed]
    public function rangeIsValid(): bool
    {
        return $this->validDate($this->from) !== null
            && $this->validDate($this->to) !== null
            && $this->to >= $this->from;
    }

    /**
     * @return Collection<int, Company>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Company::query()->orderBy('name')->get(['id', 'name']);
    }

    private function report(): OrderReport
    {
        return new OrderReport(
            Carbon::parse($this->from),
            Carbon::parse($this->to),
            $this->companyId !== '' ? (int) $this->companyId : null,
        );
    }

    /**
     * @return SupportCollection<int, array{code: string, name_ru: string, name_uz: string, unit: string, quantity: int, locations: int}>
     */
    #[Computed]
    public function production(): SupportCollection
    {
        return $this->rangeIsValid ? $this->report()->production() : collect();
    }

    /**
     * Lines grouped as date → company/location → lines.
     *
     * @return SupportCollection<string, SupportCollection<int, object>>
     */
    #[Computed]
    public function groups(): SupportCollection
    {
        if (! $this->rangeIsValid) {
            return collect();
        }

        return $this->report()->lines()->groupBy(fn ($line) => Carbon::parse($line->order_date)->format('d.m.Y').'|'.$line->company.'|'.$line->location.'|'.$line->order_id);
    }

    public function exportUrl(): string
    {
        return route('crm.reports.export', array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'company' => $this->companyId,
        ]));
    }
};
?>

<div class="w-full space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.reports.title') }}</h2>
        @if ($this->rangeIsValid)
            <a href="{{ $this->exportUrl() }}" class="crm-btn-gold">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                {{ __('crm.reports.export') }}
            </a>
        @endif
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <label class="block text-sm text-navy-600">{{ __('crm.reports.from') }}
            <input type="date" wire:model.live="from" class="crm-input mt-1 w-44">
        </label>
        <label class="block text-sm text-navy-600">{{ __('crm.reports.to') }}
            <input type="date" wire:model.live="to" class="crm-input mt-1 w-44">
        </label>
        <button type="button" wire:click="today" class="crm-btn-secondary">{{ __('crm.orders.today') }}</button>
        <x-crm.company-picker :companies="$this->companies" />
    </div>

    @unless ($this->rangeIsValid)
        <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ __('crm.reports.invalid_range') }}</p>
    @else
        <p class="text-sm text-navy-600">{{ __('crm.reports.drafts_note') }}</p>

        <section class="crm-card overflow-hidden">
            <h3 class="border-b border-navy-100 px-5 py-4 font-semibold text-navy-800">{{ __('crm.reports.production') }}</h3>

            @if ($this->production->isEmpty())
                <p class="p-10 text-center text-navy-600">{{ __('crm.reports.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-navy-100">
                        <thead class="bg-navy-50/60">
                            <tr>
                                <th class="crm-th">{{ __('crm.reports.code') }}</th>
                                <th class="crm-th">{{ __('crm.common.name') }}</th>
                                <th class="crm-th">{{ __('crm.items.unit') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.quantity') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.locations') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-navy-100">
                            @foreach ($this->production as $row)
                                <tr wire:key="prod-{{ $row['code'] }}" class="hover:bg-navy-50/40">
                                    <td class="crm-td font-mono text-xs">{{ $row['code'] }}</td>
                                    <td class="crm-td">
                                        <p class="font-medium">{{ $row['name_ru'] }}</p>
                                        <p class="text-xs text-navy-600">{{ $row['name_uz'] }}</p>
                                    </td>
                                    <td class="crm-td">{{ ItemUnit::from($row['unit'])->label() }}</td>
                                    <td class="crm-td text-right text-base font-semibold">{{ number_format($row['quantity'], 0, '', ' ') }}</td>
                                    <td class="crm-td text-right">{{ $row['locations'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($this->groups->isNotEmpty())
            <section class="space-y-3">
                <h3 class="font-semibold text-navy-800">{{ __('crm.reports.by_location') }}</h3>

                @foreach ($this->groups as $key => $lines)
                    @php([$date, $company, $location, $orderId] = explode('|', $key))
                    <details wire:key="group-{{ $key }}" class="crm-card group">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3">
                            <span>
                                <span class="font-medium text-navy-800">{{ $company }} · {{ $location }}</span>
                                <span class="block text-xs text-navy-600">{{ $date }}</span>
                            </span>
                            <span class="flex items-center gap-3 text-sm">
                                <span class="font-semibold text-navy-800">{{ number_format($lines->sum('quantity'), 0, '', ' ') }}</span>
                                <a href="{{ route('crm.orders.show', $orderId) }}" wire:navigate class="crm-btn-ghost">№ {{ $orderId }}</a>
                            </span>
                        </summary>
                        <ul class="divide-y divide-navy-100 border-t border-navy-100">
                            @foreach ($lines as $line)
                                <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                                    <span class="min-w-0 truncate text-navy-800">{{ $line->name_ru }} <span class="text-xs text-navy-600">{{ $line->code }}</span></span>
                                    <span class="shrink-0 font-semibold">{{ $line->quantity }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </section>
        @endif
    @endunless
</div>
