<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingPayment>
 */
class BillingPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_customer_id'      => BillingCustomer::factory(),
            'billing_invoice_id'       => null,
            'stripe_payment_intent_id' => null,
            'status'                   => 'succeeded',
            'amount_cents'             => 4900,
            'currency'                 => 'USD',
            'paid_at'                  => now(),
            'metadata'                 => null,
        ];
    }

    public function withStripe(?string $id = null): static
    {
        return $this->state(fn () => ['stripe_payment_intent_id' => $id ?? 'pi_'.fake()->unique()->bothify('############')]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'paid_at' => null]);
    }
}
