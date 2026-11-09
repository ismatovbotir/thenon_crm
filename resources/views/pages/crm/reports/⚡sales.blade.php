<?php

use App\Livewire\Concerns\HasReportFilters;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Layout('layouts::crm')] class extends Component
{
    use HasReportFilters;

    #[Url]
    public string $view = 'company';

    public function rendering($view): void
    {
        $view->title(__('crm.reports.sales_title'));
    }

    /**
     * @return array{orders: int, positions: int, quantity: int, amount: float, unpriced: int}
     */
    #[Computed]
    public function totals(): array
    {
        return $this->rangeIsValid ? $this->salesReport()->totals() : ['orders' => 0, 'positions' => 0, 'quantity' => 0, 'amount' => 0.0, 'unpriced' => 0];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->rangeIsValid) {
            return collect();
        }

        return match ($this->view) {
            'location' => $this->salesReport()->byLocation(),
            'item' => $this->salesReport()->byItem(),
            default => $this->salesReport()->byCompany(),
        };
    }

    public function export(): BinaryFileResponse
    {
        $head = match ($this->view) {
            'location' => [__('crm.nav.companies'), __('crm.orders.location')],
            'item' => [__('crm.reports.code'), __('crm.reports.name_ru')],
            default => [__('crm.nav.companies')],
        };
        $rows = [array_merge($head, [__('crm.reports.orders'), __('crm.reports.quantity'), __('crm.reports.amount'), __('crm.reports.unpriced')])];

        foreach ($this->rows as $row) {
            $lead = match ($this->view) {
                'location' => [$row['company'], $row['name']],
                'item' => [$row['code'], $row['name_ru']],
                default => [$row['name']],
            };
            $rows[] = array_merge($lead, [$row['orders'], $row['quantity'], $row['amount'], $row['unpriced']]);
        }

        return $this->downloadSheet(__('crm.reports.sales_title'), $rows, 'the-non-sales');
    }
};
?>

<div class="w-full space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.reports.sales_title') }}</h2>

    <x-crm.report-filters :companies="$this->companies" :valid="$this->rangeIsValid" export />

    @if ($this->rangeIsValid)
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="crm-card p-4"><p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.reports.orders') }}</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ $this->totals['orders'] }}</p></div>
            <div class="crm-card p-4"><p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.reports.quantity') }}</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ number_format($this->totals['quantity'], 0, '.', ' ') }}</p></div>
            <div class="crm-card p-4"><p class="text-xs uppercase tracking-wide text-navy-600">{{ __('crm.reports.amount') }}</p><p class="mt-1 text-2xl font-bold text-navy-800">{{ Money::format($this->totals['amount']) }}</p></div>
        </div>
        @if ($this->totals['unpriced'] > 0)
            <p class="text-sm text-amber-700">{{ __('crm.reports.unpriced_note', ['count' => $this->totals['unpriced']]) }}</p>
        @endif

        <div class="flex gap-2">
            @foreach (['company' => 'by_company', 'location' => 'by_location', 'item' => 'by_item'] as $key => $label)
                <button type="button" wire:key="view-{{ $key }}" wire:click="$set('view', '{{ $key }}')"
                        @class(['rounded-full px-4 py-1.5 text-sm font-medium', 'bg-navy-800 text-white' => $view === $key, 'bg-white text-navy-700 ring-1 ring-navy-100 hover:bg-navy-50' => $view !== $key])>{{ __('crm.reports.'.$label) }}</button>
            @endforeach
        </div>

        <section class="crm-card overflow-hidden">
            @if ($this->rows->isEmpty())
                <p class="p-10 text-center text-navy-600">{{ __('crm.reports.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-navy-100">
                        <thead class="bg-navy-50/60">
                            <tr>
                                @if ($view === 'item')
                                    <th class="crm-th">{{ __('crm.reports.code') }}</th>
                                @endif
                                <th class="crm-th">{{ $view === 'item' ? __('crm.common.name') : ($view === 'location' ? __('crm.orders.location') : __('crm.nav.companies')) }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.orders') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.quantity') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-navy-100">
                            @foreach ($this->rows as $row)
                                <tr wire:key="row-{{ $view }}-{{ $loop->index }}" class="hover:bg-navy-50/40">
                                    @if ($view === 'item')
                                        <td class="crm-td font-mono text-xs">{{ $row['code'] }}</td>
                                        <td class="crm-td font-medium">{{ $row['name_ru'] }}</td>
                                    @elseif ($view === 'location')
                                        <td class="crm-td"><p class="font-medium">{{ $row['name'] }}</p><p class="text-xs text-navy-600">{{ $row['company'] }}</p></td>
                                    @else
                                        <td class="crm-td font-medium">{{ $row['name'] }}</td>
                                    @endif
                                    <td class="crm-td text-right">{{ $row['orders'] }}</td>
                                    <td class="crm-td text-right">{{ number_format($row['quantity'], 0, '.', ' ') }}</td>
                                    <td class="crm-td text-right font-semibold">{{ $row['amount'] > 0 ? Money::format($row['amount']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
