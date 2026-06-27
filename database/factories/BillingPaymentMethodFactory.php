<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingPaymentMethod>
 */
class BillingPaymentMethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_customer_id'      => BillingCustomer::factory(),
            'stripe_payment_method_id' => null,
            'type'                     => 'card',
            'brand'                    => fake()->randomElement(['visa', 'mastercard', 'amex']),
            'last4'                    => (string) fake()->numberBetween(1000, 9999),
            'exp_month'                => fake()->numberBetween(1, 12),
            'exp_year'                 => 2030,
            'is_default'               => false,
            'metadata'                 => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
