<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orderCount = 0;

    private ?Item $item = null;

    private string $token;

    private ApiToken $record;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->record, $this->token] = ApiToken::issue('1C');
    }

    /**
     * @return array<string, string>
     */
    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function order(OrderStatus $status = OrderStatus::Completed, $date = null): Order
    {
        $this->orderCount++;
        $this->item ??= Item::factory()->create(['code' => '00000101']);

        $order = Order::factory()->for(Location::factory()->for(Company::factory()->create(['name' => $this->orderCount === 1 ? 'Alpha' : 'Alpha '.$this->orderCount])))->create([
            'status' => $status,
            'order_date' => $date ?? today(),
        ]);
        OrderItem::factory()->for($order)->create(['item_id' => $this->item->id, 'quantity' => 30, 'price' => 1500]);

        return $order;
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/orders', $this->auth('thenon_wrong'))->assertUnauthorized();
        $this->getJson('/api/v1/summary')->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $this->record->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/orders', $this->auth())->assertUnauthorized();
    }

    public function test_tokens_are_stored_hashed(): void
    {
        $this->assertNotSame($this->token, $this->record->token_hash);
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $this->token]);
    }

    public function test_orders_include_company_location_and_1c_item_codes(): void
    {
        $order = $this->order();

        $this->getJson('/api/v1/orders', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.company.name', 'Alpha')
            ->assertJsonPath('data.0.lines.0.item_code', '00000101')
            ->assertJsonPath('data.0.lines.0.quantity', 30)
            ->assertJsonPath('data.0.lines.0.price', 1500);

        $this->assertNotNull($this->record->fresh()->last_used_at);
    }

    public function test_drafts_are_hidden_unless_requested(): void
    {
        $this->order(OrderStatus::Draft);

        $this->getJson('/api/v1/orders', $this->auth())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=draft', $this->auth())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_orders_can_be_filtered(): void
    {
        $today = $this->order();
        $this->order(OrderStatus::Sent, today()->subDays(2));

        $this->getJson('/api/v1/orders?date='.today()->toDateString(), $this->auth())->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $today->id);
        $this->getJson('/api/v1/orders?status=sent', $this->auth())->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?from='.today()->subDays(3)->toDateString(), $this->auth())->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/orders?company_id='.$today->location->company_id, $this->auth())->assertJsonCount(1, 'data');
    }

    public function test_updated_since_supports_incremental_sync(): void
    {
        $old = $this->order();
        $old->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $fresh = $this->order();

        $this->getJson('/api/v1/orders?updated_since='.urlencode(now()->subDay()->toIso8601String()), $this->auth())
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fresh->id);
    }

    public function test_invalid_filters_return_validation_errors(): void
    {
        $this->getJson('/api/v1/orders?date=yesterday', $this->auth())->assertUnprocessable();
        $this->getJson('/api/v1/orders?status=nope', $this->auth())->assertUnprocessable();
        $this->getJson('/api/v1/orders?per_page=9999', $this->auth())->assertUnprocessable();
    }

    public function test_summary_totals_by_item_code(): void
    {
        $this->order();
        $this->order(OrderStatus::Accepted);
        $this->order(OrderStatus::Draft);

        $this->getJson('/api/v1/summary?date='.today()->toDateString(), $this->auth())
            ->assertOk()
            ->assertJsonPath('data.from', today()->toDateString())
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.item_code', '00000101')
            ->assertJsonPath('data.items.0.quantity', 60);
    }

    public function test_only_admins_can_manage_tokens(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('crm.settings'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('crm.settings'))->assertOk();
    }

    public function test_admin_creates_and_revokes_a_token_that_is_shown_once(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test('pages::crm.settings')
            ->set('tokenName', '1C warehouse')
            ->call('createToken')
            ->assertHasNoErrors();

        $plain = $component->get('newToken');
        $this->assertStringStartsWith('thenon_', $plain);
        $this->getJson('/api/v1/orders', $this->auth($plain))->assertOk();

        $created = ApiToken::query()->where('name', '1C warehouse')->firstOrFail();
        $component->call('revoke', $created->id);

        $this->getJson('/api/v1/orders', $this->auth($plain))->assertUnauthorized();
    }

    public function test_token_name_is_required(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('pages::crm.settings')->call('createToken')->assertHasErrors('tokenName');
    }

    public function test_1c_fetches_only_accepted_orders_it_has_not_answered_yet(): void
    {
        $pending = $this->order(OrderStatus::Accepted);
        $this->order(OrderStatus::Completed);
        $this->order(OrderStatus::Done);
        $answered = $this->order(OrderStatus::Accepted);
        $answered->update(['synced_1c_at' => now()]);

        $ids = collect($this->getJson('/api/v1/orders?pending_1c=1', $this->auth())->assertOk()->json('data'))->pluck('uuid')->all();

        $this->assertSame([$pending->uuid], $ids);
    }

    public function test_1c_answers_with_its_number_and_the_order_is_marked_as_in_1c(): void
    {
        $order = $this->order(OrderStatus::Accepted);

        $this->postJson('/api/v1/1c/orders/'.$order->uuid, ['one_c_number' => 'НФ-000777'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.one_c_number', 'НФ-000777');

        $order->refresh();
        $this->assertSame('НФ-000777', $order->one_c_number);
        $this->assertNotNull($order->synced_1c_at);

        $first = $order->synced_1c_at;
        $this->travel(5)->minutes();
        $this->postJson('/api/v1/1c/orders/'.$order->uuid, ['one_c_number' => 'НФ-000777'], $this->auth())->assertOk();
        $this->assertTrue($order->fresh()->synced_1c_at->equalTo($first));
    }

    public function test_1c_wins_over_lines_and_prices_but_never_changes_the_status(): void
    {
        $order = $this->order(OrderStatus::Accepted);
        $other = Item::factory()->create(['code' => '00000202']);
        $new = Item::factory()->create(['code' => '00000303']);
        $order->lines()->create(['item_id' => $other->id, 'quantity' => 5, 'price' => 100]);

        $this->postJson('/api/v1/1c/orders/'.$order->uuid, [
            'one_c_number' => 'НФ-1',
            'status' => 'delivered',
            'lines' => [
                ['item_code' => '00000101', 'quantity' => 25, 'price' => 1600, 'comment' => 'Меньше, чем просили'],
                ['item_code' => '00000303', 'quantity' => 4, 'price' => 700, 'is_active' => false],
            ],
        ], $this->auth())->assertOk();

        $order->refresh()->load('lines');
        $this->assertSame(OrderStatus::Accepted, $order->status);

        $byItem = $order->lines->keyBy('item_id');
        $this->assertSame(25, $byItem[$this->item->id]->quantity);
        $this->assertSame('Меньше, чем просили', $byItem[$this->item->id]->comment);
        $this->assertFalse($byItem[$new->id]->is_active);
        $this->assertFalse($byItem[$other->id]->is_active);
        $this->assertSame(25 * 1600.0, $order->total());
    }

    public function test_1c_cannot_touch_drafts_or_send_bad_data(): void
    {
        $draft = $this->order(OrderStatus::Draft);

        $this->postJson('/api/v1/1c/orders/'.$draft->uuid, ['one_c_number' => 'X'], $this->auth())->assertUnprocessable();

        $order = $this->order(OrderStatus::Accepted);
        $this->postJson('/api/v1/1c/orders/'.$order->uuid, [], $this->auth())->assertUnprocessable();
        $this->postJson('/api/v1/1c/orders/'.$order->uuid, ['one_c_number' => 'X', 'lines' => [['item_code' => 'missing', 'quantity' => 1]]], $this->auth())->assertUnprocessable();
        $this->postJson('/api/v1/1c/orders/'.$order->uuid, ['one_c_number' => 'X'])->assertUnauthorized();
    }

    public function test_1c_imports_items_by_code_and_can_switch_off_the_missing_ones(): void
    {
        $keep = Item::factory()->create(['code' => 'A1', 'name_ru' => 'Старое', 'unit' => 'pcs']);
        $gone = Item::factory()->create(['code' => 'B2', 'is_active' => true]);

        $this->postJson('/api/v1/1c/items', [
            'full_sync' => true,
            'items' => [
                ['code' => 'A1', 'name_ru' => 'Лепёшка', 'unit' => 'pack', 'step' => 5],
                ['code' => 'C3', 'name_uz' => 'Non', 'category' => 'Bread'],
            ],
        ], $this->auth())->assertOk()->assertJsonPath('data', ['created' => 1, 'updated' => 1, 'deactivated' => 1]);

        $this->assertSame('Лепёшка', $keep->fresh()->name_ru);
        $this->assertSame(5, $keep->fresh()->step);
        $this->assertFalse($gone->fresh()->is_active);
        $created = Item::query()->where('code', 'C3')->firstOrFail();
        $this->assertSame('Non', $created->name_ru);
        $this->assertSame('Non', $created->name_uz);

        $this->postJson('/api/v1/1c/items', ['items' => [['code' => 'A1']]], $this->auth())->assertUnprocessable();
        $this->postJson('/api/v1/1c/items', ['items' => [['code' => 'D4', 'name_ru' => 'X']]])->assertUnauthorized();
    }
}
