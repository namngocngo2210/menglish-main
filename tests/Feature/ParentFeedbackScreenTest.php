<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** TK-2026-0058: màn "Feedback phụ huynh" theo lớp/chặng (Học vụ/Admin) + cổng phụ huynh chỉ gửi khi chặng đang mở. */
class ParentFeedbackScreenTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ClassModel $class;

    private Student $student;

    private SyllabusAssignment $stage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cơ sở A', 'code' => 'CA', 'is_active' => true]);
        $this->class = ClassModel::create(['name' => 'Lớp FB1', 'code' => 'FB1', 'max_capacity' => 12, 'status' => 'active', 'branch_id' => $this->branch->id]);
        $parentUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->student = Student::create(['user_id' => $parentUser->id, 'code' => 'HV-FB1', 'name' => 'Nguyễn An', 'phone' => '0911111111',
            'branch_id' => $this->branch->id, 'status' => 'studying', 'current_class_id' => $this->class->id]);
        $curriculum = SyllabusCurriculum::create(['code' => 'CUR-FB', 'title' => 'GT FB', 'version' => 'v1', 'stage_name' => 'Chặng 1']);
        $this->stage = SyllabusAssignment::create(['class_id' => $this->class->id, 'curriculum_id' => $curriculum->id, 'user_id' => $parentUser->id,
            'assigned_chapters' => 'Unit 1', 'stage_name' => 'Chặng 1', 'status' => SyllabusAssignment::STATUS_OPEN, 'feedback_open' => false]);
        $this->actingAs($parentUser);
        $this->parentUser = $parentUser;
    }

    private User $parentUser;

    private function staff(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function sendFeedback(array $extra = [])
    {
        return $this->actingAs($this->parentUser)->post(route('portal.student.feedback.store'), $extra + [
            'student_id' => $this->student->id, 'stage_name' => 'Chặng 1', 'noi_dung_feedback' => 'Lớp học tốt',
        ]);
    }

    public function test_portal_rejects_feedback_while_stage_is_closed_and_accepts_when_open(): void
    {
        $this->sendFeedback()->assertSessionHasErrors('validation');
        $this->assertSame(0, AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')->count());

        $this->stage->update(['feedback_open' => true]);
        // Tên chặng do client gửi bị bỏ qua: feedback gắn vào chặng đang mở của lớp.
        $this->sendFeedback(['stage_name' => 'Chặng giả'])->assertSessionHasNoErrors();
        $record = AcademicRecord::where('screen_key', '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')->firstOrFail();
        $this->assertSame('Chặng 1', $record->data['stage_name']);
    }

    public function test_portal_page_reports_open_flag(): void
    {
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', false));
        $this->stage->update(['feedback_open' => true]);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', true));
    }

    public function test_academic_staff_sees_students_with_source_and_status_and_can_toggle(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $staff = $this->staff('academic_staff');

        $this->actingAs($staff)->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('ClassQuality/ParentFeedback')
                ->where('stageId', $this->stage->id)->where('canToggle', true)
                ->where('stages.0.open', true)
                ->where('rows.0.name', 'Nguyễn An')->where('rows.0.status', 'submitted')->where('rows.0.source', 'Cổng phụ huynh'));

        $this->actingAs($staff)->put(route('class-quality.parent-feedback.toggle', $this->stage->id), ['open' => false])->assertRedirect();
        $this->assertFalse($this->stage->fresh()->feedback_open);
        $this->sendFeedback()->assertSessionHasErrors('validation');
    }

    public function test_student_without_feedback_is_pending(): void
    {
        $this->actingAs($this->staff('academic_staff'))->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('rows.0.status', 'pending')->where('rows.0.source', null));
    }

    public function test_permissions_view_vs_toggle_and_teacher_denied(): void
    {
        $lead = $this->staff('academic_lead'); // có class_quality.view nhưng không có quyền bật/tắt
        $this->actingAs($lead)->get(route('class-quality.parent-feedback'))->assertOk();
        $this->actingAs($lead)->put(route('class-quality.parent-feedback.toggle', $this->stage->id), ['open' => true])->assertForbidden();

        $teacher = $this->staff('teacher_fulltime');
        $this->actingAs($teacher)->get(route('class-quality.parent-feedback'))->assertForbidden();
        $this->actingAs($teacher)->put(route('class-quality.parent-feedback.toggle', $this->stage->id), ['open' => true])->assertForbidden();

        $this->assertTrue(Role::findByName('manager', 'web')->hasPermissionTo('class_quality.parent_feedback'));
        $this->assertTrue(Role::findByName('academic_staff', 'web')->hasPermissionTo('class_quality.parent_feedback'));
    }

    public function test_toggle_out_of_scope_class_is_not_found(): void
    {
        $other = Branch::create(['name' => 'Cơ sở B', 'code' => 'CB', 'is_active' => true]);
        $otherClass = ClassModel::create(['name' => 'Lớp B', 'code' => 'B1', 'max_capacity' => 12, 'status' => 'active', 'branch_id' => $other->id]);
        $this->stage->update(['class_id' => $otherClass->id, 'status' => SyllabusAssignment::STATUS_CLOSED]);
        $manager = $this->staff('manager'); // phạm vi chi nhánh A

        $this->actingAs($manager)->put(route('class-quality.parent-feedback.toggle', $this->stage->id), ['open' => true])->assertNotFound();
    }

    public function test_migration_backfills_open_stage_and_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_12_08_090000_add_feedback_open_to_syllabus_assignments.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('syllabus_assignments', 'feedback_open'));
        $this->assertFalse(Permission::where('name', 'class_quality.parent_feedback')->exists());

        $migration->up();
        $this->assertTrue((bool) DB::table('syllabus_assignments')->where('id', $this->stage->id)->value('feedback_open'));
        $this->assertTrue(Permission::where('name', 'class_quality.parent_feedback')->exists());
    }
}
