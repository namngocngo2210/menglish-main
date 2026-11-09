<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::transaction(function () {
            if (! SystemSetting::where('key', 'payroll_renewal_table')->exists()) {
                return;
            }

            if (! SystemSetting::where('key', 'payroll_renewal_same_percent')->exists()) {
                $percent = data_get(SystemSetting::get('payroll_renewal_table'), '0.percent');
                $same = is_numeric($percent) ? (float) $percent : (float) config('payroll.renewal_bonus.same_percent', 1.0);
                SystemSetting::set('payroll_renewal_same_percent', $same, 'Thưởng tái tục Học vụ: giữ nguyên số HS (%)');
            }

            if (! SystemSetting::where('key', 'payroll_renewal_increase_percent')->exists()) {
                $same = (float) SystemSetting::get('payroll_renewal_same_percent');
                $increase = (float) config('payroll.renewal_bonus.increase_percent', 1.5);
                SystemSetting::set('payroll_renewal_increase_percent', $increase > $same ? $increase : $same + 0.5, 'Thưởng tái tục Học vụ: tăng số HS (%)');
            }
        });
    }

    public function down(): void
    {
        // Preserve settings on rollback: they may predate this migration or have been edited by an admin.
        // Legacy keys remain available to the previous application version; no payroll data is changed.
    }
};
