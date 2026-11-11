<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class OrderPrintController extends Controller
{
    private const MAX_BULK = 100;

    /**
     * A4 delivery form for the driver: items, signatures and a QR code with the location's Yandex Maps link.
     */
    public function __invoke(Request $request, Order $order): View
    {
        return $this->render(new Collection([$order]), $this->perPage($request, 1));
    }

    /**
     * Forms per A4 sheet: 1 or 2 (?per=). One order printed 2-up gives two copies on the same sheet.
     */
    private function perPage(Request $request, int $default): int
    {
        return $request->query('per') === '2' ? 2 : ($request->query('per') === '1' ? 1 : $default);
    }

    /**
     * Several orders, one A4 sheet each (?ids=1,2,3), at most 100 at a time.
     */
    public function bulk(Request $request): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(self::MAX_BULK)->values();

        abort_if($ids->isEmpty(), 404);

        $orders = Order::query()->whereIn('id', $ids)->orderBy('order_date')->orderBy('id')->get();

        abort_if($orders->isEmpty(), 404);

        return $this->render($orders, $this->perPage($request, 2));
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function render(Collection $orders, int $per): View
    {
        App::setLocale('ru');

        $orders->load('location.company', 'location.defaultEmployee', 'employee', 'lines.item');

        $sheets = $orders->map(function (Order $order): array {
            $mapUrl = $order->location->yandex_url;
            $lines = $order->lines->where('is_active', true)->sortBy(fn ($line) => $line->item->code)->values();

            return [
                'order' => $order,
                'lines' => $lines,
                'total' => (float) $lines->sum(fn ($line) => $line->total() ?? 0),
                'qr' => $mapUrl ? QrCode::svg($mapUrl) : null,
                'mapUrl' => $mapUrl,
                'contact' => $order->location->defaultEmployee ?? $order->employee,
            ];
        });

        if ($per === 2 && $sheets->count() === 1) {
            $sheets = $sheets->concat($sheets);
        }

        return view('order-print', ['sheets' => $sheets->values(), 'per' => $per]);
    }
}
