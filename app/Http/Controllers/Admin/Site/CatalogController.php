<?php

namespace App\Http\Controllers\Admin\Site;

use App\Http\Controllers\Controller;
use App\Models\PublicProductCatalogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin management of GlassSite public catalog entries (Phase 22).
 *
 * Owner/admin only — enforced by stacked `role:owner,admin` middleware on the
 * route group. Staff/support and customers cannot reach these actions.
 */
class CatalogController extends Controller
{
    public function index(): View
    {
        $entries = PublicProductCatalogEntry::query()
            ->ordered()
            ->paginate(25);

        return view('admin.site.catalog.index', ['entries' => $entries]);
    }

    public function create(): View
    {
        return view('admin.site.catalog.form', [
            'entry'  => new PublicProductCatalogEntry(['currency' => 'USD']),
            'method' => 'POST',
            'action' => route('admin.site.catalog.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entry = PublicProductCatalogEntry::create($this->validatedData($request));

        return redirect()
            ->route('admin.site.catalog.index')
            ->with('success', "Catalog entry “{$entry->title}” created.");
    }

    public function edit(PublicProductCatalogEntry $entry): View
    {
        return view('admin.site.catalog.form', [
            'entry'  => $entry,
            'method' => 'PATCH',
            'action' => route('admin.site.catalog.update', $entry),
        ]);
    }

    public function update(Request $request, PublicProductCatalogEntry $entry): RedirectResponse
    {
        $entry->update($this->validatedData($request, $entry));

        return redirect()
            ->route('admin.site.catalog.index')
            ->with('success', "Catalog entry “{$entry->title}” updated.");
    }

    public function togglePublish(PublicProductCatalogEntry $entry): RedirectResponse
    {
        if ($entry->is_public) {
            $entry->update(['is_public' => false]);
            $message = "“{$entry->title}” unpublished.";
        } else {
            $entry->update([
                'is_public'    => true,
                'published_at' => $entry->published_at ?? now(),
            ]);
            $message = "“{$entry->title}” published.";
        }

        return redirect()->route('admin.site.catalog.index')->with('success', $message);
    }

    public function toggleFeatured(PublicProductCatalogEntry $entry): RedirectResponse
    {
        $entry->update(['featured' => ! $entry->featured]);

        return redirect()
            ->route('admin.site.catalog.index')
            ->with('success', $entry->featured ? "“{$entry->title}” featured." : "“{$entry->title}” unfeatured.");
    }

    public function destroy(PublicProductCatalogEntry $entry): RedirectResponse
    {
        // Soft delete preserves the record (and removes it from the public site).
        $entry->delete();

        return redirect()
            ->route('admin.site.catalog.index')
            ->with('success', "Catalog entry “{$entry->title}” deleted.");
    }

    /**
     * Validate and normalize the request into model attributes.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, ?PublicProductCatalogEntry $entry = null): array
    {
        $slugRule = 'nullable|string|max:255|alpha_dash';
        $slugRule .= '|unique:public_product_catalog_entries,slug' . ($entry ? ",{$entry->id}" : '');

        $validated = $request->validate([
            'title'                => 'required|string|max:255',
            'slug'                 => $slugRule,
            'short_description'    => 'nullable|string|max:500',
            'description'          => 'nullable|string|max:10000',
            'category'             => 'nullable|string|max:64',
            'product_key'          => 'nullable|string|max:64',
            'module_key'           => 'nullable|string|max:64',
            'starting_price_cents' => 'nullable|integer|min:0|max:100000000',
            'currency'             => 'nullable|string|size:3',
            'billing_interval'     => 'nullable|string|max:32',
            'cta_label'            => 'nullable|string|max:64',
            'cta_url'              => 'nullable|url|max:2048',
            'docs_url'             => 'nullable|url|max:2048',
            'support_url'          => 'nullable|url|max:2048',
            'status_url'           => 'nullable|url|max:2048',
            'icon'                 => 'nullable|string|max:16',
            'sort_order'           => 'nullable|integer|min:-32768|max:32767',
            'published_at'         => 'nullable|date',
            'metadata'             => 'nullable|json',
        ]);

        $validated['currency']     = strtoupper($validated['currency'] ?? 'USD') ?: 'USD';
        $validated['featured']     = $request->boolean('featured');
        $validated['is_public']    = $request->boolean('is_public');
        $validated['sort_order']   = $validated['sort_order'] ?? 0;
        $validated['published_at'] = $validated['published_at'] ?? null;
        $validated['metadata']     = filled($validated['metadata'] ?? null)
            ? json_decode($validated['metadata'], true)
            : null;

        // Publishing without an explicit date stamps "now" so it appears immediately.
        if ($validated['is_public'] && blank($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        return $validated;
    }
}
