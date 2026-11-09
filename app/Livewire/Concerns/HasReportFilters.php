<?php

namespace App\Livewire\Concerns;

use App\Models\Company;
use App\Services\Reports\SalesReport;
use App\Services\Reports\XlsxWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Period and company filters shared by the report pages, plus Excel download of a table.
 */
trait HasReportFilters
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $companyId = '';

    public function mountHasReportFilters(): void
    {
        $this->from = $this->validDate($this->from) ?? today()->startOfMonth()->toDateString();
        $this->to = $this->validDate($this->to) ?? today()->toDateString();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }

    /**
     * Quick period buttons: today, week (last 7 days), month (this month), last_month.
     */
    public function setRange(string $preset): void
    {
        [$this->from, $this->to] = match ($preset) {
            'week' => [today()->subDays(6)->toDateString(), today()->toDateString()],
            'month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'last_month' => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => [today()->toDateString(), today()->toDateString()],
        };
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

    protected function salesReport(): SalesReport
    {
        return new SalesReport(
            Carbon::parse($this->from),
            Carbon::parse($this->to),
            $this->companyId !== '' ? (int) $this->companyId : null,
        );
    }

    /**
     * @param  list<list<scalar|null>>  $rows  Header row first.
     */
    protected function downloadSheet(string $sheet, array $rows, string $name): BinaryFileResponse
    {
        $path = (new XlsxWriter)->addSheet($sheet, $rows)->build();

        return response()->download($path, $name.'-'.$this->from.($this->from === $this->to ? '' : '_'.$this->to).'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
