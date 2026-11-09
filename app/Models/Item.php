<?php

namespace App\Models;

use App\Enums\ItemUnit;
use App\Services\CatalogCache;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name_uz', 'name_ru', 'image_path', 'unit', 'step', 'category', 'is_active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => app(CatalogCache::class)->flush());
        static::deleted(fn () => app(CatalogCache::class)->flush());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => ItemUnit::class,
            'step' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Name in the current locale, falling back to the other language.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            $preferred = app()->getLocale() === 'uz' ? $this->name_uz : $this->name_ru;

            return $preferred ?: ($this->name_ru ?: $this->name_uz);
        });
    }

    /**
     * Served by the app itself on the host the page is opened on (not through public/storage), versioned for caching.
     *
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path ? route('media.item', ['item' => $this->id, 'v' => $this->updated_at?->timestamp]) : null);
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->where('code', 'like', $like)
            ->orWhere('name_uz', 'like', $like)
            ->orWhere('name_ru', 'like', $like));
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('price')->withTimestamps();
    }
}
