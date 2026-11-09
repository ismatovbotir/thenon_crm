<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Never create well-known demo accounts on a production server: use `php artisan crm:create-user`.
        if (app()->isProduction()) {
            return;
        }

        foreach (config('crm.demo_users') as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => config('crm.seed_password'),
                    'role' => UserRole::from($account['role']),
                    'is_active' => true,
                ],
            );
        }

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
