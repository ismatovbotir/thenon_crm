<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LocationCutoffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordering rule for one location on one ISO weekday (1 = Monday ... 7 = Sunday).
 * A missing row means no restriction that day.
 */
#[Fillable(['location_id', 'weekday', 'is_open', 'cutoff_time', 'ends_next_day', 'remind_time'])]
class LocationCutoff extends Model
{
    /** @use HasFactory<LocationCutoffFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'ends_next_day' => 'boolean'];
    }

    /**
     * Cutoff as HH:MM, or null when there is no time limit.
     */
    public function cutoffTimeLabel(): ?string
    {
        return $this->cutoff_time ? substr((string) $this->cutoff_time, 0, 5) : null;
    }

    /**
     * Time of day (HH:MM) to remind the location to order on this weekday, or null for no reminder.
     */
    public function remindTimeLabel(): ?string
    {
        return $this->remind_time ? substr((string) $this->remind_time, 0, 5) : null;
    }

    /**
     * The moment ordering closes for an order dated $orderDate: the cutoff time on that date,
     * or on the following calendar day when the rule's day runs past midnight.
     */
    public function deadlineFor(CarbonInterface $orderDate): ?CarbonImmutable
    {
        $time = $this->cutoffTimeLabel();

        if ($time === null) {
            return null;
        }

        $day = CarbonImmutable::instance($orderDate)->startOfDay();

        return ($this->ends_next_day ? $day->addDay() : $day)->setTimeFromTimeString($time);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
