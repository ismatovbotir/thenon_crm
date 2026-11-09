<?php

namespace Tests\Feature;

use App\Enums\Language;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123:ABC',
            'telegram.webhook_secret' => self::SECRET,
            'telegram.web_app_url' => 'https://example.test/app',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function send(array $update, ?string $secret = self::SECRET): TestResponse
    {
        return $this->postJson('/telegram/webhook', $update, $secret ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function contactUpdate(int $telegramId, string $phone, ?int $contactUserId = null): array
    {
        return ['message' => [
            'chat' => ['id' => $telegramId, 'type' => 'private'],
            'from' => ['id' => $telegramId],
            'contact' => ['phone_number' => $phone, 'user_id' => $contactUserId ?? $telegramId],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function startUpdate(int $telegramId): array
    {
        return ['message' => ['chat' => ['id' => $telegramId, 'type' => 'private'], 'from' => ['id' => $telegramId], 'text' => '/start']];
    }

    private function sentText(): string
    {
        return (string) collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['text'] ?? '')->last();
    }

    public function test_webhook_rejects_requests_without_the_secret(): void
    {
        $this->send(['message' => []], null)->assertForbidden();
        $this->send(['message' => []], 'wrong')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_start_asks_for_a_language(): void
    {
        $this->send($this->startUpdate(1))->assertOk();

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'lang:uz'));
    }

    public function test_choosing_a_language_asks_for_the_phone(): void
    {
        $this->send(['callback_query' => ['id' => 'cb', 'from' => ['id' => 5], 'message' => ['chat' => ['id' => 5]], 'data' => 'lang:uz']]);

        $this->assertSame(__('telegram.share_phone', [], 'uz'), $this->sentText());
        $this->assertSame('uz', Cache::get('telegram.language.5'));
    }

    public function test_employee_is_registered_when_the_phone_matches(): void
    {
        $employee = Employee::factory()->create(['phone' => '+998901234567']);
        Cache::put('telegram.language.77', 'uz');

        $this->send($this->contactUpdate(77, '998901234567'));

        $employee->refresh();
        $this->assertSame(77, (int) $employee->telegram_id);
        $this->assertSame(Language::Uz, $employee->language);
        $this->assertNotNull($employee->registered_at);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'web_app'));
    }

    public function test_unknown_phone_is_not_registered(): void
    {
        $this->send($this->contactUpdate(77, '+998900000000'));

        $this->assertSame(__('telegram.not_found', [], 'ru'), $this->sentText());
        $this->assertDatabaseMissing('employees', ['telegram_id' => 77]);
    }

    public function test_deactivated_employee_cannot_register(): void
    {
        $employee = Employee::factory()->inactive()->create(['phone' => '+998901234567']);

        $this->send($this->contactUpdate(77, '+998901234567'));

        $this->assertNull($employee->fresh()->telegram_id);
        $this->assertSame(__('telegram.deactivated', [], 'ru'), $this->sentText());
    }

    public function test_someone_elses_contact_card_is_refused(): void
    {
        $employee = Employee::factory()->create(['phone' => '+998901234567']);

        $this->send($this->contactUpdate(77, '+998901234567', contactUserId: 999));

        $this->assertNull($employee->fresh()->telegram_id);
        $this->assertSame(__('telegram.own_phone_only', [], 'ru'), $this->sentText());
    }

    public function test_phone_already_linked_to_another_telegram_account_is_refused(): void
    {
        $employee = Employee::factory()->registered()->create(['phone' => '+998901234567', 'telegram_id' => 50]);

        $this->send($this->contactUpdate(77, '+998901234567'));

        $this->assertSame(50, (int) $employee->fresh()->telegram_id);
        $this->assertSame(__('telegram.already_linked', [], 'ru'), $this->sentText());
    }

    public function test_telegram_account_cannot_be_linked_to_two_employees(): void
    {
        Employee::factory()->registered()->create(['telegram_id' => 77]);
        $second = Employee::factory()->create(['phone' => '+998901234567']);

        $this->send($this->contactUpdate(77, '+998901234567'));

        $this->assertNull($second->fresh()->telegram_id);
    }

    public function test_registered_employee_gets_the_app_button_on_start(): void
    {
        Employee::factory()->registered()->create(['telegram_id' => 77, 'language' => 'ru']);

        $this->send($this->startUpdate(77));

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'web_app'));
    }

    public function test_deactivated_registered_employee_gets_no_app_button(): void
    {
        Employee::factory()->registered()->inactive()->create(['telegram_id' => 77]);

        $this->send($this->startUpdate(77));

        Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), 'web_app'));
    }
}
