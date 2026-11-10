<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use App\Models\TvScreen;
use App\Models\User;
use App\Services\TvBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TvBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function placed(Location $location, array $attributes = [], int $quantity = 5): Order
    {
        $item = Item::factory()->create(['name_ru' => 'Лепёшка']);
        $order = Order::factory()->completed()->for($location)->create(array_merge(['order_date' => '2026-11-09', 'completed_at' => now()->subMinutes(30)], $attributes));
        $order->lines()->create(['item_id' => $item->id, 'quantity' => $quantity]);

        return $order;
    }

    public function test_locations_without_an_order_are_listed_by_deadline_and_ordered_ones_move_to_orders(): void
    {
        $company = Company::factory()->create();
        $late = Location::factory()->for($company)->create(['name' => 'Late']);
        $soon = Location::factory()->for($company)->create(['name' => 'Soon']);
        $done = Location::factory()->for($company)->create(['name' => 'Done']);
        $free = Location::factory()->for($company)->create(['name' => 'Free']);
        LocationCutoff::factory()->for($late)->create(['weekday' => 1, 'cutoff_time' => '18:00']);
        LocationCutoff::factory()->for($soon)->create(['weekday' => 1, 'cutoff_time' => '11:00']);
        $this->placed($done);

        $board = app(TvBoard::class)->build(null, now());

        $this->assertSame(['Soon', 'Late', 'Free'], $board['missing']->map(fn ($row) => $row['location']->name)->all());
        $this->assertSame(60, $board['missing']->first()['minutes_left']);
        $this->assertSame(1, $board['ordered']);
        $this->assertSame(4, $board['expected']);
        $this->assertSame('Done', $board['orders']->first()['order']->location->name);
    }

    public function test_orders_are_newest_first_and_a_fresh_one_is_marked_new(): void
    {
        $company = Company::factory()->create();
        $old = Location::factory()->for($company)->create(['name' => 'Old']);
        $fresh = Location::factory()->for($company)->create(['name' => 'Fresh']);
        $this->placed($old, ['completed_at' => now()->subHours(2)]);
        $this->placed($fresh, ['completed_at' => now()->subSeconds(30)]);

        $orders = app(TvBoard::class)->build(null, now())['orders'];

        $this->assertSame(['Fresh', 'Old'], $orders->map(fn ($row) => $row['order']->location->name)->all());
        $this->assertTrue($orders[0]['is_new']);
        $this->assertFalse($orders[1]['is_new']);
    }

    public function test_drafts_do_not_count_as_ordered_but_are_flagged(): void
    {
        $location = Location::factory()->create();
        $draft = Order::factory()->create(['location_id' => $location->id, 'order_date' => '2026-11-09', 'status' => OrderStatus::Draft]);
        $draft->lines()->create(['item_id' => Item::factory()->create()->id, 'quantity' => 2]);

        $board = app(TvBoard::class)->build(null, now());

        $this->assertSame(0, $board['ordered']);
        $this->assertTrue($board['missing']->first()['has_draft']);
    }

    public function test_quantities_are_totalled_per_product(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->create(['name_ru' => 'Самса']);
        foreach ([3, 4] as $quantity) {
            $order = Order::factory()->completed()->for(Location::factory()->for($company)->create())->create(['order_date' => '2026-11-09']);
            $order->lines()->create(['item_id' => $item->id, 'quantity' => $quantity]);
        }

        $totals = app(TvBoard::class)->build(null, now())['totals'];

        $this->assertSame(7, $totals->firstWhere('name', $item->name)['quantity']);
    }

    public function test_a_company_screen_only_sees_its_own_locations(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        Location::factory()->for($mine)->create(['name' => 'Mine']);
        Location::factory()->for($other)->create(['name' => 'Other']);

        $board = app(TvBoard::class)->build($mine->id, now());

        $this->assertSame(['Mine'], $board['missing']->map(fn ($row) => $row['location']->name)->all());
    }

    public function test_a_closed_weekday_is_not_expected_to_order(): void
    {
        $location = Location::factory()->create();
        LocationCutoff::factory()->for($location)->create(['weekday' => 1, 'is_open' => false, 'cutoff_time' => null]);

        $board = app(TvBoard::class)->build(null, now());

        $this->assertSame(0, $board['expected']);
        $this->assertCount(0, $board['missing']);
    }

    public function test_a_day_running_past_midnight_keeps_showing_the_previous_day_until_its_deadline(): void
    {
        $location = Location::factory()->create(['name' => 'Night']);
        LocationCutoff::factory()->for($location)->create(['weekday' => 1, 'cutoff_time' => '06:00', 'ends_next_day' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 03:00', 'Asia/Tashkent'));

        $row = app(TvBoard::class)->build(null, now())['missing']->first();

        $this->assertSame('2026-11-09', $row['date']->toDateString());
        $this->assertSame(180, $row['minutes_left']);
    }

    public function test_the_screen_opens_by_its_secret_url_without_login(): void
    {
        $screen = TvScreen::factory()->create(['name' => 'Bakery wall']);
        Location::factory()->create(['name' => 'Chilonzor']);

        $this->get($screen->url())
            ->assertOk()
            ->assertSee('Chilonzor')
            ->assertSee(__('tv.missing', [], 'ru'));

        $this->assertNotNull($screen->fresh()->last_seen_at);
    }

    public function test_unknown_or_disabled_tokens_show_nothing(): void
    {
        $this->get(route('tv.board', 'nope'))->assertNotFound();

        $screen = TvScreen::factory()->create(['is_active' => false]);
        $this->get($screen->url())->assertNotFound();
    }

    public function test_admins_manage_screens_and_operators_cannot(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('crm.tv'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $company = Company::factory()->create();

        Livewire::test('pages::crm.tv')
            ->set('name', 'Kitchen')
            ->set('companyId', $company->id)
            ->call('create')
            ->assertHasNoErrors();

        $screen = TvScreen::query()->firstOrFail();
        $this->assertSame($company->id, $screen->company_id);
        $oldToken = $screen->token;

        Livewire::test('pages::crm.tv')->call('regenerate', $screen->id)->call('toggle', $screen->id);
        $screen->refresh();
        $this->assertNotSame($oldToken, $screen->token);
        $this->assertFalse($screen->is_active);

        Livewire::test('pages::crm.tv')->call('remove', $screen->id);
        $this->assertDatabaseCount('tv_screens', 0);
    }

    public function test_an_all_companies_screen_has_a_slide_per_company_and_rotates_every_15_seconds(): void
    {
        foreach (['Beta', 'Alpha'] as $name) {
            Location::factory()->for(Company::factory()->create(['name' => $name]))->create(['name' => $name.' branch']);
        }
        Company::factory()->create(['name' => 'Empty']);
        $screen = TvScreen::factory()->create();

        $slides = app(TvBoard::class)->slides(null, now());

        $this->assertSame(['Alpha', 'Beta'], $slides->map(fn ($slide) => $slide['company']->name)->all());
        $this->assertSame(15, TvBoard::SLIDE_SECONDS);

        $this->get($screen->url())
            ->assertOk()
            ->assertSee('Alpha branch')
            ->assertSee('Beta branch')
            ->assertSee('15 * 1000', false);
    }

    public function test_a_company_screen_has_a_single_slide(): void
    {
        $mine = Company::factory()->create(['name' => 'Mine']);
        Location::factory()->for($mine)->create();
        Location::factory()->for(Company::factory()->create(['name' => 'Other']))->create();

        $slides = app(TvBoard::class)->slides($mine->id, now());

        $this->assertCount(1, $slides);
        $this->assertSame('Mine', $slides->first()['company']->name);
    }

    public function test_many_locations_flow_into_several_columns(): void
    {
        $company = Company::factory()->create(['name' => 'Big']);
        $screen = TvScreen::factory()->create();
        foreach (range(1, 14) as $number) {
            Location::factory()->for($company)->create(['name' => 'Branch '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
        }

        $html = $this->get($screen->url())->assertOk()->getContent();

        $this->assertStringContainsString('grid-cols-3', $html);
        $this->assertStringContainsString('Branch 14', $html);
    }

    public function test_a_client_screen_hides_the_quantities_to_prepare(): void
    {
        $company = Company::factory()->create();
        Location::factory()->for($company)->create(['name' => 'Chilonzor']);
        $ours = TvScreen::factory()->create();
        $client = TvScreen::factory()->create(['company_id' => $company->id, 'type' => TvScreen::TYPE_CLIENT]);

        $this->get($ours->url())->assertOk()->assertSee(__('tv.totals', [], 'ru'));
        $this->get($client->url())->assertOk()->assertSee('Chilonzor')->assertDontSee(__('tv.totals', [], 'ru'));
    }

    public function test_a_client_screen_needs_a_company(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $company = Company::factory()->create();

        Livewire::test('pages::crm.tv')
            ->set('name', 'Client wall')
            ->set('type', 'client')
            ->call('create')
            ->assertHasErrors('companyId')
            ->set('companyId', $company->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('client', TvScreen::query()->firstOrFail()->type);
    }
}
