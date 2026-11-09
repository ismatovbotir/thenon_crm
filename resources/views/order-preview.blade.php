<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>THE NON — {{ $order->location->name }} · {{ $order->order_date->format('d.m.Y') }}</title>
    <link rel="icon" href="{{ asset('images/brand/logo.jpg') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-cream-50 font-sans text-navy-900 antialiased">
    <header class="bg-navy-900 px-4 py-4 text-white shadow-md">
        <div class="mx-auto flex max-w-2xl items-center gap-3">
            <img src="{{ asset('images/brand/logo.jpg') }}" alt="THE NON" class="size-10 shrink-0 rounded-full ring-2 ring-gold-400/40">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ __('order.for_date', ['date' => $order->order_date->format('d.m.Y')]) }}</p>
                <p class="truncate text-xs text-navy-100">{{ $order->location->company->name }} · {{ $order->location->name }}</p>
            </div>
            <span class="crm-badge bg-white text-navy-800">{{ $order->status->label() }}</span>
        </div>
    </header>

    <main class="mx-auto max-w-2xl space-y-4 px-4 py-4">
        @if ($order->location->address || $order->location->yandex_url)
            <div class="crm-card space-y-2 p-4 text-sm">
                @if ($order->location->address)
                    <p class="text-navy-800">{{ $order->location->address }}</p>
                @endif
                @if ($order->location->yandex_url)
                    <a href="{{ $order->location->yandex_url }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-gold-600 hover:underline">{{ __('order.open_map') }}</a>
                @endif
            </div>
        @endif

        @if ($order->employee)
            <p class="text-sm text-navy-600">{{ $order->employee->name }}</p>
        @endif

        <div class="crm-card divide-y divide-navy-100">
            @forelse ($order->lines as $line)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <p class="min-w-0 truncate text-sm font-medium text-navy-800">{{ $line->item->name }}</p>
                    <p class="shrink-0 text-sm font-semibold">{{ $line->quantity }} <span class="font-normal text-navy-600">{{ $line->item->unit->label() }}</span></p>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-navy-600">{{ __('order.empty_cart') }}</p>
            @endforelse
        </div>

        <p class="text-center text-xs text-navy-600">{{ __('order.positions', ['count' => $order->lines->count()]) }} · {{ $order->lines->sum('quantity') }}</p>
    </main>
</body>
</html>
