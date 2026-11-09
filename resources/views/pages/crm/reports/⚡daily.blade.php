<?php

use App\Livewire\Concerns\HasReportFilters;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Layout('layouts::crm')] class extends Component
{
    use HasReportFilters;

    public function rendering($view): void
    {
        $view->title(__('crm.reports.daily_title'));
    }

    /**
     * @return Collection<int, array{date: string, orders: int, positions: int, quantity: int, amount: float, unpriced: int}>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->rangeIsValid ? $this->salesReport()->byDay() : collect();
    }

    public function export(): BinaryFileResponse
    {
        $rows = [[__('crm.orders.date'), __('crm.reports.orders'), __('crm.reports.quantity'), __('crm.reports.amount')]];

        foreach ($this->days as $day) {
            $rows[] = [Carbon::parse($day['date'])->format('d.m.Y'), $day['orders'], $day['quantity'], $day['amount']];
        }

        return $this->downloadSheet(__('crm.reports.daily_title'), $rows, 'the-non-daily');
    }
};
?>

<div class="w-full space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.reports.daily_title') }}</h2>

    <x-crm.report-filters :companies="$this->companies" :valid="$this->rangeIsValid" export />

    @if ($this->rangeIsValid)
        @php($peak = max(1, (int) $this->days->max('quantity')))
        <section class="crm-card overflow-hidden">
            @if ($this->days->isEmpty())
                <p class="p-10 text-center text-navy-600">{{ __('crm.reports.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-navy-100">
                        <thead class="bg-navy-50/60">
                            <tr>
                                <th class="crm-th">{{ __('crm.orders.date') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.orders') }}</th>
                                <th class="crm-th w-1/3">{{ __('crm.reports.quantity') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-navy-100">
                            @foreach ($this->days as $day)
                                <tr wire:key="day-{{ $day['date'] }}" class="hover:bg-navy-50/40">
                                    <td class="crm-td font-medium">{{ Carbon::parse($day['date'])->translatedFormat('d.m.Y, D') }}</td>
                                    <td class="crm-td text-right">{{ $day['orders'] }}</td>
                                    <td class="crm-td">
                                        <div class="flex items-center gap-3">
                                            <div class="h-2.5 flex-1 rounded-full bg-navy-50"><div class="h-2.5 rounded-full bg-gold-400" style="width: {{ round($day['quantity'] / $peak * 100) }}%"></div></div>
                                            <span class="w-20 shrink-0 text-right tabular-nums">{{ number_format($day['quantity'], 0, '.', ' ') }}</span>
                                        </div>
                                    </td>
                                    <td class="crm-td text-right font-semibold">{{ $day['amount'] > 0 ? Money::format($day['amount']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-navy-50/60 font-semibold">
                            <tr>
                                <td class="crm-td">{{ __('crm.orders.total') }}</td>
                                <td class="crm-td text-right">{{ $this->days->sum('orders') }}</td>
                                <td class="crm-td text-right">{{ number_format($this->days->sum('quantity'), 0, '.', ' ') }}</td>
                                <td class="crm-td text-right">{{ Money::format($this->days->sum('amount')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
