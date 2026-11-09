<?php

namespace Tests\Feature;

use App\Enums\ItemUnit;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_item_is_created_with_the_1c_code_unit_and_step(): void
    {
        Livewire::test('pages::crm.items.index')
            ->call('create')
            ->set('code', ' 00000101 ')
            ->set('nameUz', 'Burger bulochkasi')
            ->set('nameRu', 'Булочка для бургера')
            ->set('unit', 'pack')
            ->set('step', 10)
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::query()->where('code', '00000101')->firstOrFail();
        $this->assertSame(ItemUnit::Pack, $item->unit);
        $this->assertSame(10, $item->step);
    }

    public function test_1c_code_must_be_unique(): void
    {
        Item::factory()->create(['code' => '00000101']);

        Livewire::test('pages::crm.items.index')
            ->call('create')
            ->set('code', '00000101')
            ->set('nameUz', 'A')
            ->set('nameRu', 'B')
            ->call('save')
            ->assertHasErrors('code');
    }

    public function test_step_must_be_a_positive_whole_number(): void
    {
        Livewire::test('pages::crm.items.index')
            ->call('create')
            ->set('code', '1')
            ->set('nameUz', 'A')
            ->set('nameRu', 'B')
            ->set('step', 0)
            ->call('save')
            ->assertHasErrors('step');
    }

    public function test_item_image_is_stored_and_replaced(): void
    {
        Storage::fake('public');

        $component = Livewire::test('pages::crm.items.index')
            ->call('create')
            ->set('code', '1')
            ->set('nameUz', 'A')
            ->set('nameRu', 'B')
            ->set('image', UploadedFile::fake()->image('first.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $item = Item::query()->firstOrFail();
        $first = $item->image_path;
        Storage::disk('public')->assertExists($first);

        $component
            ->call('edit', $item->id)
            ->set('image', UploadedFile::fake()->image('second.jpg'))
            ->call('save');

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($item->fresh()->image_path);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        Livewire::test('pages::crm.items.index')
            ->call('create')
            ->set('code', '1')
            ->set('nameUz', 'A')
            ->set('nameRu', 'B')
            ->set('image', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors('image')
            ->assertSet('image', null);
    }

    public function test_items_are_searchable_by_code_and_name(): void
    {
        Item::factory()->create(['code' => '777', 'name_ru' => 'Багет', 'name_uz' => 'Baget']);
        Item::factory()->create(['code' => '888', 'name_ru' => 'Круассан', 'name_uz' => 'Kruassan']);

        Livewire::test('pages::crm.items.index')->set('search', '777')->assertSee('Багет')->assertDontSee('Круассан');
        Livewire::test('pages::crm.items.index')->set('search', 'Kruassan')->assertSee('Круассан')->assertDontSee('Багет');
    }

    public function test_item_name_follows_the_current_locale(): void
    {
        $item = Item::factory()->make(['name_uz' => 'Baget', 'name_ru' => 'Багет']);

        app()->setLocale('uz');
        $this->assertSame('Baget', $item->name);

        app()->setLocale('ru');
        $this->assertSame('Багет', $item->name);
    }
}
