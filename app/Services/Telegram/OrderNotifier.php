<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\OrderStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Order;

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
