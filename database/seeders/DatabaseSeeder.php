<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Phase 29 — minimal active product/plan so a fresh local/dev environment
        // is pilot-test ready (idempotent; no fake subscriptions/payments).
        $this->call(PilotSeeder::class);
    }
}
