<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Khung giờ ca dạy (file "Khung giờ chấm công ME", 07/10/2026): Thứ 2–6 chỉ có Ca 1 / Ca 2, Thứ 7 / Chủ nhật theo giờ
 * thực tế của lớp; mỗi ca 90 phút; mỗi khung có giờ chuẩn và giờ lệch (một số lớp lệch 10–30 phút).
 * Lịch & TKB lớp chỉ cho chọn theo khung này; quyền sửa danh mục: teaching_shift.manage (chỉ Admin).
 * Buổi học cũ tên "Slot 1/2" khớp khung → đổi tên ca cho dễ đọc ở chấm công. Chạy lại an toàn (MySQL không rollback DDL).
 */
return new class extends Migration
{
    /** [loại ngày, tên ca, giờ chuẩn, giờ lệch, ghi chú] — theo file. Dòng CN 16:30 ghi "lệch 15 phút" nên giờ lệch là 16:45–18:15. */
    private const SEED = [
        ['weekday', 'Ca 1', '18:00', '19:30', '18:10', '19:40', 'Một số lớp lệch 10 phút'],
        ['weekday', 'Ca 2', '19:30', '21:00', '19:40', '21:10', 'Một số lớp lệch 10 phút'],
        ['saturday', 'Ca chiều', '15:00', '16:30', '15:15', '16:45', 'Có lớp lệch 15 phút'],
        ['saturday', 'Ca chiều', '16:00', '17:30', '16:15', '17:45', 'Có lớp lệch 15 phút'],
        ['saturday', 'Ca chiều', '17:00', '18:30', '17:30', '19:00', 'Có lớp lệch 30 phút'],
        ['saturday', 'Ca chiều/tối', '18:00', '19:30', '18:30', '20:00', 'Có lớp lệch 30 phút'],
        ['sunday', 'Ca chiều', '15:00', '16:30', null, null, null],
        ['sunday', 'Ca chiều', '16:00', '17:30', '16:15', '17:45', 'Có lớp lệch 15 phút'],
        ['sunday', 'Ca chiều', '16:30', '18:00', '16:45', '18:15', 'Có lớp lệch 15 phút'],
        ['sunday', 'Ca chiều', '18:00', '19:30', null, null, null],
        ['sunday', 'Ca tối', '18:30', '20:00', null, null, null],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('teaching_shifts')) {
            Schema::create('teaching_shifts', function (Blueprint $table) {
                $table->id();
                $table->string('day_type', 10); // weekday | saturday | sunday
                $table->string('name', 50);
                $table->time('start_time');
                $table->time('end_time');
                $table->time('alt_start_time')->nullable();
                $table->time('alt_end_time')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['day_type', 'is_active'], 'teaching_shifts_day_active_idx');
            });
        }

        if (DB::table('teaching_shifts')->doesntExist()) {
            $now = now();
            foreach (self::SEED as $i => [$dayType, $name, $start, $end, $altStart, $altEnd, $note]) {
                DB::table('teaching_shifts')->insert([
                    'day_type' => $dayType,
                    'name' => $name,
                    'start_time' => $start.':00',
                    'end_time' => $end.':00',
                    'alt_start_time' => $altStart ? $altStart.':00' : null,
                    'alt_end_time' => $altEnd ? $altEnd.':00' : null,
                    'note' => $note,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Permission::findOrCreate('teaching_shift.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->renameMatchingSessions();
    }

    /** Buổi chính khóa sắp tới / đã qua còn tên "Slot n" (hoặc trống) mà giờ khớp một khung → đặt tên ca theo khung. */
    private function renameMatchingSessions(): void
    {
        if (! Schema::hasTable('class_sessions')) {
            return;
        }
        $frames = [];
        foreach (DB::table('teaching_shifts')->whereNull('deleted_at')->get() as $shift) {
            foreach ([[$shift->start_time, $shift->end_time], [$shift->alt_start_time, $shift->alt_end_time]] as [$start, $end]) {
                if ($start && $end) {
                    $frames[$shift->day_type][substr($start, 0, 5).'-'.substr($end, 0, 5)] ??= $shift->name;
                }
            }
        }

        DB::table('class_sessions')
            ->where(fn ($q) => $q->whereNull('shift_name')->orWhere('shift_name', 'like', 'Slot%'))
            ->where('type', 'regular')
            ->select(['id', 'date', 'start_time', 'end_time'])
            ->chunkById(500, function ($sessions) use ($frames) {
                foreach ($sessions as $session) {
                    $weekday = Carbon::parse($session->date)->isoWeekday();
                    $dayType = match ($weekday) {
                        6 => 'saturday',
                        7 => 'sunday',
                        default => 'weekday',
                    };
                    $name = $frames[$dayType][substr((string) $session->start_time, 0, 5).'-'.substr((string) $session->end_time, 0, 5)] ?? null;
                    if ($name) {
                        DB::table('class_sessions')->where('id', $session->id)->update(['shift_name' => $name]);
                    }
                }
            });
    }

    public function down(): void
    {
        Permission::query()->where('name', 'teaching_shift.manage')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('teaching_shifts');
    }
};
