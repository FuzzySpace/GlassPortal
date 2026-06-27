<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use App\Models\ProvisioningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProvisioningRequest>
 */
class ProvisioningRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request_key'                    => 'preq-'.Str::lower(Str::random(12)),
            'billing_customer_id'            => BillingCustomer::factory(),
            'module_key'                     => null,
            'product_key'                    => null,
            'service_type'                   => fake()->randomElement(['hosting', 'ai_sales', 'dns', 'mail']),
            'driver_key'                     => 'manual',
            'requested_action'               => ProvisioningRequest::ACTION_PROVISION,
            'status'                         => ProvisioningRequest::STATUS_PENDING_APPROVAL,
            'priority'                       => 'normal',
            'requires_approval'              => true,
            'idempotency_key'                => null,
            'payload'                        => ['plan' => 'pro'],
            'result'                         => null,
            'metadata'                       => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function action(string $action): static
    {
        return $this->state(fn () => ['requested_action' => $action]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ProvisioningRequest::STATUS_APPROVED, 'approved_at' => now()]);
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => ProvisioningRequest::STATUS_RUNNING, 'started_at' => now()]);
    }
}
