<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class OrderingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private Location $location;

    private Item $baguette;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(today()->setTime(10, 0));

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->registered()->for($this->company)->create(['language' => 'ru']);
        $this->location = Location::factory()->for($this->company)->create();
        $this->employee->locations()->attach($this->location);

        $this->baguette = Item::factory()->create(['step' => 10, 'category' => 'Bread']);
        $this->company->items()->attach($this->baguette, ['price' => 1500]);

        $this->signIn($this->employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function signIn(Employee $employee): void
    {
        session(['employee' => ['id' => $employee->id, 'telegram_id' => $employee->telegram_id]]);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::app.catalog');
    }

    public function test_adding_items_moves_by_the_item_step_and_saves_a_draft(): void
    {
        $this->page()->call('increment', $this->baguette->id)->call('increment', $this->baguette->id)
            ->assertSet('quantities.'.$this->baguette->id, 20);

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame(20, $order->lines()->firstOrFail()->quantity);
        $this->assertEquals('1500.00', $order->lines()->firstOrFail()->price);
    }

    public function test_decrementing_to_zero_removes_the_line(): void
    {
        $this->page()->call('increment', $this->baguette->id)->call('decrement', $this->baguette->id);

        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_typed_quantity_is_rounded_up_to_the_step(): void
    {
        $this->page()->call('setQuantity', $this->baguette->id, '23')->assertSet('quantities.'.$this->baguette->id, 30);
    }

    public function test_draft_is_restored_when_the_app_is_reopened(): void
    {
        $this->page()->call('increment', $this->baguette->id);

        $this->page()->assertSet('quantities.'.$this->baguette->id, 10);
    }

    public function test_items_not_assigned_to_the_company_cannot_be_ordered(): void
    {
        $foreign = Item::factory()->create();

        $this->page()->call('increment', $foreign->id)->assertForbidden();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_inactive_items_cannot_be_ordered(): void
    {
        $inactive = Item::factory()->inactive()->create();
        $this->company->items()->attach($inactive);

        $this->page()->call('increment', $inactive->id)->assertForbidden();
    }

    public function test_complete_requires_at_least_one_item(): void
    {
        $this->page()->call('complete')->assertHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_completing_locks_the_order_for_the_employee(): void
    {
        $this->page()->call('increment', $this->baguette->id)->call('complete');

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame($this->employee->id, $order->employee_id);
        $this->assertNotNull($order->completed_at);

        $this->page()->call('increment', $this->baguette->id)->assertForbidden();
        $this->assertSame(10, $order->lines()->firstOrFail()->quantity);
    }

    public function test_second_order_the_same_day_shows_the_warning(): void
    {
        Order::factory()->completed()->for($this->location)->create();

        $this->page()
            ->call('startOrder')
            ->assertSee(__('order.already_title'))
            ->assertSee(config('crm.support_phone'));
    }

    public function test_only_one_order_per_location_per_day_exists(): void
    {
        $this->page()->call('increment', $this->baguette->id)->call('complete');
        $this->page()->call('clearCart')->assertForbidden();

        $this->assertSame(1, Order::query()->count());
    }

    public function test_previous_orders_do_not_block_a_new_one(): void
    {
        Order::factory()->completed()->for($this->location)->create(['order_date' => today()->subDay()]);

        $this->page()->call('increment', $this->baguette->id)->assertHasNoErrors();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_copy_last_order_fills_the_cart_with_still_available_items(): void
    {
        $gone = Item::factory()->create();
        $last = Order::factory()->completed()->for($this->location)->create(['order_date' => today()->subDay()]);
        OrderItem::factory()->for($last)->create(['item_id' => $this->baguette->id, 'quantity' => 25]);
        OrderItem::factory()->for($last)->create(['item_id' => $gone->id, 'quantity' => 5]);

        $this->page()->call('copyLast')
            ->assertSet('quantities', [$this->baguette->id => 30]);

        $this->assertSame(OrderStatus::Draft, Order::query()->whereDate('order_date', today())->firstOrFail()->status);
    }

    public function test_copy_last_without_history_only_shows_a_notice(): void
    {
        $this->page()->call('copyLast')->assertSet('notice', __('order.copy_none'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_drafts_are_shared_by_colleagues_at_the_same_location(): void
    {
        $colleague = Employee::factory()->registered()->for($this->company)->create();
        $colleague->locations()->attach($this->location);

        $this->page()->call('increment', $this->baguette->id);

        $this->signIn($colleague);
        $this->page()->assertSet('quantities.'.$this->baguette->id, 10);
    }

    public function test_employee_cannot_switch_to_a_location_that_is_not_theirs(): void
    {
        $foreign = Location::factory()->create();

        $this->page()->call('selectLocation', $foreign->id)->assertForbidden();
    }

    public function test_switching_location_loads_that_locations_order(): void
    {
        $second = Location::factory()->for($this->company)->create();
        $this->employee->locations()->attach($second);
        $other = Order::factory()->for($second)->create();
        OrderItem::factory()->for($other)->create(['item_id' => $this->baguette->id, 'quantity' => 40]);

        $this->page()->call('selectLocation', $second->id)
            ->assertSet('quantities.'.$this->baguette->id, 40);
    }

    public function test_history_lists_only_the_current_locations_orders(): void
    {
        Order::factory()->completed()->for($this->location)->create(['order_date' => today()->subDays(2)]);
        Order::factory()->completed()->create(['order_date' => today()->subDays(3)]);

        $this->assertCount(1, $this->page()->set('tab', 'history')->get('history'));
    }

    public function test_catalog_search_and_category_filter(): void
    {
        $croissant = Item::factory()->create(['name_ru' => 'Круассан', 'name_uz' => 'Kruassan', 'category' => 'Pastry']);
        $this->company->items()->attach($croissant);

        $page = $this->page();
        $this->assertCount(2, $page->get('visibleItems'));
        $this->assertCount(1, $page->set('search', 'Kruassan')->get('visibleItems'));
        $this->assertCount(1, $page->set('search', '')->set('category', 'Bread')->get('visibleItems'));
    }

    public function test_deactivated_employee_cannot_use_the_catalog(): void
    {
        $this->employee->update(['is_active' => false]);

        $this->get(route('app.home'))->assertUnauthorized();
    }

    public function test_catalog_page_renders_for_a_signed_in_employee(): void
    {
        $this->withSession(['employee' => ['id' => $this->employee->id, 'telegram_id' => $this->employee->telegram_id]])
            ->get(route('app.home'))
            ->assertOk()
            ->assertSee($this->location->name)
            ->assertSee(__('order.continue'));
    }
}
