<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->company());
        $directory = storage_path('framework/testing/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        return [
            'name' => fake()->company(),
            'host' => "{$slug}.example.test",
            'sqlite_path' => $directory.'/'.Str::uuid().'.sqlite',
        ];
    }
}
