<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'token_hash', 'last_used_at', 'revoked_at'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Create a token and return it with its plain text value (shown to the admin only once).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name): array
    {
        $plain = 'thenon_'.Str::random(40);

        return [self::query()->create(['name' => $name, 'token_hash' => self::hash($plain)]), $plain];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
