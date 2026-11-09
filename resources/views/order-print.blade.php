<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('print.title', ['id' => $order->id, 'date' => $order->order_date->format('d.m.Y')]) }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e7eb; color: #000; font: 13px/1.35 Arial, Helvetica, sans-serif; }
        .toolbar { display: flex; gap: 8px; justify-content: center; padding: 12px; }
        .toolbar button, .toolbar a { padding: 8px 18px; border: 1px solid #1f2937; border-radius: 6px; background: #1f2937; color: #fff; font: inherit; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f2937; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 16px; padding: 12mm; background: #fff; }
        h1 { margin: 0 0 10px; font-size: 20px; }
        .head { display: flex; justify-content: space-between; gap: 16px; }
        .meta { flex: 1; }
        .meta p { margin: 0 0 4px; }
        .meta b { font-weight: 700; }
        .qr { width: 52mm; flex-shrink: 0; text-align: center; }
        .qr svg { display: block; width: 44mm; height: 44mm; margin: 0 auto 4px; }
        .qr p { margin: 0; font-size: 11px; }
        .qr .addr { font-weight: 700; }
        table { width: 100%; margin-top: 14px; border-collapse: collapse; }
        th, td { padding: 5px 6px; border: 1px solid #000; }
        th { background: #f3f4f6; text-align: center; font-weight: 700; }
        td.num { text-align: right; white-space: nowrap; }
        td.c { text-align: center; }
        .totals { display: flex; justify-content: flex-end; gap: 36px; margin-top: 10px; padding-top: 8px; border-top: 2px solid #000; font-weight: 700; }
        .count { margin-top: 10px; padding-top: 8px; border-top: 1px solid #000; text-decoration: underline; }
        .sign { display: flex; justify-content: space-between; gap: 24px; margin-top: 36px; }
        .sign div { flex: 1; }
        .sign span { display: inline-block; width: 70%; border-bottom: 1px solid #000; }
        .note { margin-top: 18px; font-size: 11px; color: #444; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">{{ __('print.print') }}</button>
        <a href="{{ route('crm.orders.show', $order) }}">{{ __('print.back') }}</a>
    </div>

    <div class="sheet">
        <h1>{{ __('print.title', ['id' => $order->id, 'date' => $order->order_date->format('d.m.Y')]) }}</h1>

        <div class="head">
            <div class="meta">
                <p>{{ __('print.firm') }}: <b>THE NON</b></p>
                <p>{{ __('print.buyer') }}: <b>{{ $order->location->company->name }} — {{ $order->location->name }}</b></p>
                @if ($order->location->address)
                    <p>{{ __('print.address') }}: <b>{{ $order->location->address }}</b></p>
                @endif
                @if ($contact)
                    <p>{{ __('print.contact') }}: <b>{{ $contact->name }}@if ($contact->phone), {{ $contact->phone }}@endif</b></p>
                @endif
                <p>{{ __('print.delivery_date') }}: <b>{{ $order->order_date->format('d.m.Y') }}</b></p>
                <p style="margin-top: 14px">{{ __('print.courier') }}: ______________________________</p>
            </div>

            @if ($qr)
                <div class="qr">
                    {!! $qr !!}
                    <p class="addr">{{ __('print.route') }}</p>
                    <p>{{ __('print.scan') }}</p>
                </div>
            @endif
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 28px">№</th>
                    <th style="width: 60px">{{ __('print.code') }}</th>
                    <th>{{ __('print.name') }}</th>
                    <th style="width: 70px">{{ __('print.qty') }}</th>
                    <th style="width: 44px">{{ __('print.unit') }}</th>
                    <th style="width: 80px">{{ __('print.price') }}</th>
                    <th style="width: 90px">{{ __('print.sum') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td class="c">{{ $loop->iteration }}</td>
                        <td class="c">{{ $line->item->code }}</td>
                        <td>{{ $line->item->name_ru }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                        <td class="c">{{ $line->item->unit->label() }}</td>
                        <td class="num">{{ $line->price !== null ? number_format((float) $line->price, 2, ',', ' ') : '' }}</td>
                        <td class="num">{{ $line->total() !== null ? number_format($line->total(), 2, ',', ' ') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <span>{{ __('print.total') }}:</span>
            <span>{{ $lines->sum('quantity') }}</span>
            @if ($order->total() > 0)
                <span>{{ number_format($order->total(), 2, ',', ' ') }} UZS</span>
            @endif
        </div>
        <p class="count">{{ __('print.positions', ['count' => $lines->count()]) }}</p>

        <div class="sign">
            <div>{{ __('print.released') }} <span></span></div>
            <div>{{ __('print.received') }} <span></span></div>
        </div>

        @if ($mapUrl)
            <p class="note">{{ $mapUrl }}</p>
        @endif
    </div>
</body>
</html>
