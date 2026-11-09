<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Jobs\SyncOrderToOneC;
use App\Services\Telegram\OrderNotifier;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['location_id', 'employee_id', 'order_date', 'status', 'completed_at', 'delivered_at', 'admin_notified_at', 'synced_1c_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The public preview link is keyed by the uuid column; the numeric id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::updated(function (Order $order): void {
            if ($order->wasChanged('status')) {
                app(OrderNotifier::class)->statusChanged($order);

                // 1C only receives orders an operator has confirmed (Accepted, or any later status).
                $confirmed = in_array($order->status, [OrderStatus::Accepted, OrderStatus::Done, OrderStatus::Sent, OrderStatus::Delivered], true);

                if ($confirmed && $order->synced_1c_at === null) {
                    SyncOrderToOneC::dispatch($order->id);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'status' => OrderStatus::class,
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'admin_notified_at' => 'datetime',
            'synced_1c_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Sum of the priced lines (price is recorded on each line when it is added to the order).
     */
    public function total(): float
    {
        return (float) $this->lines->sum(fn (OrderItem $line) => $line->total() ?? 0);
    }

    /**
     * Whether some line has no price, so the total is only partial.
     */
    public function hasUnpricedLines(): bool
    {
        return $this->lines->contains(fn (OrderItem $line) => $line->price === null);
    }
}
