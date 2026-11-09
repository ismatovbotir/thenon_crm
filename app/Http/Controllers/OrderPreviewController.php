<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;

class OrderPreviewController extends Controller
{
    /**
     * Read-only order page opened by its unguessable UUID link.
     */
    public function __invoke(Order $order): View
    {
        $order->load('location.company', 'employee', 'lines.item');

        return view('order-preview', ['order' => $order]);
    }
}
