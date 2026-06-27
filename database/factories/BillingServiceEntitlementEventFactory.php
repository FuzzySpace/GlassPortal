<?php

namespace Database\Factories;

use App\Models\BillingServiceEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingServiceEntitlementEvent>
 */
class BillingServiceEntitlementEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_service_entitlement_id' => BillingServiceEntitlement::factory(),
            'event_type'                     => 'status_changed',
            'previous_status'                => BillingServiceEntitlement::STATUS_PENDING,
            'new_status'                     => BillingServiceEntitlement::STATUS_ACTIVE,
            'actor_type'                     => null,
            'actor_id'                       => null,
            'reason'                         => null,
            'metadata'                       => null,
        ];
    }
}
