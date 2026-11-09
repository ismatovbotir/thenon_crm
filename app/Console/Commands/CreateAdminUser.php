<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('crm:create-user {email : Login email} {--name= : Display name} {--role=admin : admin or operator} {--password= : Avoid on shared servers; omit to be prompted}')]
#[Description('Create or update a CRM user (use this on production instead of the demo seeder)')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $role = UserRole::tryFrom((string) $this->option('role'));

        if (! $role) {
            $this->error('Role must be "admin" or "operator".');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 10 characters)'));

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:10']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->option('name') ?: ucfirst($role->value),
                'password' => $password,
                'role' => $role,
                'is_active' => true,
            ],
        );

        $this->info("User {$email} saved as {$role->value}.");

        return self::SUCCESS;
    }
}
