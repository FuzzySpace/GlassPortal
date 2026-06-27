<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingProduct>
 */
class BillingProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'product_key'             => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name'                    => $name,
            'description'             => fake()->sentence(),
            'status'                  => 'active',
            'public_catalog_entry_id' => null,
            'metadata'                => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
