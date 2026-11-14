<?php

namespace Tests\Feature;

use App\Http\Controllers\ParentFeedbackController;
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

    public function test_closed_portal_page_renders_read_only_without_update_form(): void
    {
        $this->sendFeedback(); // rejected: closed => nothing saved; open then close to have a saved record
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $this->get(route('portal.student.feedback', $this->student->id))->assertSee('Cập nhật feedback');
        $this->stage->update(['feedback_open' => false]);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertSee('Đợt thu thập feedback đã đóng')->assertSee('Lớp học tốt')
            ->assertDontSee('Cập nhật feedback')->assertDontSee('Gửi feedback');
    }

    public function test_portal_page_reports_open_flag(): void
    {
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', false)->where('lastFeedback', null));
        $this->stage->update(['feedback_open' => true]);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', true));
    }

    public function test_academic_staff_sees_students_with_source_and_status_and_can_toggle(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $this->assertSame(1, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)
            ->where('data->stage_name', $this->stage->stage_name)->count());
        $staff = $this->staff('academic_staff');

        $this->actingAs($staff)->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('ClassQuality/ParentFeedback')
                ->where('stageId', $this->stage->id)->where('canToggle', true)->where('canManage', true)
                ->has('rows', 1)->where('rows.0.handled', false)
                ->where('rows.0.content', 'Lớp học tốt')
                ->where('stages.0.open', true)
                ->where('rows.0.name', 'Nguyễn An')->where('rows.0.status', 'submitted')->where('rows.0.source', 'Cổng phụ huynh'));

        $this->actingAs($staff)->put(route('class-quality.parent-feedback.toggle', $this->stage->id), ['open' => false])->assertRedirect();
        $this->assertFalse($this->stage->fresh()->feedback_open);
        $this->sendFeedback()->assertSessionHasErrors('validation');
    }

    public function test_student_without_feedback_is_pending(): void
    {
        $this->actingAs($this->staff('academic_staff'))->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('rows.0.status', 'pending')->where('rows.0.source', null)
                ->where('rows.0.record_id', null)->where('rows.0.handled', false)
                ->where('rows.0.rating', null)->where('rows.0.content', null)->where('rows.0.categories', []));
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

    private function feedbackRecord(): AcademicRecord
    {
        return AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->firstOrFail();
    }

    private function manualFeedback(array $extra = [])
    {
        return $this->post(route('class-quality.parent-feedback.manual'), $extra + [
            'student_id' => $this->student->id,
            'stage_id' => $this->stage->id,
            'muc_do_hai_long' => 4,
            'categories' => ['Học thuật'],
            'noi_dung_feedback' => 'CM ghi nhận góp ý',
        ]);
    }

    public function test_completed_stage_is_the_shared_fallback_and_rejects_portal_submission(): void
    {
        $this->stage->update(['status' => SyllabusAssignment::STATUS_CLOSED]);
        $this->assertSame($this->stage->id, SyllabusAssignment::feedbackStageForClass($this->class->id)?->id);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', false)
                ->where('stageName', $this->stage->stage_name)->where('lastFeedback', null));
        $this->sendFeedback()->assertSessionHasErrors('validation');
        $this->assertSame(0, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->count());
        $this->actingAs($this->staff('academic_staff'))->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('stageId', $this->stage->id));
    }

    public function test_resolver_prefers_open_assignment_over_newer_completed_assignment(): void
    {
        $newer = $this->stage->replicate();
        $newer->fill(['status' => SyllabusAssignment::STATUS_CLOSED, 'stage_name' => 'Chặng 2'])->save();
        $this->assertGreaterThan($this->stage->id, $newer->id);
        $this->assertSame($this->stage->id, SyllabusAssignment::feedbackStageForClass($this->class->id)?->id);
        $this->actingAs($this->staff('academic_staff'))->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('stageId', $this->stage->id));
    }

    public function test_last_feedback_only_contains_the_resolved_stage_even_when_closed(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $this->stage->update(['feedback_open' => false]);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', false)->where('lastFeedback.id', $record->id));

        $this->stage->update(['status' => SyllabusAssignment::STATUS_CLOSED]);
        $next = $this->stage->replicate();
        $next->fill(['status' => SyllabusAssignment::STATUS_OPEN, 'stage_name' => 'Chặng 2'])->save();
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('stageName', 'Chặng 2')
                ->where('feedbackOpen', false)->where('lastFeedback', null));
        $this->assertTrue($record->fresh()->exists);
    }

    public function test_general_feedback_is_open_without_assignments_or_without_class(): void
    {
        $this->stage->delete();
        $this->assertNull(SyllabusAssignment::feedbackStageForClass($this->class->id));
        $this->assertNull(SyllabusAssignment::feedbackStageForClass(null));
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', true)->where('stageName', 'Góp ý chung'));
        $this->sendFeedback(['stage_name' => 'Forged stage'])->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $this->assertSame('', $record->data['stage_name']);
        $this->student->update(['current_class_id' => null]);
        $this->get(route('portal.student.feedback', $this->student->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('feedbackOpen', true)
                ->where('hasClass', false)->where('lastFeedback.id', $record->id));
        $this->sendFeedback(['stage_name' => 'Forged stage'])->assertSessionHasNoErrors();
        $this->assertSame('', $record->fresh()->data['stage_name']);
        $this->assertSame(1, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->count());
    }

    public function test_manual_entry_works_when_closed_and_exposes_cm_row(): void
    {
        $this->actingAs($this->staff('academic_staff'));
        $this->manualFeedback()->assertRedirect()->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $this->assertSame('cm', $record->data['source']);
        $this->assertFalse($record->data['handled']);
        $this->assertSame('student_portal', $record->module);
        $this->assertStringStartsWith('FB-', $record->record_code);
        $this->get(route('class-quality.parent-feedback', ['class_id' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('rows', 1)
                ->where('rows.0.record_id', $record->id)->where('rows.0.status', 'submitted')
                ->where('rows.0.source', 'CM nhập tay')->where('rows.0.rating', 4)
                ->where('rows.0.content', 'CM ghi nhận góp ý')->where('rows.0.categories', ['Học thuật']));
        $this->manualFeedback(['noi_dung_feedback' => 'Đã cập nhật'])->assertSessionHasNoErrors();
        $this->assertSame(1, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->count());
        $this->assertSame('Đã cập nhật', $record->fresh()->data['noi_dung_feedback']);
    }

    public function test_manual_update_preserves_portal_source_and_handling_survives_portal_resubmit(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $staff = $this->staff('academic_staff');
        $this->actingAs($staff)->put(route('class-quality.parent-feedback.handled', $record), ['handled' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $handledData = $record->fresh()->data;
        $this->assertTrue($handledData['handled']);
        $this->assertSame($staff->id, $handledData['handled_by']);
        $this->assertNotNull($handledData['handled_at']);
        $this->manualFeedback()->assertSessionHasNoErrors();
        $this->assertSame('portal', $record->fresh()->data['source']);
        $this->sendFeedback(['noi_dung_feedback' => 'Phụ huynh cập nhật'])->assertSessionHasNoErrors();
        $saved = $record->fresh()->data;
        $this->assertSame('portal', $saved['source']);
        foreach (['handled', 'handled_at', 'handled_by'] as $key) {
            $this->assertSame($handledData[$key], $saved[$key]);
        }
        $this->assertSame(1, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->count());
        $this->actingAs($staff)->put(route('class-quality.parent-feedback.handled', $record), ['handled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($record->fresh()->data['handled']);
    }

    public function test_handled_and_manual_require_permission_and_visible_class(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $this->actingAs($this->staff('academic_lead'))
            ->put(route('class-quality.parent-feedback.handled', $record), ['handled' => true])->assertForbidden();
        $this->manualFeedback()->assertForbidden();

        $other = Branch::create(['name' => 'Cơ sở B', 'code' => 'CB', 'is_active' => true]);
        $this->class->update(['branch_id' => $other->id]);
        $this->actingAs($this->staff('manager'))
            ->put(route('class-quality.parent-feedback.handled', $record), ['handled' => true])->assertNotFound();
        $this->manualFeedback()->assertNotFound();
        $this->assertFalse($record->fresh()->data['handled']);
    }

    public function test_manual_entry_validates_content_categories_and_roster(): void
    {
        $this->actingAs($this->staff('academic_staff'));
        $this->manualFeedback(['muc_do_hai_long' => 0, 'categories' => [], 'noi_dung_feedback' => '   '])
            ->assertSessionHasErrors('validation');
        $this->manualFeedback(['categories' => ['Invalid'], 'muc_do_hai_long' => 6, 'noi_dung_feedback' => str_repeat('x', 5001)])
            ->assertSessionHasErrors(['categories.0', 'muc_do_hai_long', 'noi_dung_feedback']);
        $this->student->update(['current_class_id' => null]);
        $this->manualFeedback()->assertNotFound();
        $this->assertSame(0, AcademicRecord::where('screen_key', ParentFeedbackController::SCREEN_KEY)->count());
    }

    public function test_handled_rejects_other_screens_and_requires_boolean(): void
    {
        $this->stage->update(['feedback_open' => true]);
        $this->sendFeedback()->assertSessionHasNoErrors();
        $record = $this->feedbackRecord();
        $this->actingAs($this->staff('academic_staff'))
            ->put(route('class-quality.parent-feedback.handled', $record), ['handled' => 'invalid'])->assertSessionHasErrors('handled');
        $record->update(['screen_key' => 'other_screen']);
        $this->put(route('class-quality.parent-feedback.handled', $record), ['handled' => true])->assertNotFound();
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
