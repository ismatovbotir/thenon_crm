<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private Location $location;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('10:00');

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->registered()->for($this->company)->create(['language' => 'ru']);
        $this->location = Location::factory()->for($this->company)->create();
        $this->employee->locations()->attach($this->location);
        $this->item = Item::factory()->create(['step' => 1]);
        $this->company->items()->attach($this->item);

        session(['employee' => ['id' => $this->employee->id, 'telegram_id' => $this->employee->telegram_id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 '.$time));
    }

    public function test_the_flow_starts_on_the_location_and_date_screen_with_today_selected(): void
    {
        $this->location->update(['yandex_url' => 'https://yandex.uz/maps/-/CCUabc']);

        Livewire::test('pages::app.catalog')
            ->assertSet('step', 'setup')
            ->assertSet('orderDate', '2026-11-09')
            ->assertSee($this->location->name)
            ->assertSee('https://yandex.uz/maps/-/CCUabc', false)
            ->call('startOrder')
            ->assertHasNoErrors()
            ->assertSet('step', 'items')
            ->assertSee($this->item->name_ru);
    }

    public function test_an_employee_with_several_locations_picks_one_before_the_items(): void
    {
        $second = Location::factory()->for($this->company)->create();
        $this->employee->locations()->attach($second);

        Livewire::test('pages::app.catalog')
            ->call('selectLocation', $second->id)
            ->call('startOrder')
            ->call('increment', $this->item->id)
            ->call('complete');

        $this->assertSame($second->id, Order::query()->firstOrFail()->location_id);
    }

    public function test_a_future_date_creates_the_order_for_that_date(): void
    {
        Livewire::test('pages::app.catalog')
            ->set('orderDate', '2026-11-12')
            ->call('startOrder')
            ->call('increment', $this->item->id)
            ->call('complete');

        $this->assertSame('2026-11-12', Order::query()->firstOrFail()->order_date->toDateString());
    }

    public function test_dates_outside_the_allowed_window_are_rejected(): void
    {
        $this->company->update(['order_days_ahead' => 3]);

        foreach (['2026-11-08', '2026-11-13', 'not-a-date'] as $date) {
            Livewire::test('pages::app.catalog')
                ->set('orderDate', $date)
                ->call('startOrder')
                ->assertHasErrors('orderDate')
                ->assertSet('step', 'setup');
        }

        Livewire::test('pages::app.catalog')->set('orderDate', '2026-11-12')->call('startOrder')->assertHasNoErrors();
    }

    public function test_the_cutoff_only_blocks_today(): void
    {
        LocationCutoff::query()->create([
            'location_id' => $this->location->id,
            'weekday' => Carbon::now()->dayOfWeekIso,
            'is_open' => false,
        ]);

        Livewire::test('pages::app.catalog')->call('startOrder')->call('increment', $this->item->id)->assertForbidden();

        Livewire::test('pages::app.catalog')
            ->set('orderDate', '2026-11-10')
            ->call('startOrder')
            ->call('increment', $this->item->id)
            ->assertOk();
    }

    public function test_after_the_evening_time_the_order_is_for_the_next_day(): void
    {
        $this->at('21:30');

        Livewire::test('pages::app.catalog')
            ->assertSet('orderDate', '2026-11-10')
            ->set('orderDate', '2026-11-09')
            ->call('startOrder')
            ->assertHasErrors('orderDate');

        Livewire::test('pages::app.catalog')->call('startOrder')->call('increment', $this->item->id)->call('complete');

        $this->assertSame('2026-11-10', Order::query()->firstOrFail()->order_date->toDateString());
    }

    public function test_the_next_day_time_is_adjustable_and_can_be_switched_off(): void
    {
        $this->company->update(['next_day_from' => '23:00']);
        $this->at('22:00');
        Livewire::test('pages::app.catalog')->assertSet('orderDate', '2026-11-09');

        $this->company->update(['next_day_enabled' => false, 'next_day_from' => '08:00']);
        Livewire::test('pages::app.catalog')->assertSet('orderDate', '2026-11-09');
    }

    public function test_the_admin_gets_a_text_copy_of_a_completed_order(): void
    {
        Queue::fake();
        config(['telegram.admin_chat_id' => '777']);
        $this->location->update(['yandex_url' => 'https://yandex.uz/maps/-/CCUabc']);

        Livewire::test('pages::app.catalog')->call('startOrder')->call('increment', $this->item->id)->call('complete');

        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->chatId === 777
            && str_contains($job->text, $this->location->name)
            && str_contains($job->text, $this->item->name_ru)
            && str_contains($job->text, 'https://yandex.uz/maps/-/CCUabc')
            && str_contains($job->text, route('orders.preview', Order::query()->firstOrFail())));
    }

    public function test_order_preview_opens_by_uuid_without_login(): void
    {
        $order = Order::factory()->completed()->for($this->location)->create();
        $order->lines()->create(['item_id' => $this->item->id, 'quantity' => 5]);

        $this->assertTrue(Str::isUuid($order->uuid));

        $this->get(route('orders.preview', $order))
            ->assertOk()
            ->assertSee($this->location->name)
            ->assertSee($this->item->name_ru)
            ->assertSee('noindex', false);
    }

    public function test_order_preview_does_not_open_by_numeric_id_or_unknown_uuid(): void
    {
        $order = Order::factory()->for($this->location)->create();

        $this->get('/o/'.$order->id)->assertNotFound();
        $this->get('/o/'.Str::uuid())->assertNotFound();
    }

    public function test_no_admin_message_is_sent_without_an_admin_id(): void
    {
        Queue::fake();
        config(['telegram.admin_chat_id' => null]);

        Livewire::test('pages::app.catalog')->call('startOrder')->call('increment', $this->item->id)->call('complete');

        Queue::assertNothingPushed();
    }

    public function test_location_yandex_link_must_be_an_https_yandex_address(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['https://evil.example/maps', 'javascript:alert(1)', 'http://yandex.uz/maps/1'] as $url) {
            Livewire::test('crm.company-locations', ['companyId' => $this->company->id])
                ->call('create')
                ->set('name', 'Branch')
                ->set('yandexUrl', $url)
                ->call('save')
                ->assertHasErrors('yandexUrl');
        }

        Livewire::test('crm.company-locations', ['companyId' => $this->company->id])
            ->call('create')
            ->set('name', 'Branch')
            ->set('yandexUrl', 'https://yandex.uz/maps/-/CCUabc')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('locations', ['name' => 'Branch', 'yandex_url' => 'https://yandex.uz/maps/-/CCUabc']);
    }

    public function test_company_order_window_settings_can_be_saved(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::crm.companies.index')
            ->call('edit', $this->company->id)
            ->set('orderDaysAhead', 14)
            ->set('nextDayEnabled', true)
            ->set('nextDayFrom', '20:30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'order_days_ahead' => 14, 'next_day_from' => '20:30']);
    }
}
