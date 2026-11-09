<?php

namespace App\Models;

use Database\Factories\LocationCutoffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordering rule for one location on one ISO weekday (1 = Monday ... 7 = Sunday).
 * A missing row means no restriction that day.
 */
#[Fillable(['location_id', 'weekday', 'is_open', 'cutoff_time'])]
class LocationCutoff extends Model
{
    /** @use HasFactory<LocationCutoffFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_open' => 'boolean'];
    }

    /**
     * Cutoff as HH:MM, or null when there is no time limit.
     */
    public function cutoffTimeLabel(): ?string
    {
        return $this->cutoff_time ? substr((string) $this->cutoff_time, 0, 5) : null;
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
