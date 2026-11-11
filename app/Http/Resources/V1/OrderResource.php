<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'one_c_number' => $this->one_c_number,
            'preview_url' => route('orders.preview', $this->resource),
            'date' => $this->order_date->toDateString(),
            'status' => $this->status->value,
            'company' => ['id' => $this->location->company->id, 'name' => $this->location->company->name],
            'location' => ['id' => $this->location->id, 'name' => $this->location->name],
            'completed_at' => $this->completed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'admin_notified_at' => $this->admin_notified_at?->toIso8601String(),
            'synced_1c_at' => $this->synced_1c_at?->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'lines' => $this->lines->map(fn (OrderItem $line) => [
                'item_code' => $line->item->code,
                'name_ru' => $line->item->name_ru,
                'name_uz' => $line->item->name_uz,
                'unit' => $line->item->unit->value,
                'quantity' => $line->quantity,
                'is_active' => $line->is_active,
                'comment' => $line->comment,
                'price' => $line->price !== null ? (float) $line->price : null,
                'total' => $line->total(),
            ])->all(),
        ];
    }
}
