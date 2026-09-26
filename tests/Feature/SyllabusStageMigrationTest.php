<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Q4 — chuyển dữ liệu giáo trình cũ (giáo trình → bài) sang Giáo trình → Chặng → Unit → Buổi.
 *
 * Tự chạy migrate trên SQLite in-memory, KHÔNG bọc transaction (RefreshDatabase): SQLite chỉ tắt được
 * khóa ngoại ngoài transaction, mà khi dựng lại bảng để thêm cột khóa ngoại, khóa ngoại đang bật sẽ
 * kích hoạt ON DELETE của bảng con (MySQL production không bị). DB in-memory mất cùng app nên không cần rollback.
 */
class SyllabusStageMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->assertSuccessful();
    }

    public function test_migration_maps_legacy_units_assignments_and_big_tests_without_data_loss(): void
    {
        $teacher = User::factory()->create();
        $class = ClassModel::create(['code' => 'MIG-01', 'name' => 'Lớp MIG']);

        $migration = require base_path('database/migrations/2026_09_29_100000_create_syllabus_stage_hierarchy.php');
        // Migration sau có khóa ngoại tới syllabus_stages / syllabus_lessons → gỡ trước, dựng lại sau.
        $dependents = collect([
            '2026_09_30_120000_add_content_to_syllabus_lessons',
            '2026_09_30_120100_add_stage_and_views_to_syllabus_documents',
            '2026_09_30_120200_add_lesson_to_syllabus_change_proposals',
            '2026_09_30_120300_add_stage_to_big_test_orders',
            '2026_10_09_100000_add_soft_deletes_to_remaining_tables',
        ])->map(fn ($name) => require base_path("database/migrations/{$name}.php"));
        $dependents->reverse()->each->down();
        $migration->down();

        $now = now();
        $curId = DB::table('syllabus_curriculums')->insertGetId([
            'code' => 'CUR-OLD', 'title' => 'Giáo trình cũ', 'version' => 'v1', 'stage_name' => 'Chặng Foundation',
            'overview_link' => 'https://example.com/o.png', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $u1 = DB::table('syllabus_units')->insertGetId(['curriculum_id' => $curId, 'unit_number' => 1, 'title' => 'Buổi 1 cũ', 'objectives' => 'MT1', 'homework_guide' => 'BTVN1', 'created_at' => $now, 'updated_at' => $now]);
        $u2 = DB::table('syllabus_units')->insertGetId(['curriculum_id' => $curId, 'unit_number' => 2, 'title' => 'Buổi 2 cũ', 'vocabulary_focus' => 'Từ vựng 2', 'created_at' => $now, 'updated_at' => $now]);
        $dup = DB::table('syllabus_units')->insertGetId(['curriculum_id' => $curId, 'unit_number' => 2, 'title' => 'Buổi 2 trùng số', 'created_at' => $now, 'updated_at' => $now]);
        $proposal = DB::table('syllabus_change_proposals')->insertGetId([
            'curriculum_id' => $curId, 'unit_id' => $u2, 'user_id' => $teacher->id, 'new_content' => 'Sửa', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $old = DB::table('syllabus_assignments')->insertGetId(['user_id' => $teacher->id, 'curriculum_id' => $curId, 'class_id' => $class->id, 'assigned_chapters' => 'Buổi 1-5', 'status' => 'in_progress', 'created_at' => $now->copy()->subMonth(), 'updated_at' => $now]);
        $newer = DB::table('syllabus_assignments')->insertGetId(['user_id' => $teacher->id, 'curriculum_id' => $curId, 'class_id' => $class->id, 'assigned_chapters' => 'Buổi 6-10', 'status' => 'in_progress', 'created_at' => $now, 'updated_at' => $now]);
        $done = DB::table('syllabus_assignments')->insertGetId(['user_id' => $teacher->id, 'curriculum_id' => $curId, 'class_id' => $class->id, 'assigned_chapters' => 'Cũ', 'status' => 'completed', 'created_at' => $now->copy()->subMonths(2), 'updated_at' => $now->copy()->subMonth()]);
        $pendingTest = DB::table('big_tests')->insertGetId(['code' => 'BT-OLD-1', 'title' => 'Chưa gửi', 'class_id' => $class->id, 'created_at' => $now, 'updated_at' => $now]);
        $sentTest = DB::table('big_tests')->insertGetId(['code' => 'BT-OLD-2', 'title' => 'Đã gửi', 'class_id' => $class->id, 'created_at' => $now, 'updated_at' => $now]);
        $st = Student::create(['code' => 'HV-MIG', 'name' => 'HV MIG', 'phone' => '0912000000', 'status' => 'studying']);
        DB::table('big_test_results')->insert(['big_test_id' => $sentTest, 'student_id' => $st->id, 'status' => 'sent', 'parent_notified' => true, 'created_at' => $now, 'updated_at' => $now]);

        $migration->up();
        $dependents->each->up();

        $stage = SyllabusStage::where('curriculum_id', $curId)->sole();
        $this->assertSame(1, $stage->position);
        $this->assertSame('Chặng Foundation', $stage->name);
        $this->assertSame('https://example.com/o.png', $stage->overview_link);

        // Unit giữ nguyên id, gắn Chặng 1; mỗi unit có đúng 1 buổi chép nội dung, số buổi = số unit (trùng → số trống kế tiếp).
        $this->assertSame([$stage->id], SyllabusUnit::whereIn('id', [$u1, $u2, $dup])->pluck('stage_id')->unique()->values()->all());
        $l1 = SyllabusLesson::where('unit_id', $u1)->sole();
        $this->assertSame([1, 'Buổi 1 cũ', 'MT1', 'BTVN1'], [$l1->session_no, $l1->title, $l1->objectives, $l1->homework_guide]);
        $this->assertSame([2, 'Từ vựng 2'], [SyllabusLesson::where('unit_id', $u2)->value('session_no'), SyllabusLesson::where('unit_id', $u2)->value('vocabulary_focus')]);
        $this->assertSame(3, SyllabusLesson::where('unit_id', $dup)->value('session_no'));
        $this->assertSame('MT1', SyllabusUnit::find($u1)->objectives);
        $this->assertSame($u2, (int) DB::table('syllabus_change_proposals')->where('id', $proposal)->value('unit_id'));

        // Chỉ 1 chặng mở cho lớp: bản mới nhất; bản cũ đóng kèm lý do; bản đã xong có mốc đóng.
        $this->assertSame([$newer], SyllabusAssignment::open()->where('class_id', $class->id)->pluck('id')->all());
        $this->assertSame($class->id, SyllabusAssignment::find($newer)->open_class_id);
        $closed = SyllabusAssignment::find($old);
        $this->assertSame('completed', $closed->status);
        $this->assertNotEmpty($closed->close_reason);
        $this->assertNotNull(SyllabusAssignment::find($done)->closed_at);
        $this->assertSame([$stage->id], SyllabusAssignment::whereIn('id', [$old, $newer, $done])->pluck('stage_id')->unique()->values()->all());

        // Big Test cũ không tự gắn chặng (gửi kết quả đợt cũ không đóng chặng); kết quả giữ nguyên.
        $this->assertNull(BigTest::find($pendingTest)->syllabus_stage_id);
        $this->assertNull(BigTest::find($sentTest)->syllabus_stage_id);
        $this->assertSame(1, DB::table('big_test_results')->where('big_test_id', $sentTest)->count());
    }
}
