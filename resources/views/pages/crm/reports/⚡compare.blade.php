<?php

use App\Livewire\Concerns\HasReportFilters;
use App\Services\Reports\SalesReport;
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
        $view->title(__('crm.reports.compare_title'));
    }

    /**
     * The period of the same length that ends the day before the selected one starts.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    #[Computed]
    public function previousPeriod(): array
    {
        $from = Carbon::parse($this->from);
        $days = $from->diffInDays(Carbon::parse($this->to)) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    /**
     * One row per company with this period, the previous one and the change.
     *
     * @return Collection<int, array{name: string, quantity: int, amount: float, previous_quantity: int, previous_amount: float, quantity_change: ?float, amount_change: ?float}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->rangeIsValid) {
            return collect();
        }

        $companyId = $this->companyId !== '' ? (int) $this->companyId : null;
        $current = $this->salesReport()->byCompany()->keyBy('id');
        [$prevFrom, $prevTo] = $this->previousPeriod;
        $previous = (new SalesReport($prevFrom, $prevTo, $companyId))->byCompany()->keyBy('id');

        return $current->keys()->merge($previous->keys())->unique()->map(function ($id) use ($current, $previous) {
            $now = $current->get($id);
            $before = $previous->get($id);

            return [
                'name' => ($now ?? $before)['name'],
                'quantity' => $now['quantity'] ?? 0,
                'amount' => $now['amount'] ?? 0.0,
                'previous_quantity' => $before['quantity'] ?? 0,
                'previous_amount' => $before['amount'] ?? 0.0,
                'quantity_change' => $this->change($now['quantity'] ?? 0, $before['quantity'] ?? 0),
                'amount_change' => $this->change($now['amount'] ?? 0.0, $before['amount'] ?? 0.0),
            ];
        })->sortByDesc('quantity')->values();
    }

    /**
     * Percentage change, or null when there is nothing to compare with.
     */
    private function change(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    public function export(): BinaryFileResponse
    {
        $rows = [[__('crm.nav.companies'), __('crm.reports.quantity'), __('crm.reports.previous_quantity'), __('crm.reports.change').' %', __('crm.reports.amount'), __('crm.reports.previous_amount'), __('crm.reports.change').' %']];

        foreach ($this->rows as $row) {
            $rows[] = [$row['name'], $row['quantity'], $row['previous_quantity'], $row['quantity_change'], $row['amount'], $row['previous_amount'], $row['amount_change']];
        }

        return $this->downloadSheet(__('crm.reports.compare_title'), $rows, 'the-non-compare');
    }
};
?>

<div class="w-full space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.reports.compare_title') }}</h2>

    <x-crm.report-filters :companies="$this->companies" :valid="$this->rangeIsValid" export />

    @if ($this->rangeIsValid)
        <p class="text-sm text-navy-600">
            {{ __('crm.reports.compare_text', ['from' => $this->previousPeriod[0]->format('d.m.Y'), 'to' => $this->previousPeriod[1]->format('d.m.Y')]) }}
        </p>

        <section class="crm-card overflow-hidden">
            @if ($this->rows->isEmpty())
                <p class="p-10 text-center text-navy-600">{{ __('crm.reports.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-navy-100">
                        <thead class="bg-navy-50/60">
                            <tr>
                                <th class="crm-th">{{ __('crm.nav.companies') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.quantity') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.previous_quantity') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.change') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.amount') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.previous_amount') }}</th>
                                <th class="crm-th text-right">{{ __('crm.reports.change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-navy-100">
                            @foreach ($this->rows as $row)
                                <tr wire:key="cmp-{{ $loop->index }}" class="hover:bg-navy-50/40">
                                    <td class="crm-td font-medium">{{ $row['name'] }}</td>
                                    <td class="crm-td text-right font-semibold">{{ number_format($row['quantity'], 0, '.', ' ') }}</td>
                                    <td class="crm-td text-right text-navy-600">{{ number_format($row['previous_quantity'], 0, '.', ' ') }}</td>
                                    <td @class(['crm-td text-right font-semibold', 'text-emerald-600' => ($row['quantity_change'] ?? 0) > 0, 'text-red-600' => ($row['quantity_change'] ?? 0) < 0])>
                                        {{ $row['quantity_change'] === null ? '—' : ($row['quantity_change'] > 0 ? '+' : '').$row['quantity_change'].'%' }}
                                    </td>
                                    <td class="crm-td text-right font-semibold">{{ $row['amount'] > 0 ? Money::format($row['amount']) : '—' }}</td>
                                    <td class="crm-td text-right text-navy-600">{{ $row['previous_amount'] > 0 ? Money::format($row['previous_amount']) : '—' }}</td>
                                    <td @class(['crm-td text-right font-semibold', 'text-emerald-600' => ($row['amount_change'] ?? 0) > 0, 'text-red-600' => ($row['amount_change'] ?? 0) < 0])>
                                        {{ $row['amount_change'] === null ? '—' : ($row['amount_change'] > 0 ? '+' : '').$row['amount_change'].'%' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
