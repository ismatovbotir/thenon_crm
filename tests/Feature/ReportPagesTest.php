<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use App\Models\User;
use App\Services\Reports\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'Asia/Tashkent'));
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function order(Location $location, string $date, int $quantity, ?int $price, OrderStatus $status = OrderStatus::Completed, ?Item $item = null): Order
    {
        $order = Order::factory()->for($location)->create(['order_date' => $date, 'status' => $status]);
        $order->lines()->create(['item_id' => ($item ?? Item::factory()->create())->id, 'quantity' => $quantity, 'price' => $price]);

        return $order;
    }

    private function report(string $from = '2026-11-01', string $to = '2026-11-09', ?int $company = null): SalesReport
    {
        return new SalesReport(Carbon::parse($from), Carbon::parse($to), $company);
    }

    public function test_the_menu_has_a_reports_group_with_every_report(): void
    {
        $this->get(route('crm.dashboard'))
            ->assertSee(route('crm.reports'))
            ->assertSee(route('crm.reports.sales'))
            ->assertSee(route('crm.reports.daily'))
            ->assertSee(route('crm.reports.compare'))
            ->assertSee(route('crm.reports.missing'))
            ->assertSee(route('crm.orders.index'));
    }

    public function test_sales_are_totalled_by_company_location_and_item_without_drafts(): void
    {
        $alpha = Company::factory()->create(['name' => 'Alpha']);
        $a1 = Location::factory()->for($alpha)->create(['name' => 'A1']);
        $b1 = Location::factory()->for(Company::factory()->create(['name' => 'Beta']))->create(['name' => 'B1']);
        $bread = Item::factory()->create(['name_ru' => 'Хлеб']);
        $this->order($a1, '2026-11-03', 10, 1000, item: $bread);
        $this->order($a1, '2026-11-04', 5, null, item: $bread);
        $this->order($b1, '2026-11-05', 2, 500);
        $this->order($b1, '2026-11-06', 99, 500, OrderStatus::Draft);
        $this->order($b1, '2026-10-20', 7, 500);

        $report = $this->report();

        $this->assertSame(['orders' => 3, 'positions' => 3, 'quantity' => 17, 'amount' => 11000.0, 'unpriced' => 1], $report->totals());
        $this->assertSame(['Alpha', 'Beta'], $report->byCompany()->pluck('name')->all());
        $this->assertSame(15, $report->byCompany()->first()['quantity']);
        $this->assertSame(['A1', 'B1'], $report->byLocation()->pluck('name')->all());
        $this->assertSame(15, $report->byItem()->firstWhere('name_ru', 'Хлеб')['quantity']);
        $this->assertSame(['Alpha'], $this->report(company: $alpha->id)->byCompany()->pluck('name')->all());
    }

    public function test_daily_rows_are_ordered_by_date(): void
    {
        $location = Location::factory()->create();
        $this->order($location, '2026-11-05', 1, 100);
        $this->order($location, '2026-11-03', 2, 100);

        $this->assertSame(['2026-11-03', '2026-11-05'], $this->report()->byDay()->pluck('date')->all());
    }

    public function test_missing_orders_skip_ordered_and_closed_days(): void
    {
        $location = Location::factory()->for(Company::factory()->create(['name' => 'Alpha']))->create(['name' => 'A1']);
        LocationCutoff::factory()->for($location)->create(['weekday' => 2, 'is_open' => false, 'cutoff_time' => null]);
        $this->order($location, '2026-11-02', 1, 100);

        $rows = $this->report('2026-11-02', '2026-11-04')->missing();

        $this->assertSame(['2026-11-04'], $rows->first()['dates']);
        $this->assertSame(2, $rows->first()['expected']);
        $this->assertSame(1, $rows->first()['ordered']);
        $this->assertSame(1, $rows->first()['missed']);
        $this->assertCount(0, $this->report('2026-01-01', '2026-11-09')->missing());
    }

    public function test_every_report_page_opens_and_shows_its_data(): void
    {
        $location = Location::factory()->for(Company::factory()->create(['name' => 'Alpha']))->create(['name' => 'Chilonzor']);
        $this->order($location, '2026-11-03', 10, 1000);

        foreach (['sales' => 'Alpha', 'daily' => '10 000', 'compare' => 'Alpha', 'missing' => 'Chilonzor'] as $page => $text) {
            $this->get(route('crm.reports.'.$page, ['from' => '2026-11-01', 'to' => '2026-11-09']))->assertOk()->assertSee($text);
        }
    }

    public function test_the_compare_page_shows_the_change_against_the_previous_period(): void
    {
        $location = Location::factory()->for(Company::factory()->create(['name' => 'Alpha']))->create();
        $this->order($location, '2026-11-03', 10, 1000);
        $this->order($location, '2026-10-28', 5, 1000);

        Livewire::test('pages::crm.reports.compare')
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-07')
            ->assertSee('+100%')
            ->assertSee('25.10.2026');
    }

    public function test_an_invalid_period_shows_an_error_instead_of_data(): void
    {
        Livewire::test('pages::crm.reports.sales')
            ->set('from', '2026-11-09')
            ->set('to', '2026-11-01')
            ->assertSee(__('crm.reports.invalid_range', [], 'ru'));
    }

    public function test_a_report_can_be_downloaded_as_excel(): void
    {
        $location = Location::factory()->create();
        $this->order($location, '2026-11-03', 10, 1000);

        Livewire::test('pages::crm.reports.sales')
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-09')
            ->call('export')
            ->assertFileDownloaded('the-non-sales-2026-11-01_2026-11-09.xlsx');
    }

    public function test_the_company_filter_is_a_searchable_picker_listing_every_company(): void
    {
        Company::factory()->create(['name' => 'Alpha Bakery']);
        Company::factory()->create(['name' => 'Zeta Market']);

        foreach (['crm.reports', 'crm.reports.sales', 'crm.reports.daily', 'crm.reports.compare'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('type="search"', $html, $route);
            $this->assertStringContainsString('Alpha Bakery', $html, $route);
            $this->assertStringContainsString('Zeta Market', $html, $route);
        }
    }
}
