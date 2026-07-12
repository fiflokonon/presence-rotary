<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@rcc-ife.test')],
            [
                'name' => 'Admin',
                'password' => bcrypt(env('ADMIN_PASSWORD', 'password')),
            ]
        );
    }
}
