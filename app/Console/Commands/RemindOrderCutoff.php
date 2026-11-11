<?php

namespace App\Console\Commands;

use App\Models\LocationCutoff;
use App\Models\Order;
use App\Services\Telegram\OrderNotifier;
use Carbon\CarbonInterface;
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
        $now = now();
        $time = $now->format('H:i:s');
        $limit = $now->copy()->addMinutes($lead)->format('H:i:s');
        $sent = 0;

        $sent += $this->remindAtScheduledTime($notifier, $now);

        // A rule's deadline falls today for its own day, or this morning for yesterday's day when it runs past midnight.
        foreach ($lead > 0 ? [[$now->copy()->startOfDay(), false], [$now->copy()->startOfDay()->subDay(), true]] : [] as [$orderDate, $endsNextDay]) {
            $rules = LocationCutoff::query()
                ->where('weekday', $orderDate->dayOfWeekIso)
                ->where('is_open', true)
                ->where('ends_next_day', $endsNextDay)
                ->whereNotNull('cutoff_time')
                ->where('cutoff_time', '>', $time)
                ->where('cutoff_time', '<=', $limit)
                ->whereHas('location', fn ($query) => $query->where('is_active', true))
                ->with('location.defaultEmployee', 'location.employees')
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

    /**
     * Reminders set by the operator: at the rule's own time of day, on the days it applies to, when the
     * location still has no order for that date. Sent within 15 minutes after the time, once per day.
     */
    private function remindAtScheduledTime(OrderNotifier $notifier, CarbonInterface $now): int
    {
        $sent = 0;
        $today = $now->copy()->startOfDay();

        $rules = LocationCutoff::query()
            ->where('weekday', $today->dayOfWeekIso)
            ->where('is_open', true)
            ->whereNotNull('remind_time')
            ->where('remind_time', '<=', $now->format('H:i:s'))
            ->where('remind_time', '>', $now->copy()->subMinutes(15)->format('H:i:s'))
            ->whereHas('location', fn ($query) => $query->where('is_active', true))
            ->with('location.defaultEmployee', 'location.employees')
            ->get();

        foreach ($rules as $rule) {
            $location = $rule->location;
            $deadline = $rule->deadlineFor($today);

            $ordered = Order::query()
                ->where('location_id', $location->id)
                ->whereDate('order_date', $today->toDateString())
                ->where('status', '!=', 'draft')
                ->exists();

            $firstTime = Cache::add('time-reminder.'.$location->id.'.'.$today->toDateString(), true, now()->addDays(2));

            if ($ordered || ! $firstTime || ($deadline !== null && $now->gte($deadline))) {
                continue;
            }

            $notifier->cutoffReminder($location, $rule->cutoffTimeLabel());
            $sent++;
        }

        return $sent;
    }
}
