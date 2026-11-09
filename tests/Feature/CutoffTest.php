<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CutoffTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Item $item;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->location = Location::factory()->for($company)->create();
        $this->employee = Employee::factory()->registered()->for($company)->create(['language' => 'ru']);
        $this->employee->locations()->attach($this->location);
        $this->item = Item::factory()->create(['step' => 1]);
        $company->items()->attach($this->item);

        session(['employee' => ['id' => $this->employee->id, 'telegram_id' => $this->employee->telegram_id]]);
    }

    /** Monday 10:00 Tashkent time. */
    private function at(string $time = '10:00'): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 '.$time, 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rule(array $attributes): void
    {
        LocationCutoff::factory()->for($this->location)->create(array_merge(['weekday' => 1], $attributes));
    }

    public function test_no_rule_means_ordering_is_always_open(): void
    {
        $this->at('23:59');

        $this->assertFalse($this->location->fresh()->isOrderingClosed(now()));
    }

    public function test_ordering_closes_at_the_cutoff_time(): void
    {
        $this->rule(['cutoff_time' => '18:00']);

        $this->at('17:59');
        $this->assertFalse($this->location->fresh()->isOrderingClosed(now()));

        $this->at('18:00');
        $this->assertTrue($this->location->fresh()->isOrderingClosed(now()));
    }

    public function test_a_rule_for_another_weekday_does_not_apply(): void
    {
        $this->rule(['weekday' => 2, 'cutoff_time' => '08:00']);
        $this->at('12:00');

        $this->assertFalse($this->location->fresh()->isOrderingClosed(now()));
    }

    public function test_a_closed_day_blocks_ordering_all_day(): void
    {
        $this->rule(['is_open' => false, 'cutoff_time' => null]);
        $this->at('06:00');

        $this->assertTrue($this->location->fresh()->isOrderingClosed(now()));
    }

    public function test_employee_cannot_order_after_the_cutoff(): void
    {
        $this->rule(['cutoff_time' => '18:00']);
        $this->at('19:00');

        Livewire::test('pages::app.catalog')
            ->call('startOrder')
            ->assertSee(__('order.closed_title', [], 'ru'))
            ->call('increment', $this->item->id)->assertForbidden();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_employee_can_order_before_the_cutoff_and_sees_the_time(): void
    {
        $this->rule(['cutoff_time' => '18:00']);
        $this->at('09:00');

        Livewire::test('pages::app.catalog')
            ->call('startOrder')
            ->assertSee('18:00')
            ->call('increment', $this->item->id)->assertOk();

        $this->assertSame(1, Order::query()->count());
    }

    public function test_an_unfinished_draft_cannot_be_completed_after_the_cutoff(): void
    {
        $this->rule(['cutoff_time' => '18:00']);
        $this->at('10:00');
        $page = Livewire::test('pages::app.catalog')->call('increment', $this->item->id);

        $this->at('18:30');

        $page->call('complete')->assertForbidden();
        $this->assertSame('draft', Order::query()->firstOrFail()->status->value);
    }

    public function test_operator_can_still_complete_a_draft_after_the_cutoff(): void
    {
        $this->rule(['cutoff_time' => '18:00']);
        $this->at('10:00');
        Livewire::test('pages::app.catalog')->call('increment', $this->item->id);
        $order = Order::query()->firstOrFail();

        $this->at('19:00');
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'completed');

        $this->assertSame('completed', $order->fresh()->status->value);
    }

    public function test_schedule_saves_one_row_per_restricted_day(): void
    {
        $this->actingAs(User::factory()->create());

        $rows = collect(range(1, 7))->mapWithKeys(fn ($day) => [$day => ['is_open' => true, 'time' => '']])->all();
        $rows[1]['time'] = '17:30';
        $rows[7]['is_open'] = false;

        Livewire::test('crm.location-schedule', ['locationId' => $this->location->id])
            ->set('rows', $rows)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('schedule-closed');

        $this->assertSame(2, LocationCutoff::query()->count());
        $this->assertSame('17:30', LocationCutoff::query()->where('weekday', 1)->firstOrFail()->cutoffTimeLabel());
        $this->assertFalse(LocationCutoff::query()->where('weekday', 7)->firstOrFail()->is_open);
    }

    public function test_schedule_removes_the_restriction_when_time_is_cleared(): void
    {
        $this->actingAs(User::factory()->create());
        $this->rule(['cutoff_time' => '18:00']);

        $component = Livewire::test('crm.location-schedule', ['locationId' => $this->location->id])
            ->assertSet('rows.1.time', '18:00')
            ->set('rows.1.time', '')
            ->call('save');

        $this->assertSame(0, LocationCutoff::query()->count());
        $component->assertHasNoErrors();
    }

    public function test_schedule_rejects_an_invalid_time(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('crm.location-schedule', ['locationId' => $this->location->id])
            ->set('rows.2.time', '25:99')
            ->call('save')
            ->assertHasErrors('rows.2.time');
    }
}
