<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations over submitted (non-draft) orders for a date range.
 */
class OrderReport
{
    public function __construct(
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
        private readonly ?int $companyId = null,
    ) {}

    /**
     * One row per order line, ordered by date, company, location and item.
     *
     * @return Collection<int, object{order_id: int, order_date: string, status: string, company_id: int, company: string, location_id: int, location: string, code: string, name_ru: string, name_uz: string, unit: string, quantity: int, price: string|null}>
     */
    public function lines(): Collection
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('locations as l', 'l.id', '=', 'o.location_id')
            ->join('companies as c', 'c.id', '=', 'l.company_id')
            ->join('items as i', 'i.id', '=', 'oi.item_id')
            ->where('o.status', '!=', 'draft')
            ->whereDate('o.order_date', '>=', $this->from->toDateString())
            ->whereDate('o.order_date', '<=', $this->to->toDateString())
            ->when($this->companyId, fn ($query, $id) => $query->where('c.id', $id))
            ->orderBy('o.order_date')->orderBy('c.name')->orderBy('l.name')->orderBy('i.name_ru')
            ->get([
                'o.id as order_id', 'o.order_date', 'o.status',
                'c.id as company_id', 'c.name as company',
                'l.id as location_id', 'l.name as location',
                'i.code', 'i.name_ru', 'i.name_uz', 'i.unit',
                'oi.quantity', 'oi.price',
            ]);
    }

    /**
     * Total quantity to produce per item.
     *
     * @return Collection<int, array{code: string, name_ru: string, name_uz: string, unit: string, quantity: int, locations: int}>
     */
    public function production(): Collection
    {
        return $this->lines()
            ->groupBy('code')
            ->map(fn (Collection $rows) => [
                'code' => $rows->first()->code,
                'name_ru' => $rows->first()->name_ru,
                'name_uz' => $rows->first()->name_uz,
                'unit' => $rows->first()->unit,
                'quantity' => (int) $rows->sum('quantity'),
                'locations' => $rows->pluck('location_id')->unique()->count(),
            ])
            ->sortBy('name_ru', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
