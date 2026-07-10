<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => env('ADMIN_EMAIL', 'admin@rotarynexus.test'),
            'password' => bcrypt(env('ADMIN_PASSWORD', 'password')),
        ]);
    }
}
