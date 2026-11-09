<?php

namespace Database\Seeders;

use App\Enums\ItemUnit;
use App\Enums\Language;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Location;
use App\Models\LocationCutoff;
use App\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    private int $phoneCounter = 0;

    /**
     * Seed a sample catalog, several customers and ten days of orders for local development.
     */
    public function run(): void
    {
        mt_srand(2026);

        // One transaction: SQLite syncs to disk per statement otherwise, which makes seeding very slow.
        DB::transaction(function () {
            $items = $this->seedCatalog();

            foreach ($this->customers() as $customer) {
                $this->seedCustomer($customer, $items);
            }

            $this->seedOrders(10);
        });
    }

    /**
     * @return Collection<int, Item>
     */
    private function seedCatalog(): Collection
    {
        $catalog = [
            ['00000101', 'Burger bulochkasi', 'Булочка для бургера', 'pcs', 10, 'Bulochka', 1800],
            ['00000102', 'Kunjutli burger bulochkasi', 'Булочка для бургера с кунжутом', 'pcs', 10, 'Bulochka', 2100],
            ['00000103', 'Xot-dog bulochkasi', 'Булочка для хот-дога', 'pcs', 10, 'Bulochka', 1500],
            ['00000104', 'Mini burger bulochkasi', 'Булочка мини-бургер', 'pack', 1, 'Bulochka', 14000],
            ['00000105', 'Baget', 'Багет', 'pcs', 1, 'Non', 4500],
            ['00000106', 'Chiabatta', 'Чиабатта', 'pcs', 5, 'Non', 5200],
            ['00000107', 'Lavash', 'Лаваш', 'pack', 1, 'Non', null],
            ['00000108', 'Kruassan', 'Круассан', 'pcs', 10, 'Qandolat', 6000],
            ['00000109', 'Brioshe', 'Бриошь', 'pcs', 6, 'Qandolat', null],
            ['00000110', 'Tost noni', 'Тостовый хлеб', 'pack', 1, 'Non', 9500],
            ['00000111', 'Pitsa asosi', 'Основа для пиццы', 'pcs', 10, 'Pitsa', 3200],
            ['00000112', 'Tandir non', 'Тандырная лепёшка', 'pcs', 10, 'Non', 3800],
            ['00000113', 'Shaurma noni', 'Хлеб для шаурмы', 'pack', 1, 'Non', 12000],
            ['00000114', 'Fokachcha', 'Фокачча', 'pcs', 5, 'Pitsa', 4800],
        ];

        return collect($catalog)->map(function (array $row) {
            $item = Item::query()->updateOrCreate(['code' => $row[0]], [
                'name_uz' => $row[1],
                'name_ru' => $row[2],
                'unit' => ItemUnit::from($row[3]),
                'step' => $row[4],
                'category' => $row[5],
                'is_active' => true,
            ]);

            $item->setAttribute('demo_price', $row[6]);

            return $item;
        });
    }

    /**
     * @return list<array{name: string, notes: string, categories: list<string>, closed_sunday: bool, locations: list<string>, employees: list<string>}>
     */
    private function customers(): array
    {
        return [
            [
                'name' => 'Demo Burger', 'notes' => 'Demo fast-food chain for local development',
                'categories' => ['Bulochka', 'Non', 'Qandolat'], 'closed_sunday' => false,
                'locations' => ['Chilonzor', 'Yunusobod'],
                'employees' => ['Aziz Karimov', 'Dilnoza Rustamova', 'Jasur Toshmatov'],
            ],
            [
                'name' => 'Burger House', 'notes' => 'Burger chain, 4 branches',
                'categories' => ['Bulochka', 'Qandolat'], 'closed_sunday' => false,
                'locations' => ['Amir Temur', 'Mustaqillik', 'Beruniy', 'Sergeli'],
                'employees' => ['Bobur Aliyev', 'Madina Yusupova', 'Sardor Nazarov', 'Nodira Ergasheva', 'Otabek Rahimov'],
            ],
            [
                'name' => 'Doner Express', 'notes' => 'Doner and shawarma kiosks',
                'categories' => ['Non'], 'closed_sunday' => true,
                'locations' => ['Chorsu', 'Tinchlik', 'Olmazor'],
                'employees' => ['Farruh Mirzayev', 'Gulnora Saidova', 'Timur Abdullayev', 'Zarina Hasanova'],
            ],
            [
                'name' => 'Pizza Mia', 'notes' => 'Pizzeria network',
                'categories' => ['Pitsa', 'Non', 'Qandolat'], 'closed_sunday' => false,
                'locations' => ['Mirobod', 'Yashnobod'],
                'employees' => ['Eldor Tursunov', 'Shahlo Karimova', 'Ulugbek Normatov'],
            ],
            [
                'name' => 'Hot Dog Point', 'notes' => 'Street food, small branches',
                'categories' => ['Bulochka', 'Non'], 'closed_sunday' => true,
                'locations' => ['Magic City', 'Next Mall', 'Compass'],
                'employees' => ['Rustam Ismoilov', 'Kamola Berdiyeva', 'Anvar Qodirov', 'Lola Mahmudova'],
            ],
        ];
    }

    /**
     * @param  array{name: string, notes: string, categories: list<string>, closed_sunday: bool, locations: list<string>, employees: list<string>}  $customer
     * @param  Collection<int, Item>  $items
     */
    private function seedCustomer(array $customer, Collection $items): void
    {
        $company = Company::query()->firstOrCreate(
            ['name' => $customer['name']],
            ['notes' => $customer['notes'], 'is_active' => true],
        );

        $assigned = $items->filter(fn (Item $item) => in_array($item->category, $customer['categories'], true));

        $company->items()->syncWithoutDetaching(
            $assigned->mapWithKeys(fn (Item $item) => [
                $item->id => ['price' => $item->demo_price !== null ? (int) round($item->demo_price * (0.95 + mt_rand(0, 10) / 100), -2) : null],
            ])->all(),
        );

        $locations = collect($customer['locations'])->map(function (string $name) use ($company, $customer) {
            $location = $company->locations()->firstOrCreate(['name' => $name], ['address' => $name.', Toshkent', 'is_active' => true]);

            foreach (range(1, 7) as $weekday) {
                $closed = $customer['closed_sunday'] && $weekday === 7;

                LocationCutoff::query()->updateOrCreate(
                    ['location_id' => $location->id, 'weekday' => $weekday],
                    ['is_open' => ! $closed, 'cutoff_time' => $closed ? null : ($weekday === 6 ? '16:00' : '18:00')],
                );
            }

            return $location;
        })->values();

        $employees = collect($customer['employees'])->values()->map(function (string $name, int $index) use ($company) {
            $phone = $name === 'Aziz Karimov' ? '+998901112233' : ($name === 'Dilnoza Rustamova' ? '+998909998877' : ($name === 'Jasur Toshmatov' ? '+998935556677' : $this->nextPhone()));
            $registered = $index % 3 !== 2;

            $employee = Employee::query()->firstOrCreate(['phone' => $phone], [
                'company_id' => $company->id,
                'name' => $name,
                'is_active' => true,
            ]);

            if ($registered && ! $employee->telegram_id && ! in_array($phone, ['+998901112233', '+998909998877', '+998935556677'], true)) {
                $employee->forceFill([
                    'telegram_id' => 7_000_000 + $employee->id,
                    'language' => $index % 2 === 0 ? Language::Uz : Language::Ru,
                    'registered_at' => now()->subDays(mt_rand(3, 30)),
                ])->save();
            }

            return $employee;
        });

        // Every employee works at one location; some cover two, which exercises the location switcher.
        foreach ($employees as $index => $employee) {
            $ids = [$locations[$index % $locations->count()]->id];

            if ($index % 4 === 1 && $locations->count() > 1) {
                $ids[] = $locations[($index + 1) % $locations->count()]->id;
            }

            $employee->locations()->syncWithoutDetaching($ids);
        }

        foreach ($locations as $location) {
            $default = $location->employees()->where('is_active', true)->orderBy('employees.id')->first();
            $location->update(['default_employee_id' => $default?->id]);
        }

        // One deactivated employee per large company, to show the lockout in the CRM.
        if ($employees->count() >= 5) {
            $employees->last()->update(['is_active' => false]);
        }
    }

    private function nextPhone(): string
    {
        $this->phoneCounter++;

        return '+99890'.str_pad((string) (2_000_000 + $this->phoneCounter * 137), 7, '0', STR_PAD_LEFT);
    }

    private function seedOrders(int $days): void
    {
        $locations = Location::query()->with('company.items', 'cutoffs', 'employees')->get();

        foreach (range($days - 1, 0) as $ago) {
            $date = today()->subDays($ago);

            foreach ($locations as $location) {
                $rule = $location->cutoffs->firstWhere('weekday', $date->dayOfWeekIso);

                if (($rule && ! $rule->is_open) || mt_rand(1, 100) > 88) {
                    continue;
                }

                if (Order::query()->where('location_id', $location->id)->whereDate('order_date', $date)->exists()) {
                    continue;
                }

                $products = $location->company->items->where('is_active', true)->shuffle();

                if ($products->isEmpty()) {
                    continue;
                }

                $employee = $location->employees->where('is_active', true)->random();
                $status = $this->statusFor($ago);
                $placedAt = $date->copy()->setTime(mt_rand(7, 16), mt_rand(0, 59));

                $order = new Order([
                    'location_id' => $location->id,
                    'employee_id' => $employee->id,
                    'order_date' => $date,
                    'status' => $status,
                    'completed_at' => $status === OrderStatus::Draft ? null : $placedAt,
                    'delivered_at' => $status === OrderStatus::Delivered ? $date->copy()->addDay()->setTime(mt_rand(6, 10), 0) : null,
                ]);
                $order->created_at = $placedAt;
                $order->updated_at = $placedAt;
                $order->saveQuietly();

                foreach ($products->take(mt_rand(3, min(8, $products->count()))) as $product) {
                    $order->lines()->create([
                        'item_id' => $product->id,
                        'quantity' => $product->step * mt_rand(1, $product->unit === ItemUnit::Pack ? 6 : 12),
                        'price' => $product->pivot->price,
                    ]);
                }
            }
        }
    }

    private function statusFor(int $daysAgo): OrderStatus
    {
        return match (true) {
            $daysAgo >= 3 => OrderStatus::Delivered,
            $daysAgo === 2 => mt_rand(1, 10) > 2 ? OrderStatus::Delivered : OrderStatus::Sent,
            $daysAgo === 1 => collect([OrderStatus::Sent, OrderStatus::Sent, OrderStatus::Done, OrderStatus::Delivered])->random(),
            default => collect([OrderStatus::Completed, OrderStatus::Completed, OrderStatus::Accepted, OrderStatus::Done, OrderStatus::Draft])->random(),
        };
    }
}
