<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Reports\OrderReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Orders with their lines. Drafts are excluded unless asked for with status=draft.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'company_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'],
            'pending_1c' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $orders = Order::query()
            ->with(['location.company', 'lines.item'])
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('order_date', $date))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('order_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('order_date', '<=', $to))
            ->when(
                $filters['status'] ?? null,
                fn ($query, $status) => $query->where('status', $status),
                fn ($query) => $query->where('status', '!=', OrderStatus::Draft->value),
            )
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($filters['company_id'] ?? null, fn ($query, $id) => $query->whereHas('location', fn ($q) => $q->where('company_id', $id)))
            ->when($filters['pending_1c'] ?? false, fn ($query) => $query->whereNull('synced_1c_at')->where('status', OrderStatus::Accepted->value))
            ->when($filters['updated_since'] ?? null, fn ($query, $since) => $query->where('updated_at', '>=', Carbon::parse($since)))
            ->orderBy('order_date')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 100));

        return OrderResource::collection($orders);
    }

    /**
     * Total quantity per item for a date range (what the factory has to produce).
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($filters['from'] ?? $filters['date'] ?? today());
        $to = Carbon::parse($filters['to'] ?? $filters['date'] ?? $from);

        $items = (new OrderReport($from, $to, isset($filters['company_id']) ? (int) $filters['company_id'] : null))
            ->production()
            ->map(fn (array $row) => [
                'item_code' => $row['code'],
                'name_ru' => $row['name_ru'],
                'name_uz' => $row['name_uz'],
                'unit' => $row['unit'],
                'quantity' => $row['quantity'],
                'locations' => $row['locations'],
            ])->all();

        return response()->json(['data' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'items' => $items]]);
    }
}
