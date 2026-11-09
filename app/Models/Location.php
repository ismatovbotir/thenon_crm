<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'address', 'yandex_url', 'default_employee_id', 'is_active'])]
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
        return $this->isOrderingClosedFor($moment, $moment);
    }

    /**
     * Whether an order dated $orderDate can no longer be placed or changed at $moment.
     * The rule is the one of the order date's weekday. A closed weekday blocks the day itself;
     * a cutoff time ends the day at that time on the order date, or on the next calendar day
     * when the rule is set to run past midnight.
     */
    public function isOrderingClosedFor(CarbonInterface $orderDate, CarbonInterface $moment): bool
    {
        $rule = $this->cutoffFor($orderDate);

        if (! $rule) {
            return false;
        }

        if (! $rule->is_open) {
            return $orderDate->copy()->startOfDay()->lte($moment->copy()->startOfDay());
        }

        $deadline = $rule->deadlineFor($orderDate);

        return $deadline !== null && $moment->gte($deadline);
    }

    /**
     * The latest earlier date that is still open only because its day runs past midnight
     * (e.g. Monday's orders until Tuesday 06:00), looking at the day before $firstDate.
     */
    public function stillOpenEarlierDate(CarbonInterface $firstDate, CarbonInterface $moment): ?CarbonImmutable
    {
        $candidate = CarbonImmutable::instance($firstDate)->startOfDay()->subDay();
        $rule = $this->cutoffFor($candidate);

        if (! $rule || ! $rule->is_open || ! $rule->ends_next_day || $this->isOrderingClosedFor($candidate, $moment)) {
            return null;
        }

        return $candidate;
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
