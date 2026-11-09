<?php

namespace App\Models;

use Database\Factories\TvScreenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A wall-mounted order monitor opened by its secret URL, without logging in.
 */
#[Fillable(['name', 'token', 'company_id', 'is_active', 'last_seen_at'])]
class TvScreen extends Model
{
    /** @use HasFactory<TvScreenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function url(): string
    {
        return route('tv.board', $this->token);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
