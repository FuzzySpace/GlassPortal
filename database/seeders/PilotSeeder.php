<?php

namespace Database\Seeders;

use App\Models\BillingPlan;
use App\Models\BillingProduct;
use Illuminate\Database\Seeder;

/**
 * Pilot-safe seed data (Phase 29).
 *
 * Gives a fresh local/dev environment a minimal **active product + plan** so the
 * pilot readiness checks pass and an operator can walk the product-test flow.
 *
 * Safety:
 *  - Idempotent (firstOrCreate by key) — safe to run repeatedly / with --seed.
 *  - No fake paid subscriptions, customers, invoices, or payments are created.
 *  - Provider references are **clearly fake/local placeholders** (`price_local_*`).
 *    Replace them with real Stripe TEST price ids before live checkout testing —
 *    the readiness checks flag placeholder pricing as a warning until you do.
 */
class PilotSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'product_key' => 'glasshosting-wordpress',
                'name'        => 'GlassHosting WordPress Hosting',
                'description' => 'Managed WordPress hosting (pilot test product).',
                'plan'        => [
                    'plan_key'        => 'wordpress-hosting-monthly',
                    'name'            => 'WordPress Hosting Monthly',
                    'amount_cents'    => 1200,
                    'stripe_price_id' => 'price_local_wordpress_monthly',
                ],
            ],
            [
                'product_key' => 'glasshosting-minecraft',
                'name'        => 'GlassHosting Minecraft Server',
                'description' => 'Managed Minecraft game server (pilot test product).',
                'plan'        => [
                    'plan_key'        => 'minecraft-starter-monthly',
                    'name'            => 'Minecraft Starter Monthly',
                    'amount_cents'    => 800,
                    'stripe_price_id' => 'price_local_minecraft_starter_monthly',
                ],
            ],
        ];

        foreach ($products as $spec) {
            $product = BillingProduct::firstOrCreate(
                ['product_key' => $spec['product_key']],
                [
                    'name'        => $spec['name'],
                    'description' => $spec['description'],
                    'status'      => 'active',
                ],
            );

            BillingPlan::firstOrCreate(
                ['plan_key' => $spec['plan']['plan_key']],
                [
                    'billing_product_id' => $product->id,
                    'name'               => $spec['plan']['name'],
                    'amount_cents'       => $spec['plan']['amount_cents'],
                    'currency'           => 'USD',
                    'interval'           => 'month',
                    'status'             => 'active',
                    'stripe_price_id'    => $spec['plan']['stripe_price_id'],
                ],
            );
        }
    }
}
