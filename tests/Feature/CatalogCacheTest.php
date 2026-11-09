<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Services\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Item $item;

    private CatalogCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->cache = app(CatalogCache::class);
        $this->company = Company::factory()->create();
        $this->item = Item::factory()->create(['name_ru' => 'Багет']);
        $this->company->items()->attach($this->item, ['price' => 1000]);
    }

    public function test_the_catalog_is_served_from_cache_after_the_first_load(): void
    {
        $this->cache->forCompany($this->company);

        DB::enableQueryLog();
        $items = $this->cache->forCompany($this->company);

        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame('Багет', $items->first()->name_ru);
        $this->assertEquals(1000, $items->first()->pivot->price);
    }

    public function test_editing_a_product_refreshes_the_cache(): void
    {
        $this->cache->forCompany($this->company);

        $this->item->update(['name_ru' => 'Батон']);

        $this->assertSame('Батон', $this->cache->forCompany($this->company)->first()->name_ru);
    }

    public function test_deactivating_a_product_removes_it_from_the_cached_catalog(): void
    {
        $this->assertCount(1, $this->cache->forCompany($this->company));

        $this->item->update(['is_active' => false]);

        $this->assertCount(0, $this->cache->forCompany($this->company));
    }

    public function test_changing_a_price_in_the_crm_refreshes_the_cache(): void
    {
        $this->cache->forCompany($this->company);
        $this->actingAs(User::factory()->create());

        Livewire::test('crm.company-items', ['companyId' => $this->company->id])
            ->set('prices.'.$this->item->id, '2500')
            ->call('savePrice', $this->item->id);

        $this->assertEquals(2500, $this->cache->forCompany($this->company)->first()->pivot->price);
    }

    public function test_cache_expires_after_six_hours(): void
    {
        $this->cache->forCompany($this->company);
        $this->company->items()->updateExistingPivot($this->item->id, ['price' => 1]);

        $this->assertEquals(1000, $this->cache->forCompany($this->company)->first()->pivot->price);

        $this->travel(6)->hours();
        $this->travel(1)->minute();

        $this->assertEquals(1, $this->cache->forCompany($this->company)->first()->pivot->price);
    }

    public function test_product_picture_is_served_by_the_app_with_long_cache(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('items/bread.jpg', UploadedFile::fake()->image('bread.jpg')->getContent());
        $item = Item::factory()->create(['image_path' => 'items/bread.jpg']);

        $this->get($item->image_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->get(route('media.item', Item::factory()->create()))->assertNotFound();
        $this->get(route('media.item', Item::factory()->create(['image_path' => 'items/missing.jpg'])))->assertNotFound();
    }

    public function test_product_image_url_follows_the_host_the_page_is_opened_on(): void
    {
        URL::forceRootUrl('http://the-non');
        $item = Item::factory()->create(['image_path' => 'items/bread.jpg']);

        $this->assertStringStartsWith('http://the-non/media/items/'.$item->id.'?v=', $item->image_url);
        $this->assertNull(Item::factory()->create()->image_url);
    }
}
