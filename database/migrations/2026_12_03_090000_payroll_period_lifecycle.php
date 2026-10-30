<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_period_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['payroll_period_id', 'created_at']);
        });

        DB::transaction(function () {
            DB::table('payroll_periods')->whereIn('status', ['reviewing', 'approved'])
                ->orderBy('id')->chunkById(200, function ($periods) {
                    foreach ($periods as $period) {
                        // Kỳ đã duyệt đã tất toán và nhân sự đã xem được: giữ khóa bằng trạng thái published.
                        $status = $period->status === 'approved' ? 'published' : 'draft';
                        DB::table('payroll_period_transitions')->insert([
                            'payroll_period_id' => $period->id,
                            'from_status' => $period->status,
                            'to_status' => $status,
                            'user_id' => null,
                            'note' => 'Chuyển đổi trạng thái cũ (TK-0016)',
                            'created_at' => $period->updated_at ?? now(),
                        ]);
                        DB::table('payroll_periods')->where('id', $period->id)->update(['status' => $status]);
                    }
                });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('payroll_periods')->whereIn('status', ['published', 'closed'])->update(['status' => 'approved']);
            DB::table('payroll_periods')->where('status', 'draft')
                ->whereIn('id', DB::table('payroll_period_transitions')
                    ->where('from_status', 'reviewing')->where('to_status', 'draft')
                    ->where('note', 'Chuyển đổi trạng thái cũ (TK-0016)')->select('payroll_period_id'))
                ->update(['status' => 'reviewing']);
        });
        Schema::dropIfExists('payroll_period_transitions');
    }
};
