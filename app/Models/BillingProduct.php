<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable billing product (Phase 24), optionally linked to a GlassSite
 * public catalog entry for marketing display.
 */
class BillingProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_key',
        'name',
        'description',
        'status',
        'public_catalog_entry_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(BillingPlan::class);
    }

    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(PublicProductCatalogEntry::class, 'public_catalog_entry_id');
    }

    public function serviceEntitlements(): HasMany
    {
        return $this->hasMany(BillingServiceEntitlement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
