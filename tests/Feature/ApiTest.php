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
}
