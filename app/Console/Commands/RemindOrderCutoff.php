<?php

namespace App\Console\Commands;

use App\Models\LocationCutoff;
use App\Models\Order;
use App\Services\Telegram\OrderNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('orders:remind-cutoff')]
#[Description('Remind default employees whose location has not ordered shortly before the cutoff')]
class RemindOrderCutoff extends Command
{
    public function handle(OrderNotifier $notifier): int
    {
        $lead = (int) config('crm.reminder_minutes');

        if ($lead <= 0) {
            return self::SUCCESS;
        }

        $now = now();
        $time = $now->format('H:i:s');
        $limit = $now->copy()->addMinutes($lead)->format('H:i:s');
        $sent = 0;

        // A rule's deadline falls today for its own day, or this morning for yesterday's day when it runs past midnight.
        foreach ([[$now->copy()->startOfDay(), false], [$now->copy()->startOfDay()->subDay(), true]] as [$orderDate, $endsNextDay]) {
            $rules = LocationCutoff::query()
                ->where('weekday', $orderDate->dayOfWeekIso)
                ->where('is_open', true)
                ->where('ends_next_day', $endsNextDay)
                ->whereNotNull('cutoff_time')
                ->where('cutoff_time', '>', $time)
                ->where('cutoff_time', '<=', $limit)
                ->whereHas('location', fn ($query) => $query->where('is_active', true))
                ->with('location.defaultEmployee')
                ->get();

            foreach ($rules as $rule) {
                $location = $rule->location;

                $ordered = Order::query()
                    ->where('location_id', $location->id)
                    ->whereDate('order_date', $orderDate->toDateString())
                    ->where('status', '!=', 'draft')
                    ->exists();

                $firstTime = Cache::add('cutoff-reminder.'.$location->id.'.'.$orderDate->toDateString(), true, now()->addDays(2));

                if ($ordered || ! $firstTime) {
                    continue;
                }

                $notifier->cutoffReminder($location, (string) $rule->cutoffTimeLabel());
                $sent++;
            }
        }

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
