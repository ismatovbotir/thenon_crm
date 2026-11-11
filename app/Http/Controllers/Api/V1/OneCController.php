<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ItemUnit;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Item;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Endpoints 1C calls on its own schedule: it sends the item list, fetches the orders an operator has
 * confirmed, and answers each one with its document number and any edits. 1C always wins on conflicts.
 */
class OneCController extends Controller
{
    /**
     * Create or update items by code. With full_sync, active items that are missing from the list are switched off
     * (never deleted: old orders still refer to them).
     */
    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_sync' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.code' => ['required', 'string', 'max:50', 'distinct'],
            'items.*.name_ru' => ['nullable', 'string', 'max:255', 'required_without:items.*.name_uz'],
            'items.*.name_uz' => ['nullable', 'string', 'max:255', 'required_without:items.*.name_ru'],
            'items.*.unit' => ['nullable', Rule::enum(ItemUnit::class)],
            'items.*.step' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.category' => ['nullable', 'string', 'max:255'],
            'items.*.is_active' => ['nullable', 'boolean'],
        ]);

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($data, &$created, &$updated): void {
            foreach ($data['items'] as $row) {
                $item = Item::query()->firstOrNew(['code' => $row['code']]);
                $isNew = ! $item->exists;

                $item->fill(array_filter([
                    'name_ru' => $row['name_ru'] ?? ($isNew ? ($row['name_uz'] ?? null) : null),
                    'name_uz' => $row['name_uz'] ?? ($isNew ? ($row['name_ru'] ?? null) : null),
                    'unit' => $row['unit'] ?? null,
                    'step' => $row['step'] ?? null,
                    'category' => $row['category'] ?? null,
                ], fn ($value) => $value !== null));

                if (array_key_exists('is_active', $row) && $row['is_active'] !== null) {
                    $item->is_active = (bool) $row['is_active'];
                }

                $item->save();
                $isNew ? $created++ : $updated++;
            }
        });

        $deactivated = 0;

        if ($data['full_sync'] ?? false) {
            $deactivated = Item::query()->where('is_active', true)
                ->whereNotIn('code', collect($data['items'])->pluck('code'))
                ->get()->each(fn (Item $item) => $item->update(['is_active' => false]))->count();
        }

        Log::info('1C items import', compact('created', 'updated', 'deactivated'));

        return response()->json(['data' => compact('created', 'updated', 'deactivated')]);
    }

    /**
     * 1C's answer for one order, identified by our uuid: its document number, and optionally the full list of lines
     * as 1C holds them (also used later when someone edits the document in 1C). Only lines can change, never the
     * status. Repeating the same call changes nothing more.
     */
    public function order(Request $request, Order $order): JsonResponse|OrderResource
    {
        if ($order->status === OrderStatus::Draft) {
            return response()->json(['message' => 'The order is still a draft.'], 422);
        }

        $data = $request->validate([
            'one_c_number' => ['required', 'string', 'max:50'],
            'lines' => ['nullable', 'array', 'max:500'],
            'lines.*.item_code' => ['required', 'string', 'distinct', Rule::exists('items', 'code')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.is_active' => ['nullable', 'boolean'],
            'lines.*.comment' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($order, $data): void {
            if (isset($data['lines'])) {
                $this->replaceLines($order, $data['lines']);
            }

            $order->update(['one_c_number' => $data['one_c_number'], 'synced_1c_at' => $order->synced_1c_at ?? now()]);
        });

        Log::info('1C order update', ['order_id' => $order->id, 'one_c_number' => $data['one_c_number'], 'lines' => isset($data['lines'])]);

        return new OrderResource($order->fresh(['location.company', 'lines.item']));
    }

    /**
     * 1C is the source of truth: its lines are applied as sent, and any line it no longer lists is switched off.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Order $order, array $lines): void
    {
        $items = Item::query()->whereIn('code', collect($lines)->pluck('item_code'))->pluck('id', 'code');
        $kept = [];

        foreach ($lines as $line) {
            $itemId = $items[$line['item_code']];
            $kept[] = $itemId;

            $values = [
                'quantity' => $line['quantity'],
                'is_active' => (bool) ($line['is_active'] ?? true),
            ];

            if (array_key_exists('price', $line)) {
                $values['price'] = $line['price'];
            }

            if (array_key_exists('comment', $line)) {
                $values['comment'] = $line['comment'];
            }

            $order->lines()->updateOrCreate(['item_id' => $itemId], $values);
        }

        $order->lines()->whereNotIn('item_id', $kept)->update(['is_active' => false]);
    }
}
