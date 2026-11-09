<?php

namespace App\Services\OneC;

use App\Models\Order;

interface OneCClient
{
    /**
     * Hand a completed order over to 1C. Returns true once 1C has accepted it.
     */
    public function send(Order $order): bool;
}
