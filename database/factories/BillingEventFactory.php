<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingEvent>
 */
class BillingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_type'        => fake()->randomElement([
                'invoice.paid',
                'invoice.payment_failed',
                'customer.subscription.updated',
                'payment_intent.succeeded',
            ]),
            'provider'          => 'stripe',
            'provider_event_id' => 'evt_'.fake()->unique()->bothify('################'),
            'related_type'      => null,
            'related_id'        => null,
            'payload'           => ['id' => 'evt_test', 'object' => 'event'],
            'processed_at'      => null,
            'status'            => 'pending',
            'error_message'     => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['status' => 'processed', 'processed_at' => now()]);
    }
}
