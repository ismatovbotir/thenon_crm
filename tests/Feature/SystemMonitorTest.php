<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SystemMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function failedJob(string $uuid): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => SendTelegramMessage::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 3,
                'data' => ['commandName' => SendTelegramMessage::class, 'command' => serialize(new SendTelegramMessage(1, 'hi'))],
            ]),
            'exception' => "RuntimeException: Telegram did not accept the message.\n#0 stack line",
            'failed_at' => now(),
        ]);
    }

    public function test_only_admins_can_open_the_system_page(): void
    {
        $this->get(route('crm.system'))->assertRedirect(route('crm.login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('crm.system'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('crm.system'))->assertOk()->assertSee(__('crm.system.pending_title', [], 'ru'));
    }

    public function test_operators_cannot_run_commands_or_touch_failed_jobs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::crm.system')->assertForbidden();

        $uuid = (string) Str::uuid();
        $this->failedJob($uuid);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_pending_and_failed_jobs_are_listed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['displayName' => SendTelegramMessage::class]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->failedJob((string) Str::uuid());

        $page = Livewire::test('pages::crm.system');

        $this->assertSame(1, $page->get('stats')['waiting']);
        $this->assertSame(1, $page->get('stats')['failed']);
        $page->assertSee('SendTelegramMessage')->assertSee('Telegram did not accept the message.');
    }

    public function test_a_stuck_queue_shows_the_worker_warning(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(10)->timestamp, 'created_at' => now()->subMinutes(10)->timestamp]);

        Livewire::test('pages::crm.system')->assertSee(__('crm.system.worker_warning', ['minutes' => 10], 'ru'));
    }

    public function test_a_failed_job_can_be_retried_and_forgotten(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $retry = (string) Str::uuid();
        $forget = (string) Str::uuid();
        $this->failedJob($retry);
        $this->failedJob($forget);

        Livewire::test('pages::crm.system')
            ->call('retryFailed', $retry)
            ->assertSet('ranOk', true)
            ->call('forgetFailed', $forget)
            ->assertSet('ranOk', true);

        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_only_valid_job_ids_and_whitelisted_commands_are_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('pages::crm.system')->call('retryFailed', 'all; rm -rf /')->assertStatus(422);
        Livewire::test('pages::crm.system')->call('runCommand', 'migrate:fresh')->assertNotFound();
        Livewire::test('pages::crm.system')->call('runCommand', 'cache:clear')->assertNotFound();
    }

    public function test_a_whitelisted_command_runs_and_shows_its_output(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('pages::crm.system')
            ->call('runCommand', 'remind-cutoff')
            ->assertSet('ranOk', true)
            ->assertSee('Reminders sent');
    }

    public function test_catalog_cache_can_be_refreshed_from_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('pages::crm.system')
            ->call('runCommand', 'catalog-refresh')
            ->assertSet('ranOk', true);
    }

    public function test_a_second_command_is_refused_while_one_is_running(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $lock = cache()->lock('crm-system-command', 60);
        $lock->get();

        Livewire::test('pages::crm.system')
            ->call('runCommand', 'remind-cutoff')
            ->assertSet('ranOk', false)
            ->assertSet('output', __('crm.system.busy'));

        $lock->release();
    }

    public function test_the_system_menu_entry_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('crm.dashboard'))->assertDontSee(route('crm.system'));

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('crm.dashboard'))->assertSee(route('crm.system'));
    }
}
