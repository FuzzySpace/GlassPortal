<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use App\Models\BillingServiceEntitlement;
use App\Models\BillingSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingServiceEntitlement>
 */
class BillingServiceEntitlementFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement(['Managed Hosting', 'AI Sales (SIONA)', 'Game Server', 'DNS Zone', 'Mailbox']);

        return [
            'billing_customer_id'  => BillingCustomer::factory(),
            'entitlement_key'      => 'ent-'.Str::lower(Str::random(10)),
            'service_type'         => fake()->randomElement(['hosting', 'ai_sales', 'game_server', 'dns', 'mail']),
            'module_key'           => null,
            'product_key'          => null,
            'name'                 => $name,
            'description'          => fake()->sentence(),
            'status'               => BillingServiceEntitlement::STATUS_ACTIVE,
            'quantity'             => 1,
            'starts_at'            => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end'   => now()->addMonth(),
            'trial_ends_at'        => null,
            'suspended_at'         => null,
            'cancelled_at'         => null,
            'terminated_at'        => null,
            'metadata'             => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status'    => BillingServiceEntitlement::STATUS_PENDING,
            'starts_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status'       => BillingServiceEntitlement::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function forSubscription(BillingSubscription $subscription): static
    {
        return $this->state(fn () => [
            'billing_customer_id'     => $subscription->billing_customer_id,
            'billing_subscription_id' => $subscription->id,
            'billing_plan_id'         => $subscription->billing_plan_id,
        ]);
    }
}
