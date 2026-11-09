<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Caches each company's orderable product list (with its prices) so opening the Telegram web app
 * does not query the catalog every time. Entries live for six hours and are dropped as soon as
 * any product or any company's product assignment changes.
 */
class CatalogCache
{
    private const TTL_HOURS = 6;

    private const VERSION_KEY = 'catalog.version';

    /**
     * Active items the company may order, in the current locale's order.
     *
     * @return Collection<int, Item>
     */
    public function forCompany(Company $company): Collection
    {
        $locale = app()->getLocale();
        $key = sprintf('catalog.%s.%d.%s', $this->version(), $company->id, $locale);

        return Cache::remember($key, now()->addHours(self::TTL_HOURS), fn (): Collection => $company->items()
            ->where('items.is_active', true)
            ->orderBy('name_'.$locale)
            ->get());
    }

    /**
     * Invalidate every cached catalog; the next request rebuilds it.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    private function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '0');
    }
}
