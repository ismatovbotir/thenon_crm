<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OneC\OneCClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SyncOrderToOneC implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OneCClient $oneC): void
    {
        $order = Order::query()->with('location.company', 'lines.item')->find($this->orderId);

        if (! $order || $order->synced_1c_at !== null) {
            return;
        }

        if (! $oneC->send($order)) {
            throw new RuntimeException("1C did not accept order {$order->id}.");
        }

        $order->newQuery()->whereKey($order->id)->toBase()->update(['synced_1c_at' => now()]);
    }
}
