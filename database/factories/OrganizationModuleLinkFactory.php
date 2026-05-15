<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrganizationModuleLink>
 */
class OrganizationModuleLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id'    => Organization::factory(),
            'module_key'         => fake()->randomElement(['glassbilling', 'glasspanel', 'dns', 'mail', 'support', 'infrastructure']),
            'display_name'       => fake()->words(2, true),
            'external_account_id' => null,
            'external_url'       => null,
            'auth_mode'          => 'standalone',
            'status'             => 'active',
            'last_seen_at'       => null,
            'metadata'           => null,
        ];
    }

    public function forModule(string $key, string $displayName = ''): static
    {
        return $this->state([
            'module_key'   => $key,
            'display_name' => $displayName ?: ucfirst($key),
        ]);
    }

    public function withLaunchUrl(string $url = 'https://module.example.test'): static
    {
        return $this->state(['external_url' => $url, 'auth_mode' => 'standalone']);
    }

    public function ssoMode(string $mode = 'signed_launch'): static
    {
        return $this->state(['auth_mode' => $mode, 'external_url' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
