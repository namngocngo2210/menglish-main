<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phòng học theo chi nhánh + danh mục loại phòng (dùng chung toàn hệ thống).
 *
 * Trước đây "Phòng học" ở form tạo / sửa lớp là danh sách cố định 5 phòng (mã P101, P202… lưu thẳng vào classes.room).
 * Lớp nay gắn phòng qua classes.room_id; classes.room và class_sessions.room vẫn giữ TÊN phòng (kiểm tra trùng phòng,
 * lịch dạy, bảng công đang đọc cột chữ này). Phòng đang được lớp dùng được tạo lại thành phòng của chi nhánh lớp đó.
 */
return new class extends Migration
{
    /** Danh sách cố định cũ: mã → [tên, sức chứa, loại]. */
    private const LEGACY = [
        'P101' => ['Phòng 101', 20, 'Phòng tiêu chuẩn'],
        'P202' => ['Phòng 202', 16, 'Phòng tiêu chuẩn'],
        'P302' => ['Phòng 302', 18, 'Phòng tiêu chuẩn'],
        'LAB_A' => ['Phòng Lab A', 24, 'Phòng Lab máy tính'],
        'LAB_B' => ['Phòng Lab B', 24, 'Phòng Lab máy tính'],
    ];

    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types');
            $table->string('name', 100);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'name']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('room')->constrained('rooms')->nullOnDelete();
        });

        $this->linkExistingRooms();
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }

    /** Mỗi (chi nhánh, phòng) đang gắn với lớp → một phòng của chi nhánh đó; mã cũ đổi sang tên đầy đủ. */
    private function linkExistingRooms(): void
    {
        $pairs = DB::table('classes')
            ->whereNull('deleted_at')
            ->whereNotNull('branch_id')
            ->whereNotNull('room')
            ->where('room', '!=', '')
            ->select('branch_id', 'room')
            ->distinct()
            ->get();

        $now = now();
        $types = [];
        foreach ($pairs as $pair) {
            [$name, $capacity, $typeName] = self::LEGACY[$pair->room] ?? [mb_substr(trim($pair->room), 0, 100), null, 'Phòng tiêu chuẩn'];

            $types[$typeName] ??= DB::table('room_types')->insertGetId(['name' => $typeName, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

            $roomId = DB::table('rooms')->where('branch_id', $pair->branch_id)->where('name', $name)->whereNull('deleted_at')->value('id')
                ?? DB::table('rooms')->insertGetId([
                    'branch_id' => $pair->branch_id,
                    'room_type_id' => $types[$typeName],
                    'name' => $name,
                    'capacity' => $capacity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('classes')->where('branch_id', $pair->branch_id)->where('room', $pair->room)
                ->update(['room_id' => $roomId, 'room' => $name]);

            if ($name !== $pair->room) {
                DB::table('class_sessions')->where('branch_id', $pair->branch_id)->where('room', $pair->room)->update(['room' => $name]);
            }
        }
    }
};
