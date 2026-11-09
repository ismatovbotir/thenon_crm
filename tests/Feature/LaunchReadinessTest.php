<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_command_creates_an_admin(): void
    {
        $this->artisan('crm:create-user', ['email' => 'boss@thenon.uz', '--name' => 'Boss', '--password' => 'a-long-secret-1'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'boss@thenon.uz')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('a-long-secret-1', $user->password);
    }

    public function test_create_user_command_can_create_an_operator_and_reset_a_password(): void
    {
        $this->artisan('crm:create-user', ['email' => 'op@thenon.uz', '--role' => 'operator', '--password' => 'first-password-1'])->assertSuccessful();
        $this->artisan('crm:create-user', ['email' => 'op@thenon.uz', '--role' => 'operator', '--password' => 'second-password-2'])->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'op@thenon.uz')->count());
        $this->assertTrue(password_verify('second-password-2', User::query()->firstWhere('email', 'op@thenon.uz')->password));
    }

    public function test_create_user_command_rejects_weak_input(): void
    {
        $this->artisan('crm:create-user', ['email' => 'not-an-email', '--password' => 'long-enough-password'])->assertFailed();
        $this->artisan('crm:create-user', ['email' => 'a@b.uz', '--password' => 'short'])->assertFailed();
        $this->artisan('crm:create-user', ['email' => 'a@b.uz', '--role' => 'god', '--password' => 'long-enough-password'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_seeder_never_creates_demo_accounts_in_production(): void
    {
        $this->app['env'] = 'production';

        (new DatabaseSeeder)->run();

        $this->assertSame(0, User::query()->count());
    }

    public function test_demo_accounts_are_still_seeded_outside_production(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, User::query()->count());
    }

    public function test_demo_login_hint_is_off_by_default_in_the_env_example(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("CRM_DEMO_LOGIN=false\n", $example);
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN=', $example);
    }

    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_security_sensitive_pages_are_not_indexed(): void
    {
        $this->get(route('crm.login'))->assertSee('noindex', false);
        $this->get(route('app.home'))->assertSee('noindex', false);
    }

    public function test_every_route_name_resolves_so_route_caching_is_safe(): void
    {
        $this->artisan('route:cache')->assertSuccessful();
        $this->artisan('route:clear')->assertSuccessful();
    }
}
