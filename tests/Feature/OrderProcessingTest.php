<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderProcessingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Burger Chain']);
        $this->location = Location::factory()->for($this->company)->create(['name' => 'Chilonzor']);
        $this->item = Item::factory()->create(['step' => 5]);
        $this->company->items()->attach($this->item, ['price' => 2000]);
    }

    private function operator(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function order(OrderStatus $status = OrderStatus::Completed): Order
    {
        $order = Order::factory()->for($this->location)->create(['status' => $status]);
        OrderItem::factory()->for($order)->create(['item_id' => $this->item->id, 'quantity' => 10]);

        return $order;
    }

    public function test_guests_cannot_open_order_pages(): void
    {
        $order = $this->order();

        $this->get(route('crm.orders.index'))->assertRedirect(route('crm.login'));
        $this->get(route('crm.orders.show', $order))->assertRedirect(route('crm.login'));
    }

    public function test_orders_list_defaults_to_today_and_filters(): void
    {
        $this->operator();
        $today = $this->order();
        $other = Location::factory()->create(['name' => 'Other Place']);
        Order::factory()->for($other)->create(['order_date' => today()->subDay()]);

        Livewire::test('pages::crm.orders.index')
            ->assertSee('Chilonzor')
            ->assertDontSee('Other Place')
            ->set('date', '')
            ->assertSee('Other Place')
            ->set('companyId', (string) $this->company->id)
            ->assertDontSee('Other Place')
            ->set('status', 'delivered')
            ->assertDontSee('Chilonzor');

        $this->assertNotNull($today);
    }

    public function test_orders_list_counts_by_status(): void
    {
        $this->operator();
        $this->order(OrderStatus::Accepted);
        Order::factory()->create(['status' => OrderStatus::Accepted]);

        $counts = Livewire::test('pages::crm.orders.index')->get('counts');

        $this->assertSame(2, $counts['accepted']);
    }

    public function test_order_page_renders(): void
    {
        $this->operator();

        $this->get(route('crm.orders.show', $this->order()))->assertOk()->assertSee('Chilonzor');
    }

    public function test_operator_moves_an_order_through_the_statuses(): void
    {
        $this->operator();
        $order = $this->order();

        foreach (['accepted', 'done', 'sent'] as $status) {
            Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', $status);
            $this->assertSame($status, $order->fresh()->status->value);
        }
    }

    public function test_operator_cannot_mark_an_order_delivered(): void
    {
        $this->operator();
        $order = $this->order(OrderStatus::Sent);

        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'delivered')->assertForbidden();

        $this->assertSame(OrderStatus::Sent, $order->fresh()->status);
    }

    public function test_delivered_orders_are_final(): void
    {
        $this->operator();
        $order = $this->order(OrderStatus::Delivered);

        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'accepted')->assertForbidden();
    }

    public function test_operator_can_complete_a_draft_for_the_employee(): void
    {
        $this->operator();
        $order = $this->order(OrderStatus::Draft);

        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'completed');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_empty_draft_cannot_be_completed(): void
    {
        $this->operator();
        $order = Order::factory()->for($this->location)->create();

        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'completed')->assertHasErrors('status');
    }

    public function test_operator_can_change_quantity_remove_and_add_lines(): void
    {
        $this->operator();
        $order = $this->order();
        $line = $order->lines()->firstOrFail();
        $second = Item::factory()->create(['step' => 3]);
        $this->company->items()->attach($second, ['price' => 700]);

        $page = Livewire::test('pages::crm.orders.show', ['order' => $order])
            ->set('quantities.'.$line->id, 17)
            ->call('saveQuantity', $line->id)
            ->assertHasNoErrors();
        $this->assertSame(17, $line->fresh()->quantity);

        $page->set('newItemId', (string) $second->id)->assertSet('newQuantity', 3)->call('addLine');
        $this->assertSame(3, $order->lines()->where('item_id', $second->id)->firstOrFail()->quantity);
        $this->assertEquals('700.00', $order->lines()->where('item_id', $second->id)->firstOrFail()->price);

        $page->call('removeLine', $line->id);
        $this->assertSame(1, $order->lines()->count());
    }

    public function test_quantity_must_be_a_positive_whole_number(): void
    {
        $this->operator();
        $order = $this->order();
        $line = $order->lines()->firstOrFail();

        Livewire::test('pages::crm.orders.show', ['order' => $order])
            ->set('quantities.'.$line->id, 0)
            ->call('saveQuantity', $line->id)
            ->assertHasErrors('quantities.'.$line->id);
    }

    public function test_items_outside_the_companys_list_cannot_be_added(): void
    {
        $this->operator();
        $order = $this->order();
        $foreign = Item::factory()->create();

        Livewire::test('pages::crm.orders.show', ['order' => $order])
            ->set('newItemId', (string) $foreign->id)
            ->set('newQuantity', 5)
            ->call('addLine')
            ->assertHasErrors('newItemId');
    }

    public function test_sent_orders_cannot_be_edited(): void
    {
        $this->operator();
        $order = $this->order(OrderStatus::Sent);
        $line = $order->lines()->firstOrFail();

        Livewire::test('pages::crm.orders.show', ['order' => $order])
            ->set('quantities.'.$line->id, 99)
            ->call('saveQuantity', $line->id)
            ->assertForbidden();
        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('removeLine', $line->id)->assertForbidden();
    }

    public function test_employee_confirms_delivery_of_a_sent_order(): void
    {
        $employee = Employee::factory()->registered()->for($this->company)->create();
        $employee->locations()->attach($this->location);
        session(['employee' => ['id' => $employee->id, 'telegram_id' => $employee->telegram_id]]);
        $order = $this->order(OrderStatus::Sent);

        Livewire::test('pages::app.catalog')->call('confirmDelivery', $order->id);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_delivery_cannot_be_confirmed_before_sending_or_for_other_locations(): void
    {
        $employee = Employee::factory()->registered()->for($this->company)->create();
        $employee->locations()->attach($this->location);
        session(['employee' => ['id' => $employee->id, 'telegram_id' => $employee->telegram_id]]);

        $notSent = $this->order(OrderStatus::Done);
        $foreign = Order::factory()->create(['status' => OrderStatus::Sent]);

        Livewire::test('pages::app.catalog')->call('confirmDelivery', $notSent->id)->assertNotFound();
        Livewire::test('pages::app.catalog')->call('confirmDelivery', $foreign->id)->assertNotFound();

        $this->assertSame(OrderStatus::Done, $notSent->fresh()->status);
        $this->assertSame(OrderStatus::Sent, $foreign->fresh()->status);
    }
}
