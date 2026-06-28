<?php

namespace Database\Factories;

use App\Models\BillingChangeRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BillingChangeRequest>
 */
class BillingChangeRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request_key'      => 'bcr_' . Str::lower(Str::random(16)),
            'organization_id'  => Organization::factory(),
            'user_id'          => User::factory(),
            'request_type'     => BillingChangeRequest::TYPE_BILLING_SUPPORT,
            'status'           => BillingChangeRequest::STATUS_SUBMITTED,
            'reason'           => null,
            'customer_message' => fake()->sentence(),
            'requested_at'     => now(),
            'metadata'         => null,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['request_type' => $type]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function underReview(): static
    {
        return $this->state(fn () => [
            'status'      => BillingChangeRequest::STATUS_UNDER_REVIEW,
            'reviewed_at' => now(),
        ]);
    }

    public function forOrganization(Organization $org): static
    {
        return $this->state(fn () => ['organization_id' => $org->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
