<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingInvoice>
 */
class BillingInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_customer_id' => BillingCustomer::factory(),
            'stripe_invoice_id'   => null,
            'status'              => 'open',
            'amount_due_cents'    => 4900,
            'amount_paid_cents'   => 0,
            'currency'            => 'USD',
            'due_at'              => now()->addWeek(),
            'paid_at'             => null,
            'metadata'            => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attrs) => [
            'status'            => 'paid',
            'amount_paid_cents' => $attrs['amount_due_cents'] ?? 4900,
            'paid_at'           => now(),
        ]);
    }
}
