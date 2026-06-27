<?php

namespace Database\Factories;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingCheckoutSession>
 */
class BillingCheckoutSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_customer_id'  => BillingCustomer::factory(),
            'provider'             => 'stripe',
            'provider_session_id'  => 'cs_test_'.fake()->unique()->bothify('################'),
            'mode'                 => 'subscription',
            'status'               => BillingCheckoutSession::STATUS_OPEN,
            'payment_status'       => 'unpaid',
            'currency'             => 'USD',
            'amount_total'         => 4900,
            'success_url'          => 'https://portal.example/billing/success',
            'cancel_url'           => 'https://portal.example/billing/cancel',
            'expires_at'           => now()->addDay(),
            'payload'              => ['object' => 'checkout.session'],
            'metadata'             => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'         => BillingCheckoutSession::STATUS_COMPLETE,
            'payment_status' => 'paid',
            'completed_at'   => now(),
        ]);
    }
}
