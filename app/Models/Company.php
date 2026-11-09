<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'notes', 'order_days_ahead', 'next_day_enabled', 'next_day_from', 'is_active'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'next_day_enabled' => 'boolean',
            'order_days_ahead' => 'integer',
        ];
    }

    /**
     * The first date an order can be placed for. After the company's evening time the day counts as over,
     * so orders placed then are for tomorrow.
     */
    public function earliestOrderDate(CarbonInterface $moment): CarbonImmutable
    {
        $day = CarbonImmutable::instance($moment)->startOfDay();

        if ($this->next_day_enabled && $moment->format('H:i') >= $this->next_day_from) {
            return $day->addDay();
        }

        return $day;
    }

    /**
     * The last date an order can be placed for.
     */
    public function latestOrderDate(CarbonInterface $moment): CarbonImmutable
    {
        return $this->earliestOrderDate($moment)->addDays(max(0, $this->order_days_ahead));
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Items this company is allowed to order, with an optional price on the pivot.
     *
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('price')->withTimestamps();
    }
}
