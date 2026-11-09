<?php

namespace App\Models;

use App\Enums\ItemUnit;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['code', 'name_uz', 'name_ru', 'image_path', 'unit', 'step', 'category', 'is_active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

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
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path ? Storage::disk('public')->url($this->image_path) : null);
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
