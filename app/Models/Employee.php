<?php

namespace App\Models;

use App\Enums\Language;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['company_id', 'name', 'phone', 'language', 'telegram_id', 'registered_at', 'is_active'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * Normalize any phone input to the +998XXXXXXXXX format used for matching.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return '+'.$digits;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'registered_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizePhone($value));
    }

    public function isRegistered(): bool
    {
        return $this->telegram_id !== null;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }
}
