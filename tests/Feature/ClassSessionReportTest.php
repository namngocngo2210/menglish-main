<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Nộp báo cáo buổi từ Trang lớp (tab Điểm danh & báo cáo buổi): nút trên từng buổi, đã nộp → "Đã nộp";
 * bấm nút mở form với lớp khóa + buổi chọn sẵn, sidebar giữ ở Lớp học, nộp xong về lại Trang lớp.
 */
class ClassSessionReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(today()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->teacher = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'name' => 'GV An']);
        $this->teacher->assignRole('teacher_parttime');
        $course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'name' => 'Lớp ST1', 'code' => 'ST1', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => now()->subMonths(3), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
    }

    private function session(int $daysAgo): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->subDays($daysAgo)->toDateString(),
            'start_time' => '08:00', 'end_time' => '09:30', 'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
    }

    public function test_teacher_submits_report_per_session_from_class_page(): void
    {
        $recent = $this->session(2);
        // Buổi cũ hơn 30 ngày: form thường không liệt kê, mở từ Trang lớp vẫn chọn sẵn được.
        $old = $this->session(45);

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows', fn ($rows) => collect($rows)->every(fn ($row) => $row['my_report'] === null)));

        $html = $this->actingAs($this->teacher)->get(route('classes.session-report', ['id' => $this->class->id, 'session' => $old->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tasks/ClassReportCreate')
                ->where('selectedClassId', $this->class->id)
                ->where('defaultSessionId', $old->id)
                ->where('classUrl', route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))
                ->where('reporterRole', fn ($label) => $label !== null && $label !== 'Trợ giảng')
                ->where('sessionOptions', fn ($options) => collect($options)->pluck('value')->contains($old->id)))
            ->getContent();
        // Sidebar: mục Lớp học sáng, không phải Công việc.
        $this->assertMatchesRegularExpression('/<a(?=[^>]*data-menu-item="classes")[^>]*aria-current="page"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a(?=[^>]*data-menu-item="tasks")[^>]*aria-current="page"/', $html);

        $this->actingAs($this->teacher)->post(route('tasks.class-reports.store'), [
            'class_id' => $this->class->id, 'class_session_id' => $recent->id, 'hom_nay_hoc_gi' => 'Unit 3', 'from_class' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']));

        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'attendance']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attendanceRows', function ($rows) use ($recent, $old) {
                $rows = collect($rows)->keyBy('id');
                $this->assertSame(['status' => ClassReport::STATUS_PENDING, 'rejected' => false], $rows[$recent->id]['my_report']);
                $this->assertNull($rows[$old->id]['my_report']);

                return true;
            }));
    }

    public function test_session_must_belong_to_class(): void
    {
        $other = ClassModel::create([
            'name' => 'Lớp khác', 'code' => 'KH1', 'course_id' => $this->class->course_id, 'branch_id' => $this->branch->id,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active',
        ]);
        $session = $this->session(1);

        $this->actingAs($this->teacher)->get(route('classes.session-report', ['id' => $other->id, 'session' => $session->id]))->assertNotFound();
    }
}
