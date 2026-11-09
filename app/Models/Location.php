<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'address', 'default_employee_id', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsToMany<Employee, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class)->withTimestamps();
    }

    /**
     * @return HasMany<LocationCutoff, $this>
     */
    public function cutoffs(): HasMany
    {
        return $this->hasMany(LocationCutoff::class);
    }

    /**
     * The ordering rule that applies on the given moment's weekday, if any.
     */
    public function cutoffFor(CarbonInterface $moment): ?LocationCutoff
    {
        return $this->cutoffs->firstWhere('weekday', $moment->dayOfWeekIso);
    }

    /**
     * Whether employees may no longer order at the given moment (day closed or past the cutoff time).
     */
    public function isOrderingClosed(CarbonInterface $moment): bool
    {
        $rule = $this->cutoffFor($moment);

        if (! $rule) {
            return false;
        }

        if (! $rule->is_open) {
            return true;
        }

        $time = $rule->cutoffTimeLabel();

        return $time !== null && $moment->format('H:i') >= $time;
    }

    /**
     * The employee who receives this location's order messages.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function defaultEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'default_employee_id');
    }
}
