<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) (env('SUPERADMIN_EMAIL') ?: 'superadmin@sass-agent.local');
        $password = (string) (env('SUPERADMIN_PASSWORD') ?: 'Superadmin123!');
        $name = (string) (env('SUPERADMIN_NAME') ?: 'Superadmin');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => UserRole::Superadmin,
                'email_verified_at' => now(),
            ],
        );
    }
}
