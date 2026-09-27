<?php

use App\Models\PlacementTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cấp độ của đề test đầu vào = lớp của khách (Mẫu giáo, Lớp 1 … Lớp 9) — ô "Chọn cấp độ" khi hẹn test ở CRM.
 * Đề đã có được gán theo mã đề (TEST-G3-G4 → Lớp 3, PRE-G1 → Mẫu giáo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->string('grade_level', 20)->nullable()->after('target_level')->index();
        });

        DB::table('placement_tests')->select('id', 'code')->orderBy('id')->each(function ($test) {
            DB::table('placement_tests')->where('id', $test->id)
                ->update(['grade_level' => PlacementTest::detectGradeLevel($test->code)]);
        });
    }

    public function down(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropIndex(['grade_level']);
            $table->dropColumn('grade_level');
        });
    }
};
