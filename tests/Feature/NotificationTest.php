<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Employee $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123:ABC',
            'telegram.web_app_url' => 'https://example.test/app',
            'crm.reminder_minutes' => 30,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->company = Company::factory()->create();
        $this->recipient = Employee::factory()->registered()->for($this->company)->create(['telegram_id' => 555, 'language' => 'ru']);
        $this->location = Location::factory()->for($this->company)->create(['name' => 'Chilonzor', 'default_employee_id' => $this->recipient->id]);
        $this->recipient->locations()->attach($this->location);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private int $orderCount = 0;

    private function completedOrder(OrderStatus $status = OrderStatus::Completed): Order
    {
        $order = Order::factory()->for($this->location)->create([
            'status' => $status,
            'order_date' => today()->subDays(++$this->orderCount + 30),
        ]);
        OrderItem::factory()->for($order)->create();

        return $order;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->all();
    }

    public function test_default_employee_is_told_when_the_operator_changes_the_status(): void
    {
        $order = $this->completedOrder();

        $order->update(['status' => OrderStatus::Accepted]);

        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame(555, $messages[0]['chat_id']);
        $this->assertStringContainsString('Chilonzor', $messages[0]['text']);
        $this->assertStringContainsString(__('order.status.accepted', [], 'ru'), $messages[0]['text']);
        $this->assertArrayNotHasKey('reply_markup', $messages[0]);
    }

    public function test_message_follows_the_recipients_language(): void
    {
        $this->recipient->update(['language' => 'uz']);

        $this->completedOrder()->update(['status' => OrderStatus::Done]);

        $this->assertStringContainsString(__('order.status.done', [], 'uz'), $this->sent()[0]['text']);
    }

    public function test_sent_status_asks_to_confirm_delivery_with_an_app_button(): void
    {
        $this->completedOrder()->update(['status' => OrderStatus::Sent]);

        $message = $this->sent()[0];
        $this->assertStringContainsString(__('telegram.notify.confirm_delivery', [], 'ru'), $message['text']);
        $this->assertSame('https://example.test/app', $message['reply_markup']['inline_keyboard'][0][0]['web_app']['url']);
    }

    public function test_employee_completing_an_order_notifies_the_default_employee(): void
    {
        $item = Item::factory()->create(['step' => 1]);
        $this->company->items()->attach($item);
        $employee = Employee::factory()->registered()->for($this->company)->create(['language' => 'ru']);
        $employee->locations()->attach($this->location);
        session(['employee' => ['id' => $employee->id, 'telegram_id' => $employee->telegram_id]]);

        Livewire::test('pages::app.catalog')->call('increment', $item->id)->call('complete');

        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString(__('order.status.completed', [], 'ru'), $this->sent()[0]['text']);
    }

    public function test_operator_completing_a_draft_notifies_the_default_employee(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->completedOrder(OrderStatus::Draft);

        Livewire::test('pages::crm.orders.show', ['order' => $order])->call('setStatus', 'completed');

        $this->assertCount(1, $this->sent());
    }

    public function test_drafts_and_deliveries_send_nothing(): void
    {
        Order::factory()->for($this->location)->create(['order_date' => today()->subDays(90)]);
        $sent = $this->completedOrder(OrderStatus::Sent);
        $sent->update(['status' => OrderStatus::Delivered]);

        Http::assertNothingSent();
    }

    public function test_no_message_without_a_usable_default_employee(): void
    {
        $this->location->update(['default_employee_id' => null]);
        $this->completedOrder()->update(['status' => OrderStatus::Accepted]);

        $this->recipient->update(['telegram_id' => null]);
        $this->location->update(['default_employee_id' => $this->recipient->id]);
        $this->completedOrder()->update(['status' => OrderStatus::Accepted]);

        $this->recipient->update(['telegram_id' => 555, 'is_active' => false]);
        $this->completedOrder()->update(['status' => OrderStatus::Accepted]);

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_bot_is_not_configured(): void
    {
        config(['telegram.bot_token' => null]);

        $this->completedOrder()->update(['status' => OrderStatus::Accepted]);

        Http::assertNothingSent();
    }

    public function test_a_telegram_failure_makes_the_job_fail_so_it_can_be_retried(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $this->expectException(RuntimeException::class);

        (new SendTelegramMessage(555, 'hello'))->handle(app(TelegramClient::class));
    }

    private function remindableLocation(string $cutoff = '18:00'): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 17:40', 'Asia/Tashkent'));
        LocationCutoff::factory()->for($this->location)->create(['weekday' => 1, 'cutoff_time' => $cutoff]);
    }

    public function test_reminder_is_sent_shortly_before_the_cutoff_when_nothing_was_ordered(): void
    {
        $this->remindableLocation();

        $this->artisan('orders:remind-cutoff')->assertSuccessful();

        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('18:00', $messages[0]['text']);
        $this->assertStringContainsString('Chilonzor', $messages[0]['text']);
    }

    public function test_reminder_is_sent_only_once_per_day(): void
    {
        $this->remindableLocation();

        $this->artisan('orders:remind-cutoff');
        $this->artisan('orders:remind-cutoff');

        $this->assertCount(1, $this->sent());
    }

    public function test_no_reminder_when_an_order_was_already_made(): void
    {
        $this->remindableLocation();
        Order::factory()->completed()->for($this->location)->create(['order_date' => today()]);

        $this->artisan('orders:remind-cutoff');

        Http::assertNothingSent();
    }

    public function test_a_draft_does_not_count_as_ordered(): void
    {
        $this->remindableLocation();
        Order::factory()->for($this->location)->create(['order_date' => today()]);

        $this->artisan('orders:remind-cutoff');

        $this->assertCount(1, $this->sent());
    }

    public function test_no_reminder_outside_the_window_or_when_disabled(): void
    {
        $this->remindableLocation('20:00');
        $this->artisan('orders:remind-cutoff');
        Http::assertNothingSent();

        LocationCutoff::query()->update(['cutoff_time' => '18:00']);
        Cache::flush();
        config(['crm.reminder_minutes' => 0]);
        $this->artisan('orders:remind-cutoff');
        Http::assertNothingSent();
    }

    public function test_no_reminder_after_the_cutoff_has_passed(): void
    {
        $this->remindableLocation('17:30');

        $this->artisan('orders:remind-cutoff');

        Http::assertNothingSent();
    }
}
