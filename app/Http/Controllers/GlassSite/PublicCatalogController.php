<?php

namespace App\Http\Controllers\GlassSite;

use App\Http\Controllers\Controller;
use App\Models\PublicProductCatalogEntry;
use Illuminate\View\View;

/**
 * GlassSite public, unauthenticated product catalog (Phase 22).
 *
 * Renders only published entries. The `published()` scope is applied to every
 * query so unpublished / private / future-dated entries are never reachable —
 * a request for such a slug 404s rather than leaking its existence.
 */
class PublicCatalogController extends Controller
{
    public function index(): View
    {
        $entries = PublicProductCatalogEntry::query()
            ->published()
            ->ordered()
            ->get();

        return view('glasssite.products.index', ['entries' => $entries]);
    }

    public function show(string $slug): View
    {
        $entry = PublicProductCatalogEntry::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return view('glasssite.products.show', ['entry' => $entry]);
    }
}
