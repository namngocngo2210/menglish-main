<?php

namespace Tests\Feature;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalBonusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_legacy_settings_and_payroll_records_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $snapshots = [];
        foreach (['draft', 'closed', 'published', 'paid'] as $index => $status) {
            $period = PayrollPeriod::create([
                'code' => 'RENEW-MIG-'.$index, 'title' => 'Renewal migration', 'month' => 9, 'year' => 2026,
                'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => $status,
            ]);
            $record = PayrollRecord::create([
                'payroll_period_id' => $period->id, 'user_id' => $user->id,
                'renew_bonus' => 70000, 'net_salary' => 9070000,
                'calculation_details' => ['renewal' => ['classes' => [['percent' => 0.7, 'pending' => true]]]],
            ]);
            $snapshots[] = [$period, $period->fresh()->getAttributes(), $record, $record->fresh()->getAttributes()];
        }
        SystemSetting::set('payroll_renewal_table', [0 => ['percent' => 2], 1 => ['percent' => 0.7]]);
        SystemSetting::set('payroll_renewal_beyond_percent', 0.1);
        $legacy = SystemSetting::where('key', 'payroll_renewal_table')->firstOrFail()->getAttributes();
        $migration = require database_path('migrations/2026_12_04_090001_migrate_renewal_bonus_percentages.php');
        $migration->up();
        $this->assertEquals(2, SystemSetting::get('payroll_renewal_same_percent'));
        $this->assertEquals(2.5, SystemSetting::get('payroll_renewal_increase_percent'));
        $settings = SystemSetting::orderBy('id')->get()->toArray();
        $migration->up();
        $this->assertSame($settings, SystemSetting::orderBy('id')->get()->toArray());
        $this->assertSame($legacy, SystemSetting::where('key', 'payroll_renewal_table')->firstOrFail()->getAttributes());
        $this->assertEquals(0.1, SystemSetting::get('payroll_renewal_beyond_percent'));
        foreach ($snapshots as [$period, $periodBefore, $record, $recordBefore]) {
            $this->assertSame($periodBefore, $period->fresh()->getAttributes());
            $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        }
        $migration->down();
        $this->assertSame($settings, SystemSetting::orderBy('id')->get()->toArray());
    }

    public function test_migration_uses_defaults_for_missing_zero_tier_and_skips_fresh_installations(): void
    {
        $migration = require database_path('migrations/2026_12_04_090001_migrate_renewal_bonus_percentages.php');
        $migration->up();
        $this->assertFalse(SystemSetting::where('key', 'payroll_renewal_same_percent')->exists());
        SystemSetting::set('payroll_renewal_table', [1 => ['percent' => 0.7]]);
        $migration->up();
        $this->assertEquals(1, SystemSetting::get('payroll_renewal_same_percent'));
        $this->assertEquals(1.5, SystemSetting::get('payroll_renewal_increase_percent'));
    }

    public function test_migration_never_overwrites_existing_new_settings(): void
    {
        SystemSetting::set('payroll_renewal_table', [0 => ['percent' => 1]]);
        SystemSetting::set('payroll_renewal_same_percent', 3);
        $migration = require database_path('migrations/2026_12_04_090001_migrate_renewal_bonus_percentages.php');
        $migration->up();
        $this->assertEquals(3, SystemSetting::get('payroll_renewal_same_percent'));
        $this->assertEquals(3.5, SystemSetting::get('payroll_renewal_increase_percent'));
        SystemSetting::set('payroll_renewal_increase_percent', 4);
        $migration->up();
        $this->assertEquals(3, SystemSetting::get('payroll_renewal_same_percent'));
        $this->assertEquals(4, SystemSetting::get('payroll_renewal_increase_percent'));
    }
}
