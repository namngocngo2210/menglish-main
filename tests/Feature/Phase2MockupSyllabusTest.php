<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 — đối chiếu mockup nhóm Giáo trình & Big Test (roundcuoi-kieulien/01_Web_Admin/01..08,
 * 03_Cong_Giao_Vien/07..14) theo mô hình chặng Q4 (A6). Mỗi test khẳng định các phần tử chính của một màn.
 */
class Phase2MockupSyllabusTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academic;

    private User $teacher;

    private User $assistant;

    private ClassModel $class;

    private SyllabusCurriculum $curriculum;

    /** @var array<int, SyllabusStage> */
    private array $stages;

    /** @var array<int, SyllabusLesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        config(['services.zalo.mode' => 'sandbox']);

        $this->branch = Branch::create(['name' => 'Cơ sở Mockup', 'code' => 'MK', 'is_active' => true]);
        $this->academic = User::factory()->create(['name' => 'Học Thuật Mockup', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->academic->assignRole('academic_lead');
        $this->teacher = User::factory()->create(['name' => 'GV Mockup', 'employee_code' => 'GV-MK-01', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->teacher->assignRole('teacher');
        $this->assistant = User::factory()->create(['name' => 'TG Mockup', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->assistant->assignRole('assistant');

        $this->curriculum = SyllabusCurriculum::create(['code' => 'CUR-MK', 'title' => 'Starter Mockup', 'version' => 'v1', 'stage_name' => 'Chặng 1: Nền tảng']);
        $this->stages = [
            $this->curriculum->stages()->firstOrFail(),
            SyllabusStage::create(['curriculum_id' => $this->curriculum->id, 'position' => 2, 'name' => 'Chặng 2: Giao tiếp cơ bản', 'description' => 'Thực hành giao tiếp hằng ngày']),
        ];
        $session = 1;
        foreach ($this->stages as $i => $stage) {
            $unit = SyllabusUnit::create(['curriculum_id' => $this->curriculum->id, 'stage_id' => $stage->id, 'unit_number' => $i + 1, 'title' => 'Greetings '.($i + 1)]);
            foreach (range(1, 2) as $n) {
                $this->lessons[] = SyllabusLesson::create([
                    'unit_id' => $unit->id, 'session_no' => $session, 'title' => "Buổi mẫu {$session}",
                    'objectives' => "Mục tiêu {$session}", 'content' => "Hoạt động đóng vai {$session}", 'homework_guide' => "BTVN {$session}",
                ]);
                $session++;
            }
        }

        CourseLevel::create(['code' => 'MKL', 'name' => 'Starter', 'target' => 'A1', 'syllabus_curriculum_id' => $this->curriculum->id, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'MK-01', 'name' => 'Lớp Mockup 01', 'branch_id' => $this->branch->id, 'level' => 'MKL', 'room' => 'Phòng 402',
            'teacher_id' => $this->teacher->id, 'assistant_id' => $this->assistant->id, 'status' => 'active',
        ]);
    }

    private function openStage(): SyllabusAssignment
    {
        $this->actingAs($this->academic)->post(route('syllabus.assignments.store'), ['class_id' => $this->class->id])
            ->assertSessionHasNoErrors();

        return SyllabusAssignment::open()->where('class_id', $this->class->id)->firstOrFail();
    }

    private function student(string $code): Student
    {
        return Student::create(['code' => $code, 'name' => 'HV '.$code, 'phone' => '0912345678', 'current_class_id' => $this->class->id, 'status' => 'studying']);
    }

    // ---- 01_Web_Admin/02 — Soạn syllabus theo chặng ----

    public function test_builder_matches_mockup_stage_info_and_lesson_cards(): void
    {
        $url = route('syllabus.builder', ['curriculum' => $this->curriculum->id]);

        $this->actingAs($this->academic)->get($url)->assertOk()
            ->assertSee('Soạn syllabus theo chặng')
            ->assertSee('Thiết lập cấu trúc chương trình học và nội dung chi tiết từng buổi')
            ->assertSee('Tổng số: 02 buổi')
            ->assertSee('Thêm buổi học mới vào chặng')
            ->assertSee('Hoạt động đóng vai 1')
            ->assertDontSee('tự động lưu');

        // Ô soạn chặng: thông tin chung, chính sách mở khóa theo A6, xem thử link tổng quan, thanh Lưu chặng học
        $this->actingAs($this->academic)->get($url.'&edit_stage='.$this->stages[1]->id)->assertOk()
            ->assertSee('Thông tin chung chặng học')
            ->assertSee('Chính sách mở khóa')
            ->assertSee('Hoàn thành Big Test chặng trước')
            ->assertSee('Xem thử')
            ->assertSee('Khu vực hiển thị preview ảnh mục lục tổng quan')
            ->assertSee('Lưu chặng học');

        // Ô soạn buổi: Mục tiêu (Target) / Nội dung bài học chính / Bài tập về nhà (Homework), lưu được nội dung chính
        $this->actingAs($this->academic)->get($url.'&edit_lesson='.$this->lessons[0]->id)->assertOk()
            ->assertSee('Mục tiêu buổi học (Target)')
            ->assertSee('Nội dung bài học chính')
            ->assertSee('Bài tập về nhà (Homework)');

        $this->actingAs($this->academic)->put(route('syllabus.lessons.update', $this->lessons[0]->id), [
            'session_no' => 1, 'title' => 'Introduction', 'objectives' => 'Tự giới thiệu', 'content' => 'Thực hành theo cặp', 'homework_guide' => 'Viết 5 câu',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Thực hành theo cặp', $this->lessons[0]->fresh()->content);

        // Giáo viên xem được nhưng không có nút soạn
        $this->actingAs($this->teacher)->get($url)->assertOk()->assertDontSee('Thêm buổi học mới vào chặng');
    }
}
