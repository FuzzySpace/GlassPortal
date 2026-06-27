<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A single GlassSite public catalog entry (Phase 22).
 *
 * Represents one intentionally-published product/service/module marketing card.
 * Only the explicit, safe columns on this model are ever shown publicly —
 * `metadata` is admin-authored and is deliberately excluded from
 * {@see self::toPublicArray()} so a stray secret can never leak to the public
 * site.
 */
class PublicProductCatalogEntry extends Model
{
    use HasFactory, SoftDeletes;

    /** Columns that are safe to expose on public (unauthenticated) pages. */
    public const PUBLIC_FIELDS = [
        'title', 'slug', 'short_description', 'description', 'category',
        'product_key', 'module_key', 'starting_price_cents', 'currency',
        'billing_interval', 'cta_label', 'cta_url', 'docs_url', 'support_url',
        'status_url', 'icon', 'featured',
    ];

    protected $fillable = [
        'title',
        'slug',
        'short_description',
        'description',
        'category',
        'product_key',
        'module_key',
        'starting_price_cents',
        'currency',
        'billing_interval',
        'cta_label',
        'cta_url',
        'docs_url',
        'support_url',
        'status_url',
        'icon',
        'featured',
        'is_public',
        'sort_order',
        'published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starting_price_cents' => 'integer',
            'featured'             => 'boolean',
            'is_public'            => 'boolean',
            'sort_order'           => 'integer',
            'published_at'         => 'datetime',
            'metadata'             => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Normalize the slug from the title (or the provided slug) on every save,
        // matching the Str::slug convention used elsewhere (e.g. Organization).
        static::saving(function (self $entry): void {
            $source = filled($entry->slug) ? $entry->slug : $entry->title;
            if (filled($source)) {
                $entry->slug = Str::slug($source);
            }

            if (blank($entry->currency)) {
                $entry->currency = 'USD';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Scopes

    /** Only entries that are public and published at or before now. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** Featured first, then by sort_order, then alphabetically — stable order. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    // -------------------------------------------------------------------------
    // Helpers

    public function isPublished(): bool
    {
        return $this->is_public
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    /**
     * Human-friendly "starting price" label, or null when no price is set.
     * Display-only — never a billing source of truth.
     */
    public function priceLabel(): ?string
    {
        if ($this->starting_price_cents === null) {
            return null;
        }

        $currency = $this->currency ?: 'USD';
        $amount   = number_format($this->starting_price_cents / 100, 2);
        $price    = $currency === 'USD' ? "\${$amount}" : "{$amount} {$currency}";

        $suffix = match ($this->billing_interval) {
            'monthly'  => '/mo',
            'yearly'   => '/yr',
            'one_time' => ' one-time',
            null, ''   => '',
            default    => " /{$this->billing_interval}",
        };

        return "from {$price}{$suffix}";
    }

    /**
     * Safe, allow-listed representation for public rendering. NEVER includes
     * `metadata`, `is_public`, timestamps, ids, or any non-public column.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $data = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            $data[$field] = $this->{$field};
        }
        $data['price_label'] = $this->priceLabel();

        return $data;
    }

    /**
     * Featured, published entries for the public homepage. Fails safe to an
     * empty collection if the table is not migrated yet.
     *
     * @return Collection<int, self>
     */
    public static function featuredForHomepage(int $limit = 3): Collection
    {
        try {
            return static::query()->published()->featured()->ordered()->limit($limit)->get();
        } catch (\Throwable) {
            return new Collection();
        }
    }
}
