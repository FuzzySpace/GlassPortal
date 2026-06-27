<?php

namespace Database\Factories;

use App\Models\ProvisioningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProvisioningRequestEvent>
 */
class ProvisioningRequestEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provisioning_request_id' => ProvisioningRequest::factory(),
            'event_type'              => 'status_changed',
            'previous_status'         => ProvisioningRequest::STATUS_PENDING_APPROVAL,
            'new_status'              => ProvisioningRequest::STATUS_APPROVED,
            'actor_type'              => null,
            'actor_id'                => null,
            'message'                 => null,
            'metadata'                => null,
        ];
    }
}
