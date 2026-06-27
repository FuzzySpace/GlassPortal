<?php

namespace Database\Factories;

use App\Models\BillingProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingPlan>
 */
class BillingPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_product_id' => BillingProduct::factory(),
            'plan_key'           => 'plan-'.Str::lower(Str::random(6)),
            'stripe_price_id'    => null,
            'name'               => fake()->randomElement(['Starter', 'Pro', 'Business']),
            'amount_cents'       => fake()->randomElement([1000, 2500, 4900, 9900]),
            'currency'           => 'USD',
            'interval'           => 'month',
            'status'             => 'active',
            'metadata'           => null,
        ];
    }

    public function withStripePrice(?string $id = null): static
    {
        return $this->state(fn () => ['stripe_price_id' => $id ?? 'price_'.fake()->unique()->bothify('############')]);
    }
}
