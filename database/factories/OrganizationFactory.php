<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name'                     => $name,
            'slug'                     => Str::slug($name) . '-' . Str::random(4),
            'billing_email'            => fake()->companyEmail(),
            'status'                   => 'active',
            'glassbilling_customer_id' => null,
            'metadata'                 => null,
        ];
    }

    public function withGlassBillingId(string $id = 'gb_cust_test123'): static
    {
        return $this->state(['glassbilling_customer_id' => $id]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
