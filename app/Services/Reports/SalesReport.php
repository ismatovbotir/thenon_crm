<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Money and volume aggregations over submitted (non-draft) orders for a date range.
 * Amounts only include order lines that have a price.
 */
class SalesReport
{
    /** Longest period the "missing orders" report walks day by day. */
    public const MAX_MISSING_DAYS = 62;

    public function __construct(
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
        private readonly ?int $companyId = null,
    ) {}

    /**
     * @return array{orders: int, positions: int, quantity: int, amount: float, unpriced: int}
     */
    public function totals(): array
    {
        $row = $this->base()->selectRaw($this->metrics())->first();

        return $this->metricRow($row);
    }

    /**
     * @return Collection<int, array{id: int, name: string, orders: int, positions: int, quantity: int, amount: float, unpriced: int}>
     */
    public function byCompany(): Collection
    {
        return $this->base()
            ->selectRaw('c.id as id, c.name as name, '.$this->metrics())
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('amount')->orderByDesc('quantity')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name] + $this->metricRow($row));
    }

    /**
     * @return Collection<int, array{id: int, name: string, company: string, orders: int, positions: int, quantity: int, amount: float, unpriced: int}>
     */
    public function byLocation(): Collection
    {
        return $this->base()
            ->selectRaw('l.id as id, l.name as name, c.name as company, '.$this->metrics())
            ->groupBy('l.id', 'l.name', 'c.name')
            ->orderByDesc('amount')->orderByDesc('quantity')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'company' => $row->company] + $this->metricRow($row));
    }

    /**
     * @return Collection<int, array{code: string, name_ru: string, name_uz: string, unit: string, locations: int, orders: int, positions: int, quantity: int, amount: float, unpriced: int}>
     */
    public function byItem(): Collection
    {
        return $this->base()
            ->join('items as i', 'i.id', '=', 'oi.item_id')
            ->selectRaw('i.code as code, i.name_ru as name_ru, i.name_uz as name_uz, i.unit as unit, count(distinct o.location_id) as location_count, '.$this->metrics())
            ->groupBy('i.id', 'i.code', 'i.name_ru', 'i.name_uz', 'i.unit')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name_ru' => $row->name_ru,
                'name_uz' => $row->name_uz,
                'unit' => $row->unit,
                'locations' => (int) $row->location_count,
            ] + $this->metricRow($row));
    }

    /**
     * @return Collection<int, array{date: string, orders: int, positions: int, quantity: int, amount: float, unpriced: int}>
     */
    public function byDay(): Collection
    {
        return $this->base()
            ->selectRaw('o.order_date as day, '.$this->metrics())
            ->groupBy('o.order_date')
            ->orderBy('o.order_date')
            ->get()
            ->map(fn ($row) => ['date' => substr((string) $row->day, 0, 10)] + $this->metricRow($row));
    }

    /**
     * Locations that did not place a submitted order on days they could order (closed weekdays are skipped).
     *
     * @return Collection<int, array{location_id: int, company: string, location: string, expected: int, ordered: int, missed: int, dates: list<string>}>
     */
    public function missing(): Collection
    {
        $from = CarbonImmutable::instance($this->from)->startOfDay();
        $to = CarbonImmutable::instance($this->to)->startOfDay();

        if ($from->diffInDays($to) >= self::MAX_MISSING_DAYS) {
            return collect();
        }

        $locations = Location::query()
            ->where('is_active', true)
            ->when($this->companyId, fn ($query, $id) => $query->where('company_id', $id))
            ->with(['company:id,name', 'cutoffs'])
            ->get();

        $placed = DB::table('orders')
            ->where('status', '!=', OrderStatus::Draft->value)
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString())
            ->whereIn('location_id', $locations->modelKeys())
            ->get(['location_id', 'order_date'])
            ->mapWithKeys(fn ($row) => [$row->location_id.'|'.substr((string) $row->order_date, 0, 10) => true]);

        $rows = [];

        foreach ($locations as $location) {
            $expected = 0;
            $missed = [];

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $rule = $location->cutoffFor($day);

                if ($rule && ! $rule->is_open) {
                    continue;
                }

                $expected++;

                if (! $placed->has($location->id.'|'.$day->toDateString())) {
                    $missed[] = $day->toDateString();
                }
            }

            if ($missed !== []) {
                $rows[] = [
                    'location_id' => $location->id,
                    'company' => $location->company->name,
                    'location' => $location->name,
                    'expected' => $expected,
                    'ordered' => $expected - count($missed),
                    'missed' => count($missed),
                    'dates' => $missed,
                ];
            }
        }

        return collect($rows)->sortByDesc('missed')->values();
    }

    private function base(): Builder
    {
        return DB::table('orders as o')
            ->join('locations as l', 'l.id', '=', 'o.location_id')
            ->join('companies as c', 'c.id', '=', 'l.company_id')
            ->leftJoin('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->where('o.status', '!=', OrderStatus::Draft->value)
            ->whereDate('o.order_date', '>=', $this->from->toDateString())
            ->whereDate('o.order_date', '<=', $this->to->toDateString())
            ->when($this->companyId, fn ($query, $id) => $query->where('c.id', $id));
    }

    private function metrics(): string
    {
        return 'count(distinct o.id) as order_count, count(oi.id) as position_count, coalesce(sum(oi.quantity), 0) as quantity,'
            .' coalesce(sum(case when oi.price is not null then oi.price * oi.quantity else 0 end), 0) as amount,'
            .' coalesce(sum(case when oi.id is not null and oi.price is null then 1 else 0 end), 0) as unpriced_lines';
    }

    /**
     * @return array{orders: int, positions: int, quantity: int, amount: float, unpriced: int}
     */
    private function metricRow(?object $row): array
    {
        return [
            'orders' => (int) ($row->order_count ?? 0),
            'positions' => (int) ($row->position_count ?? 0),
            'quantity' => (int) ($row->quantity ?? 0),
            'amount' => (float) ($row->amount ?? 0),
            'unpriced' => (int) ($row->unpriced_lines ?? 0),
        ];
    }
}
