<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\Telegram\InitDataSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramMockTest extends TestCase
{
    use RefreshDatabase;

    private function enableMock(): void
    {
        config(['telegram.mock' => true, 'telegram.bot_token' => null]);
    }

    public function test_the_mock_does_not_exist_unless_switched_on(): void
    {
        config(['telegram.mock' => false]);
        $employee = Employee::factory()->registered()->create();

        $this->get(route('dev.telegram'))->assertNotFound();
        $this->post(route('dev.telegram.select', $employee))->assertNotFound();
    }

    public function test_the_mock_is_never_available_in_production(): void
    {
        $this->enableMock();
        $this->app['env'] = 'production';

        $this->get(route('dev.telegram'))->assertNotFound();
    }

    public function test_the_picker_lists_employees_with_their_state(): void
    {
        $this->enableMock();
        $registered = Employee::factory()->registered()->create(['name' => 'Registered Rita']);
        Employee::factory()->inactive()->for($registered->company)->create(['name' => 'Inactive Igor']);

        $this->get(route('dev.telegram'))
            ->assertOk()
            ->assertSee('Registered Rita')
            ->assertSee('Inactive Igor')
            ->assertSee('deactivated');
    }

    public function test_the_app_can_be_opened_end_to_end_as_a_registered_employee(): void
    {
        $this->enableMock();
        $employee = Employee::factory()->registered()->create();

        $this->post(route('dev.telegram.select', $employee))->assertRedirect(route('dev.telegram'));

        $gate = $this->get(route('app.home'))->assertUnauthorized();
        preg_match('/const mockInitData = (".*?");/s', $gate->getContent(), $matches);
        $initData = json_decode($matches[1]);
        $this->assertNotEmpty($initData);

        $this->postJson(route('app.auth'), ['init_data' => $initData])->assertOk();
        $this->get(route('app.home'))->assertOk();
    }

    public function test_unregistered_and_deactivated_employees_are_denied(): void
    {
        $this->enableMock();

        foreach ([Employee::factory()->create(), Employee::factory()->registered()->inactive()->create()] as $employee) {
            $this->post(route('dev.telegram.select', $employee));

            $this->postJson(route('app.auth'), ['init_data' => app(InitDataSigner::class)->sign($employee)])->assertForbidden();
        }
    }

    public function test_switching_employee_forces_a_fresh_sign_in(): void
    {
        $this->enableMock();
        $first = Employee::factory()->registered()->create();
        $second = Employee::factory()->registered()->create();

        $this->post(route('dev.telegram.select', $first));
        $this->postJson(route('app.auth'), ['init_data' => app(InitDataSigner::class)->sign($first)])->assertOk();
        $this->get(route('app.home'))->assertOk();

        $this->post(route('dev.telegram.select', $second));

        $this->get(route('app.home'))->assertUnauthorized();
    }

    public function test_stopping_the_mock_signs_out(): void
    {
        $this->enableMock();
        $employee = Employee::factory()->registered()->create();

        $this->post(route('dev.telegram.select', $employee));
        $this->postJson(route('app.auth'), ['init_data' => app(InitDataSigner::class)->sign($employee)])->assertOk();
        $this->post(route('dev.telegram.stop'));

        $this->get(route('app.home'))->assertUnauthorized()->assertDontSee('Telegram mock');
    }
}
