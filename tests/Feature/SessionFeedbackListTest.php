<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\Course;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Màn "Feedback buổi học" (chủ dự án 17/10/2026) cho Học vụ / Học thuật / Admin: phiếu phụ huynh chấm sao và góp ý của Học vụ,
 * theo tháng của buổi học, chỉ lớp trong phạm vi người xem; giáo viên không vào được.
 */
class SessionFeedbackListTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Course $course;

    private User $teacher;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travelTo(today()->setTime(12, 0));
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->course = Course::create(['name' => 'Starters', 'code' => 'ST', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher_fulltime', ['name' => 'Cô Lan']);
        $this->class = $this->makeClass('Lớp ST1', $this->branch);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['branch_id' => $this->branch->id, 'is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    private function makeClass(string $name, Branch $branch): ClassModel
    {
        return ClassModel::create(['name' => $name, 'code' => str_replace(' ', '', $name), 'course_id' => $this->course->id, 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(2), 'max_capacity' => 12, 'status' => 'active']);
    }

    private function parentFeedback(ClassModel $class, string $date, int $rating, array $extra = []): ClassSessionFeedback
    {
        $session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date, 'start_time' => '08:00', 'end_time' => '09:30',
            'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
        $student = Student::create(['code' => 'HV-'.random_int(1000, 9999), 'name' => $extra['student'] ?? 'Nguyễn An', 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $class->branch_id, 'status' => 'studying', 'current_class_id' => $class->id]);
        unset($extra['student']);

        return ClassSessionFeedback::create(array_merge(['class_session_id' => $session->id, 'class_id' => $class->id, 'source' => ClassSessionFeedback::SOURCE_PARENT,
            'student_id' => $student->id, 'rating' => $rating], $extra));
    }

    public function test_academic_staff_sees_parent_ratings_of_the_month_with_stats_and_filters(): void
    {
        // Học vụ mặc định xem mọi lớp; thu về phạm vi chi nhánh để thấy màn này đi theo phạm vi lớp của người xem.
        Role::findByName('academic_staff')->revokePermissionTo('class.scope_all')->givePermissionTo('class.scope_branch');
        $staff = $this->makeUser('academic_staff');
        $month = today()->format('Y-m');
        $day = today()->startOfMonth()->toDateString();
        $this->parentFeedback($this->class, $day, 5, ['categories' => ['teacher'], 'content' => 'Cô dạy vui', 'student' => 'Nguyễn An']);
        $low = $this->parentFeedback($this->class, $day, 2, ['categories' => ['timing'], 'content' => 'Lớp tan muộn 15 phút', 'student' => 'Trần Bình']);
        $this->parentFeedback($this->class, $day, 1, ['categories' => ['facilities'], 'student' => 'Lê Chi']);
        // Tháng trước và lớp chi nhánh khác: không tính.
        $this->parentFeedback($this->class, today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 1);
        $other = $this->makeClass('Lớp B1', Branch::create(['name' => 'Cơ sở B', 'code' => 'CB', 'is_active' => true]));
        $this->parentFeedback($other, $day, 1);

        $this->actingAs($staff)->get(route('class-quality.session-feedback'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClassQuality/SessionFeedback')
                ->where('source', 'parent')->where('month', $month)
                ->where('stats', ['total' => 3, 'average' => 2.7, 'low' => 2, 'commented' => 2])
                ->has('feedback.data', 3)->where('classes', [['value' => $this->class->id, 'label' => 'Lớp ST1']])
                ->where('teachers.0.label', 'Cô Lan'));

        $this->actingAs($staff)->get(route('class-quality.session-feedback', ['rating' => 'low', 'category' => 'timing']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.total', 3)->has('feedback.data', 1)
                ->where('feedback.data.0.id', $low->id)->where('feedback.data.0.student', 'Trần Bình')->where('feedback.data.0.categories', ['Giờ giấc'])
                ->where('feedback.data.0.class', 'Lớp ST1')->where('feedback.data.0.teacher', 'Cô Lan')->where('feedback.data.0.time', '08:00–09:30'));

        $this->actingAs($staff)->get(route('class-quality.session-feedback', ['search' => 'Nguyễn']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('feedback.data', 1)->where('feedback.data.0.rating', 5));
    }

    public function test_staff_tab_lists_academic_staff_notes_and_escalations(): void
    {
        $lead = $this->makeUser('academic_lead', ['name' => 'Học thuật Hà']);
        $author = $this->makeUser('academic_staff', ['name' => 'Học vụ Thư']);
        $day = today()->toDateString();
        $session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $day, 'start_time' => '17:30', 'end_time' => '19:00',
            'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
        $entry = StaffReport::create(['user_id' => $author->id, 'class_id' => $this->class->id, 'type' => 'journal', 'title' => 'Khẩn: Lớp ST1', 'content' => 'Máy chiếu hỏng',
            'severity' => 'urgent', 'report_date' => $day, 'status' => 'resolved']);
        $base = ['class_session_id' => $session->id, 'class_id' => $this->class->id, 'source' => ClassSessionFeedback::SOURCE_STAFF];
        ClassSessionFeedback::create($base + ['author_id' => $author->id, 'verdict' => 'problem', 'content' => 'Máy chiếu hỏng', 'escalation_id' => $entry->id]);
        ClassSessionFeedback::create($base + ['author_id' => $lead->id, 'verdict' => 'attention', 'content' => 'Lớp ồn']);
        $this->parentFeedback($this->class, $day, 4);

        $this->actingAs($lead)->get(route('class-quality.session-feedback', ['source' => 'staff']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('source', 'staff')
                ->where('stats', ['total' => 2, 'attention' => 1, 'problem' => 1, 'escalated' => 1])->has('feedback.data', 2));

        $this->actingAs($lead)->get(route('class-quality.session-feedback', ['source' => 'staff', 'verdict' => 'problem']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('feedback.data', 1)->where('feedback.data.0.author', 'Học vụ Thư')
                ->where('feedback.data.0.verdict_label', 'Có vấn đề')->where('feedback.data.0.escalated', true)->where('feedback.data.0.escalation_resolved', true));
    }

    public function test_teachers_cannot_open_the_feedback_list(): void
    {
        $this->parentFeedback($this->class, today()->toDateString(), 1);

        $this->actingAs($this->teacher)->get(route('class-quality.session-feedback'))->assertForbidden();
        $this->actingAs($this->makeUser('admin', ['branch_id' => null]))->get(route('class-quality.session-feedback'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.total', 1));
    }
}
