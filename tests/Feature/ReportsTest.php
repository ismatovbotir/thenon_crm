<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Item;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Reports\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Item $bun;

    private Item $baguette;

    private Location $first;

    private Location $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bun = Item::factory()->create(['code' => '00000101', 'name_ru' => 'Булочка', 'name_uz' => 'Bulochka']);
        $this->baguette = Item::factory()->create(['code' => '00000102', 'name_ru' => 'Багет', 'name_uz' => 'Baget']);

        $company = Company::factory()->create(['name' => 'Alpha']);
        $this->first = Location::factory()->for($company)->create(['name' => 'Chilonzor']);
        $this->second = Location::factory()->create(['name' => 'Yunusobod']);

        $this->makeOrder($this->first, today(), OrderStatus::Completed, [[$this->bun, 40], [$this->baguette, 10]]);
        $this->makeOrder($this->second, today(), OrderStatus::Sent, [[$this->bun, 60]]);
        $this->makeOrder($this->second, today()->subDay(), OrderStatus::Completed, [[$this->baguette, 5]]);
    }

    /**
     * @param  list<array{0: Item, 1: int}>  $lines
     */
    private function makeOrder(Location $location, $date, OrderStatus $status, array $lines): Order
    {
        $order = Order::factory()->for($location)->create(['order_date' => $date, 'status' => $status]);

        foreach ($lines as [$item, $quantity]) {
            OrderItem::factory()->for($order)->create(['item_id' => $item->id, 'quantity' => $quantity]);
        }

        return $order;
    }

    public function test_guests_cannot_open_reports_or_export(): void
    {
        $this->get(route('crm.reports'))->assertRedirect(route('crm.login'));
        $this->get(route('crm.reports.export', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertRedirect(route('crm.login'));
    }

    public function test_production_summary_adds_quantities_across_locations(): void
    {
        $this->actingAs(User::factory()->create());

        $rows = Livewire::test('pages::crm.reports')->get('production');

        $this->assertSame(100, $rows->firstWhere('code', '00000101')['quantity']);
        $this->assertSame(2, $rows->firstWhere('code', '00000101')['locations']);
        $this->assertSame(10, $rows->firstWhere('code', '00000102')['quantity']);
    }

    public function test_drafts_are_not_counted(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeOrder(Location::factory()->create(), today(), OrderStatus::Draft, [[$this->bun, 999]]);

        $rows = Livewire::test('pages::crm.reports')->get('production');

        $this->assertSame(100, $rows->firstWhere('code', '00000101')['quantity']);
    }

    public function test_date_range_and_company_filter(): void
    {
        $this->actingAs(User::factory()->create());

        $range = Livewire::test('pages::crm.reports')->set('from', today()->subDay()->toDateString());
        $this->assertSame(15, $range->get('production')->firstWhere('code', '00000102')['quantity']);

        $company = Livewire::test('pages::crm.reports')->set('companyId', (string) $this->first->company_id);
        $this->assertSame(40, $company->get('production')->firstWhere('code', '00000101')['quantity']);
    }

    public function test_reversed_range_shows_an_error_instead_of_data(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::crm.reports')
            ->set('from', today()->toDateString())
            ->set('to', today()->subDays(3)->toDateString())
            ->assertSee(__('crm.reports.invalid_range'));
    }

    public function test_excel_export_is_a_valid_workbook_with_both_sheets(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('crm.reports.export', ['from' => today()->toDateString(), 'to' => today()->toDateString()]));

        $response->assertOk();
        $this->assertStringEndsWith('.xlsx', (string) $response->headers->get('content-disposition'));

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2 = $zip->getFromName('xl/worksheets/sheet2.xml');
        $this->assertNotFalse(simplexml_load_string($sheet1));
        $this->assertNotFalse(simplexml_load_string($zip->getFromName('xl/workbook.xml')));

        // 1C codes keep their leading zeros because they are written as text.
        $this->assertStringContainsString('<t xml:space="preserve">00000101</t>', $sheet1);
        $this->assertStringContainsString('Булочка', $sheet1);
        $this->assertStringContainsString('Chilonzor', $sheet2);
        $this->assertStringContainsString('<v>100</v>', $sheet1);
        $zip->close();
    }

    public function test_export_validates_the_range(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('crm.reports.export', ['from' => '2026-11-10', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->get(route('crm.reports.export'))->assertSessionHasErrors('from');
    }

    public function test_xlsx_writer_escapes_markup_and_long_sheet_names(): void
    {
        $path = (new XlsxWriter)
            ->addSheet('A/very*long:name[that]exceeds?the thirty-one char limit', [['H'], ['<b>&"x"</b>', 1.5]])
            ->build();

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertNotFalse(simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml')));
        $this->assertStringContainsString('&lt;b&gt;', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertNotFalse(simplexml_load_string($zip->getFromName('xl/workbook.xml')));
        $zip->close();
        unlink($path);
    }
}
