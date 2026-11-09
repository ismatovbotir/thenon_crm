<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;

class OrderPrintController extends Controller
{
    /**
     * A4 delivery form for the driver: items, signatures and a QR code with the location's Yandex Maps link.
     */
    public function __invoke(Order $order): View
    {
        App::setLocale('ru');

        $order->load('location.company', 'location.defaultEmployee', 'employee', 'lines.item');

        $mapUrl = $order->location->yandex_url;

        return view('order-print', [
            'order' => $order,
            'lines' => $order->lines->sortBy(fn ($line) => $line->item->code)->values(),
            'qr' => $mapUrl ? QrCode::svg($mapUrl) : null,
            'mapUrl' => $mapUrl,
            'contact' => $order->location->defaultEmployee ?? $order->employee,
        ]);
    }
}
