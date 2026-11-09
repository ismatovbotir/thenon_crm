<?php

use App\Livewire\Concerns\HasReportFilters;
use App\Services\Reports\SalesReport;
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
        $view->title(__('crm.reports.missing_title'));
    }

    #[Computed]
    public function tooLong(): bool
    {
        return $this->rangeIsValid
            && Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) >= SalesReport::MAX_MISSING_DAYS;
    }

    /**
     * @return Collection<int, array{location_id: int, company: string, location: string, expected: int, ordered: int, missed: int, dates: list<string>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->rangeIsValid && ! $this->tooLong ? $this->salesReport()->missing() : collect();
    }

    public function export(): BinaryFileResponse
    {
        $rows = [[__('crm.nav.companies'), __('crm.orders.location'), __('crm.reports.expected_days'), __('crm.reports.ordered_days'), __('crm.reports.missed_days'), __('crm.reports.missed_dates')]];

        foreach ($this->rows as $row) {
            $rows[] = [$row['company'], $row['location'], $row['expected'], $row['ordered'], $row['missed'], implode(', ', array_map(fn ($date) => Carbon::parse($date)->format('d.m'), $row['dates']))];
        }

        return $this->downloadSheet(__('crm.reports.missing_title'), $rows, 'the-non-missing');
    }
};
?>

<div class="w-full space-y-6">
    <h2 class="text-2xl font-semibold text-navy-800">{{ __('crm.reports.missing_title') }}</h2>

    <x-crm.report-filters :companies="$this->companies" :valid="$this->rangeIsValid" export />

    @if ($this->rangeIsValid)
        <p class="text-sm text-navy-600">{{ __('crm.reports.missing_text') }}</p>

        @if ($this->tooLong)
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('crm.reports.missing_too_long', ['days' => SalesReport::MAX_MISSING_DAYS]) }}</p>
        @else
            <section class="crm-card overflow-hidden">
                @if ($this->rows->isEmpty())
                    <p class="p-10 text-center font-medium text-emerald-700">{{ __('crm.reports.missing_none') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-navy-100">
                            <thead class="bg-navy-50/60">
                                <tr>
                                    <th class="crm-th">{{ __('crm.orders.location') }}</th>
                                    <th class="crm-th text-right">{{ __('crm.reports.ordered_days') }}</th>
                                    <th class="crm-th text-right">{{ __('crm.reports.missed_days') }}</th>
                                    <th class="crm-th">{{ __('crm.reports.missed_dates') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-navy-100">
                                @foreach ($this->rows as $row)
                                    <tr wire:key="miss-{{ $row['location_id'] }}" class="hover:bg-navy-50/40">
                                        <td class="crm-td"><p class="font-medium">{{ $row['location'] }}</p><p class="text-xs text-navy-600">{{ $row['company'] }}</p></td>
                                        <td class="crm-td text-right">{{ $row['ordered'] }} / {{ $row['expected'] }}</td>
                                        <td class="crm-td text-right font-semibold text-red-600">{{ $row['missed'] }}</td>
                                        <td class="crm-td">
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($row['dates'] as $date)
                                                    <span class="rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700">{{ Carbon::parse($date)->format('d.m') }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @endif
</div>
