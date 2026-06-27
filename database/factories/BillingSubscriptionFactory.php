<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use App\Models\BillingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingSubscription>
 */
class BillingSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_customer_id'    => BillingCustomer::factory(),
            'billing_plan_id'        => BillingPlan::factory(),
            'stripe_subscription_id' => null,
            'status'                 => 'active',
            'current_period_start'   => now()->subDays(1),
            'current_period_end'     => now()->addMonth(),
            'cancel_at_period_end'   => false,
            'metadata'               => null,
        ];
    }

    public function withStripe(?string $id = null): static
    {
        return $this->state(fn () => ['stripe_subscription_id' => $id ?? 'sub_'.fake()->unique()->bothify('############')]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => ['status' => 'canceled', 'cancel_at_period_end' => true]);
    }
}
