<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAppAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123:ABC';

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.bot_token' => self::TOKEN]);
    }

    private function initData(int $telegramId, ?int $authDate = null, string $token = self::TOKEN): string
    {
        $fields = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAH',
            'user' => json_encode(['id' => $telegramId, 'first_name' => 'Test']),
        ];

        ksort($fields);
        $check = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

        return http_build_query($fields);
    }

    public function test_visitors_without_a_session_get_the_telegram_gate(): void
    {
        $this->get(route('app.home'))->assertUnauthorized()->assertSee('telegram-web-app.js', false);
    }

    public function test_valid_init_data_opens_the_app_for_an_active_employee(): void
    {
        $employee = Employee::factory()->registered()->create(['telegram_id' => 77]);
        $location = Location::factory()->for($employee->company)->create(['name' => 'Chilonzor Point']);
        $employee->locations()->attach($location);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77)])->assertOk();

        $this->get(route('app.home'))->assertOk()->assertSee('Chilonzor Point')->assertSee($employee->name);
    }

    public function test_tampered_or_foreign_init_data_is_refused(): void
    {
        Employee::factory()->registered()->create(['telegram_id' => 77]);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77, token: '999:OTHER')])->assertForbidden();
        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77).'x'])->assertForbidden();
        $this->postJson(route('app.auth'), ['init_data' => ''])->assertForbidden();
        $this->get(route('app.home'))->assertUnauthorized();
    }

    public function test_expired_init_data_is_refused(): void
    {
        Employee::factory()->registered()->create(['telegram_id' => 77]);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77, time() - 90000)])->assertForbidden();
    }

    public function test_unregistered_telegram_account_is_refused(): void
    {
        $this->postJson(route('app.auth'), ['init_data' => $this->initData(404)])->assertForbidden();
    }

    public function test_deactivated_employee_cannot_sign_in(): void
    {
        Employee::factory()->registered()->inactive()->create(['telegram_id' => 77]);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77)])->assertForbidden();
    }

    public function test_deactivation_locks_out_an_open_session_instantly(): void
    {
        $employee = Employee::factory()->registered()->create(['telegram_id' => 77]);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77)])->assertOk();
        $this->get(route('app.home'))->assertOk();

        $employee->update(['is_active' => false]);

        $this->get(route('app.home'))->assertUnauthorized();
    }

    public function test_unlinking_telegram_ends_the_session(): void
    {
        $employee = Employee::factory()->registered()->create(['telegram_id' => 77]);

        $this->postJson(route('app.auth'), ['init_data' => $this->initData(77)])->assertOk();
        $employee->forceFill(['telegram_id' => null])->save();

        $this->get(route('app.home'))->assertUnauthorized();
    }

    public function test_init_data_with_array_style_fields_is_refused_not_a_server_error(): void
    {
        config(['telegram.bot_token' => self::TOKEN]);

        $this->postJson(route('app.auth'), ['init_data' => 'x[]=1&hash=abc'])->assertForbidden();
        $this->postJson(route('app.auth'), ['init_data' => 'user[a]=1&hash=abc'])->assertForbidden();
    }
}
