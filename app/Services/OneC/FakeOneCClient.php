<?php

namespace App\Services\OneC;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in for the real 1C integration: accepts every order and only writes a log line.
 * Replace the binding in AppServiceProvider with the real client once the 1C protocol is agreed.
 */
class FakeOneCClient implements OneCClient
{
    public function send(Order $order): bool
    {
        Log::info('1C (fake): order accepted', ['order_id' => $order->id, 'uuid' => $order->uuid]);

        return true;
    }
}
