<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chấm công tay (Phase 3):
     * - checkout_time: giờ ra (checkin_time đã có = giờ vào) để tính số giờ từ giờ vào/ra.
     * - source: nguồn tạo bản ghi (checkin = GV tự check-in theo buổi học, manual = Học vụ chấm tay).
     */
    public function up(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->string('checkout_time')->nullable()->after('checkin_time');
            $table->string('source', 20)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropColumn(['checkout_time', 'source']);
        });
    }
};
