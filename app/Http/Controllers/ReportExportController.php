<?php

namespace App\Http\Controllers;

use App\Enums\ItemUnit;
use App\Enums\OrderStatus;
use App\Services\Reports\OrderReport;
use App\Services\Reports\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $report = new OrderReport(
            Carbon::parse($data['from']),
            Carbon::parse($data['to']),
            isset($data['company']) ? (int) $data['company'] : null,
        );

        $production = [[
            __('crm.reports.code'), __('crm.reports.name_ru'), __('crm.reports.name_uz'),
            __('crm.items.unit'), __('crm.reports.quantity'), __('crm.reports.locations'),
        ]];

        foreach ($report->production() as $row) {
            $production[] = [
                $row['code'], $row['name_ru'], $row['name_uz'],
                ItemUnit::from($row['unit'])->label(), $row['quantity'], $row['locations'],
            ];
        }

        $details = [[
            __('crm.orders.date'), __('crm.nav.companies'), __('crm.orders.location'),
            __('crm.reports.code'), __('crm.reports.name_ru'), __('crm.items.unit'),
            __('crm.reports.quantity'), __('crm.items.price'), __('crm.common.status'), '№',
        ]];

        foreach ($report->lines() as $line) {
            $details[] = [
                Carbon::parse($line->order_date)->format('d.m.Y'), $line->company, $line->location,
                $line->code, $line->name_ru, ItemUnit::from($line->unit)->label(),
                (int) $line->quantity, $line->price !== null ? (float) $line->price : null,
                OrderStatus::from($line->status)->label(), (int) $line->order_id,
            ];
        }

        $path = (new XlsxWriter)
            ->addSheet(__('crm.reports.sheet_production'), $production)
            ->addSheet(__('crm.reports.sheet_details'), $details)
            ->build();

        $name = 'the-non-orders-'.$data['from'].($data['from'] === $data['to'] ? '' : '_'.$data['to']).'.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
