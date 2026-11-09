<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds what the wall monitor shows: who has not ordered yet (most urgent first),
 * the orders received (newest first) and the quantity of every product to prepare.
 */
class TvBoard
{
    /** Orders completed this recently are highlighted as new. */
    public const NEW_SECONDS = 120;

    /**
     * @return array{
     *     expected: int,
     *     ordered: int,
     *     missing: Collection<int, array{location: Location, date: CarbonImmutable, deadline: ?CarbonImmutable, minutes_left: ?int, has_draft: bool}>,
     *     orders: Collection<int, array{order: Order, positions: int, quantity: int, is_new: bool}>,
     *     totals: Collection<int, array{name: string, unit: string, quantity: int}>
     * }
     */
    public function build(?int $companyId, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->startOfDay();

        $locations = Location::query()
            ->where('is_active', true)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with(['company', 'cutoffs'])
            ->orderBy('name')
            ->get();

        $dates = $locations
            ->mapWithKeys(fn (Location $location) => [$location->id => $location->stillOpenEarlierDate($today, $now) ?? $today])
            ->filter(fn (CarbonImmutable $date, int $locationId) => ! $this->isClosedDay($locations->firstWhere('id', $locationId), $date));

        $orders = Order::query()
            ->with(['location.company', 'lines.item'])
            ->whereIn('location_id', $dates->keys())
            ->whereDate('order_date', '>=', $today->subDay()->toDateString())
            ->whereDate('order_date', '<=', $today->toDateString())
            ->get()
            ->filter(fn (Order $order) => $dates->get($order->location_id)?->toDateString() === $order->order_date->toDateString());

        $received = $orders->filter(fn (Order $order) => $order->status !== OrderStatus::Draft);
        $orderedIds = $received->pluck('location_id')->unique();
        $draftIds = $orders->filter(fn (Order $order) => $order->status === OrderStatus::Draft && $order->lines->isNotEmpty())->pluck('location_id');

        $missing = $locations
            ->filter(fn (Location $location) => $dates->has($location->id) && ! $orderedIds->contains($location->id))
            ->map(function (Location $location) use ($dates, $now, $draftIds) {
                $date = $dates->get($location->id);
                $deadline = $location->cutoffFor($date)?->deadlineFor($date);

                return [
                    'location' => $location,
                    'date' => $date,
                    'deadline' => $deadline,
                    'minutes_left' => $deadline ? (int) floor($now->diffInMinutes($deadline, false)) : null,
                    'has_draft' => $draftIds->contains($location->id),
                ];
            })
            ->sortBy(fn (array $row) => $row['deadline']?->timestamp ?? PHP_INT_MAX)
            ->values();

        return [
            'expected' => $dates->count(),
            'ordered' => $orderedIds->count(),
            'missing' => $missing,
            'orders' => $received
                ->sortByDesc(fn (Order $order) => ($order->completed_at ?? $order->updated_at)->timestamp)
                ->map(fn (Order $order) => [
                    'order' => $order,
                    'positions' => $order->lines->count(),
                    'quantity' => (int) $order->lines->sum('quantity'),
                    'is_new' => $order->status === OrderStatus::Completed
                        && $order->completed_at !== null
                        && $order->completed_at->gte($now->copy()->subSeconds(self::NEW_SECONDS)),
                ])
                ->values(),
            'totals' => $received->flatMap->lines
                ->groupBy('item_id')
                ->map(fn (Collection $lines) => [
                    'name' => $lines->first()->item->name,
                    'unit' => $lines->first()->item->unit->label(),
                    'quantity' => (int) $lines->sum('quantity'),
                ])
                ->sortByDesc('quantity')
                ->values(),
        ];
    }

    private function isClosedDay(?Location $location, CarbonImmutable $date): bool
    {
        $rule = $location?->cutoffFor($date);

        return $rule !== null && ! $rule->is_open;
    }
}
