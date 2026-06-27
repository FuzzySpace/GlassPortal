<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PublicProductCatalogEntry>
 */
class PublicProductCatalogEntryFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'title'                => $title,
            'slug'                 => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'short_description'    => fake()->sentence(),
            'description'          => fake()->paragraphs(2, true),
            'category'             => fake()->randomElement(['Hosting', 'AI', 'Infrastructure', 'Domains']),
            'product_key'          => null,
            'module_key'           => null,
            'starting_price_cents' => fake()->randomElement([null, 500, 1500, 4900]),
            'currency'             => 'USD',
            'billing_interval'     => 'monthly',
            'cta_label'            => 'Get started',
            'cta_url'              => 'https://glasshouse.example/signup',
            'docs_url'             => null,
            'support_url'          => null,
            'status_url'           => null,
            'icon'                 => fake()->randomElement(['◆', '◉', '⊞', '▶']),
            'featured'             => false,
            'is_public'            => false,
            'sort_order'           => 0,
            'published_at'         => null,
            'metadata'             => null,
        ];
    }

    /** Publicly visible: is_public + published in the past. */
    public function published(): static
    {
        return $this->state(fn () => [
            'is_public'    => true,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Explicitly not visible to the public. */
    public function unpublished(): static
    {
        return $this->state(fn () => [
            'is_public'    => false,
            'published_at' => null,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['featured' => true]);
    }
}
