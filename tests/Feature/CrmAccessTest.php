<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrmAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_the_crm_login(): void
    {
        $this->get('/crm')->assertRedirect(route('crm.login'));
    }

    public function test_login_page_is_reachable_by_guests(): void
    {
        $this->get('/crm/login')->assertOk();
    }

    public function test_admin_and_operator_can_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/crm')->assertOk();
        $this->actingAs(User::factory()->create())->get('/crm')->assertOk();
    }

    public function test_active_user_can_log_in(): void
    {
        $user = User::factory()->create();

        Livewire::test('pages::crm.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('crm.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::test('pages::crm.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        Livewire::test('pages::crm.login')
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_deactivated_during_a_session_loses_access_immediately(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/crm')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/crm')->assertRedirect(route('crm.login'));
        $this->assertGuest();
    }

    public function test_demo_credentials_are_shown_only_when_enabled(): void
    {
        config(['crm.demo_login' => true]);
        Livewire::test('pages::crm.login')
            ->assertSee('admin@thenon.uz')
            ->assertSee('operator@thenon.uz');

        config(['crm.demo_login' => false]);
        Livewire::test('pages::crm.login')
            ->assertDontSee('admin@thenon.uz');
    }

    public function test_demo_account_can_fill_the_login_form(): void
    {
        config(['crm.demo_login' => true]);

        Livewire::test('pages::crm.login')
            ->call('fillDemo', 'operator@thenon.uz')
            ->assertSet('email', 'operator@thenon.uz')
            ->assertSet('password', config('crm.seed_password'));
    }

    public function test_demo_fill_is_refused_when_disabled_or_for_unknown_accounts(): void
    {
        config(['crm.demo_login' => false]);
        Livewire::test('pages::crm.login')->call('fillDemo', 'admin@thenon.uz')->assertForbidden();

        config(['crm.demo_login' => true]);
        Livewire::test('pages::crm.login')->call('fillDemo', 'someone@else.com')->assertForbidden();
    }

    public function test_seeded_demo_users_can_log_in(): void
    {
        $this->seed();

        foreach (config('crm.demo_users') as $account) {
            Livewire::test('pages::crm.login')
                ->set('email', $account['email'])
                ->set('password', config('crm.seed_password'))
                ->call('login')
                ->assertHasNoErrors();

            auth()->logout();
        }
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/crm/logout')
            ->assertRedirect(route('crm.login'));

        $this->assertGuest();
    }
}
