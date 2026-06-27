<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingCustomer>
 */
class BillingCustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id'    => null,
            'user_id'            => null,
            'stripe_customer_id' => null,
            'name'               => fake()->company(),
            'email'              => fake()->unique()->companyEmail(),
            'status'             => 'active',
            'metadata'           => null,
        ];
    }

    public function withStripe(?string $id = null): static
    {
        return $this->state(fn () => ['stripe_customer_id' => $id ?? 'cus_'.fake()->unique()->bothify('############')]);
    }

    public function forOrganization(Organization $org): static
    {
        return $this->state(fn () => ['organization_id' => $org->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
