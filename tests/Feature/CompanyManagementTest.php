<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_cannot_open_company_pages(): void
    {
        auth()->logout();

        $this->get(route('crm.companies.index'))->assertRedirect(route('crm.login'));
        $this->get(route('crm.items.index'))->assertRedirect(route('crm.login'));
    }

    public function test_operator_can_create_a_company(): void
    {
        Livewire::test('pages::crm.companies.index')
            ->call('create')
            ->set('name', 'Burger Chain')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', ['name' => 'Burger Chain', 'is_active' => true]);
    }

    public function test_company_name_must_be_unique(): void
    {
        Company::factory()->create(['name' => 'Burger Chain']);

        Livewire::test('pages::crm.companies.index')
            ->call('create')
            ->set('name', 'Burger Chain')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_company_can_be_deactivated_and_reactivated(): void
    {
        $company = Company::factory()->create();

        Livewire::test('pages::crm.companies.index')->call('toggleActive', $company->id);
        $this->assertFalse($company->fresh()->is_active);

        Livewire::test('pages::crm.companies.index')->call('toggleActive', $company->id);
        $this->assertTrue($company->fresh()->is_active);
    }

    public function test_company_list_can_be_searched(): void
    {
        Company::factory()->create(['name' => 'Alpha Foods']);
        Company::factory()->create(['name' => 'Beta Grill']);

        Livewire::test('pages::crm.companies.index')
            ->set('search', 'Alpha')
            ->assertSee('Alpha Foods')
            ->assertDontSee('Beta Grill');
    }

    public function test_company_page_renders_every_tab(): void
    {
        $company = Company::factory()->create();

        foreach (['items', 'locations', 'employees'] as $tab) {
            $this->get(route('crm.companies.show', ['company' => $company, 'tab' => $tab]))->assertOk();
        }
    }

    public function test_location_can_be_created_for_a_company(): void
    {
        $company = Company::factory()->create();

        Livewire::test('crm.company-locations', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Chilonzor')
            ->set('address', 'Chilonzor 5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('locations', ['company_id' => $company->id, 'name' => 'Chilonzor']);
    }

    public function test_location_name_is_unique_only_within_a_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        Location::factory()->for($company)->create(['name' => 'Center']);
        Location::factory()->for($other)->create(['name' => 'Center']);

        Livewire::test('crm.company-locations', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Center')
            ->call('save')
            ->assertHasErrors('name');
    }

    public function test_default_employee_must_be_connected_to_the_location(): void
    {
        $company = Company::factory()->create();
        $location = Location::factory()->for($company)->create();
        $outsider = Employee::factory()->for($company)->create();
        $member = Employee::factory()->for($company)->create();
        $location->employees()->attach($member);

        Livewire::test('crm.company-locations', ['companyId' => $company->id])
            ->call('edit', $location->id)
            ->set('defaultEmployeeId', $outsider->id)
            ->call('save')
            ->assertHasErrors('defaultEmployeeId');

        Livewire::test('crm.company-locations', ['companyId' => $company->id])
            ->call('edit', $location->id)
            ->set('defaultEmployeeId', $member->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($member->id, $location->fresh()->default_employee_id);
    }

    public function test_employee_phone_is_normalized_and_connected_to_locations(): void
    {
        $company = Company::factory()->create();
        $location = Location::factory()->for($company)->create();

        Livewire::test('crm.company-employees', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Aziz')
            ->set('phone', '90 123 45 67')
            ->set('locationIds', [(string) $location->id])
            ->call('save')
            ->assertHasNoErrors();

        $employee = Employee::query()->where('phone', '+998901234567')->firstOrFail();
        $this->assertTrue($employee->locations->contains($location));
    }

    public function test_employee_phone_must_be_unique_and_valid(): void
    {
        $company = Company::factory()->create();
        Employee::factory()->for($company)->create(['phone' => '+998901234567']);

        Livewire::test('crm.company-employees', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Duplicate')
            ->set('phone', '+998 90 123 45 67')
            ->call('save')
            ->assertHasErrors('phone');

        Livewire::test('crm.company-employees', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Short')
            ->set('phone', '12345')
            ->call('save')
            ->assertHasErrors('phone');
    }

    public function test_employee_cannot_be_connected_to_another_companys_location(): void
    {
        $company = Company::factory()->create();
        $foreign = Location::factory()->create();

        Livewire::test('crm.company-employees', ['companyId' => $company->id])
            ->call('create')
            ->set('name', 'Aziz')
            ->set('phone', '+998901234567')
            ->set('locationIds', [(string) $foreign->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, Employee::query()->firstOrFail()->locations()->count());
    }

    public function test_deactivating_an_employee_clears_their_default_location_role(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $location = Location::factory()->for($company)->create(['default_employee_id' => $employee->id]);
        $location->employees()->attach($employee);

        Livewire::test('crm.company-employees', ['companyId' => $company->id])->call('toggleActive', $employee->id);

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertNull($location->fresh()->default_employee_id);
    }

    public function test_removing_an_employee_from_a_location_clears_their_default_role_there(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $location = Location::factory()->for($company)->create(['default_employee_id' => $employee->id]);
        $location->employees()->attach($employee);

        Livewire::test('crm.company-employees', ['companyId' => $company->id])
            ->call('edit', $employee->id)
            ->set('locationIds', [])
            ->call('save');

        $this->assertNull($location->fresh()->default_employee_id);
    }

    public function test_items_can_be_assigned_to_a_company_in_bulk(): void
    {
        $company = Company::factory()->create();
        $items = Item::factory()->count(3)->create();
        Item::factory()->inactive()->create();

        Livewire::test('crm.company-items', ['companyId' => $company->id])
            ->set('selected', $items->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('addSelected');

        $this->assertSame(3, $company->items()->count());
    }

    public function test_company_item_price_is_optional(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->create();
        $company->items()->attach($item);

        Livewire::test('crm.company-items', ['companyId' => $company->id])
            ->set('prices.'.$item->id, 'abc')
            ->call('savePrice', $item->id)
            ->assertHasErrors('prices.'.$item->id);

        Livewire::test('crm.company-items', ['companyId' => $company->id])
            ->set('prices.'.$item->id, '1 250,50')
            ->call('savePrice', $item->id)
            ->assertHasNoErrors();

        $this->assertEquals('1250.50', $company->items()->first()->pivot->price);

        Livewire::test('crm.company-items', ['companyId' => $company->id])
            ->set('prices.'.$item->id, '')
            ->call('savePrice', $item->id);

        $this->assertNull($company->items()->first()->pivot->price);
    }

    public function test_company_item_can_be_removed(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->create();
        $company->items()->attach($item);

        Livewire::test('crm.company-items', ['companyId' => $company->id])->call('remove', $item->id);

        $this->assertSame(0, $company->items()->count());
        $this->assertModelExists($item);
    }
}
