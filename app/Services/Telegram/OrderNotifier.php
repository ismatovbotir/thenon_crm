<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\OrderStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Support\Facades\App;

class OrderNotifier
{
    /**
     * Tell the location's default employee that the order moved to a new status.
     */
    public function statusChanged(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Draft, OrderStatus::Delivered], true)) {
            return;
        }

        if ($order->status === OrderStatus::Completed) {
            $this->notifyAdmin($order);
        }

        $order->loadMissing('location.defaultEmployee');
        $employee = $this->recipient($order->location);

        if (! $employee) {
            return;
        }

        $language = $employee->language ?? Language::Ru;
        $text = __('telegram.notify.status', [
            'date' => $order->order_date->format('d.m.Y'),
            'location' => $order->location->name,
            'status' => __('order.status.'.$order->status->value, [], $language->value),
        ], $language->value);

        $isSent = $order->status === OrderStatus::Sent;

        if ($isSent) {
            $text .= "\n".__('telegram.notify.confirm_delivery', [], $language->value);
        }

        SendTelegramMessage::dispatch(
            (int) $employee->telegram_id,
            $text,
            $isSent ? __('telegram.open_app', [], $language->value) : null,
        );
    }

    /**
     * Send the administrator a plain-text copy of a freshly completed order.
     */
    public function notifyAdmin(Order $order): void
    {
        $adminId = config('telegram.admin_chat_id');

        if ($adminId === null || $adminId === '') {
            return;
        }

        $order->loadMissing('location.company', 'employee', 'lines.item');

        $previousLocale = App::getLocale();
        App::setLocale('ru');

        try {
            $text = $this->adminOrderText($order);
        } finally {
            App::setLocale($previousLocale);
        }

        SendTelegramMessage::dispatch((int) $adminId, mb_substr($text, 0, 4000), null, $order->id);
    }

    /**
     * The administrator's order summary, always in Russian.
     */
    private function adminOrderText(Order $order): string
    {
        $lines = $order->lines
            ->map(fn ($line) => '• '.$line->item->name_ru.' — '.$line->quantity.' '.$line->item->unit->label())
            ->implode(PHP_EOL);

        $location = $order->location;

        return __('telegram.notify.admin_order', [
            'date' => $order->order_date->format('d.m.Y'),
            'company' => $location->company->name,
            'location' => $location->name,
            'address' => $location->address ?: '—',
            'map' => $location->yandex_url ? PHP_EOL.$location->yandex_url : '',
            'employee' => $order->employee?->name ?? '—',
            'lines' => $lines,
            'positions' => $order->lines->count(),
            'link' => route('orders.preview', $order),
        ]);
    }

    /**
     * Remind the default employee that ordering closes soon and nothing has been ordered yet.
     */
    public function cutoffReminder(Location $location, string $cutoffTime): void
    {
        $location->loadMissing('defaultEmployee');
        $employee = $this->recipient($location);

        if (! $employee) {
            return;
        }

        $language = $employee->language ?? Language::Ru;

        SendTelegramMessage::dispatch(
            (int) $employee->telegram_id,
            __('telegram.notify.reminder', ['location' => $location->name, 'time' => $cutoffTime], $language->value),
            __('telegram.open_app', [], $language->value),
        );
    }

    /**
     * The location's default employee, only when they can actually receive bot messages.
     */
    private function recipient(Location $location): ?Employee
    {
        $employee = $location->defaultEmployee;

        return $employee && $employee->is_active && $employee->telegram_id !== null ? $employee : null;
    }
}
