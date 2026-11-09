<?php

namespace Database\Seeders;

use App\Models\KpiRetentionTier;
use Illuminate\Database\Seeder;

class KpiRetentionTierSeeder extends Seeder
{
    public function run(): void
    {
        if (KpiRetentionTier::query()->exists()) {
            return;
        }

        KpiRetentionTier::create(['min_seniority_months' => 0, 'from_students' => 1, 'to_students' => 5, 'rate' => 25000]);
        KpiRetentionTier::create(['min_seniority_months' => 0, 'from_students' => 6, 'to_students' => 10, 'rate' => 30000]);
    }
}
