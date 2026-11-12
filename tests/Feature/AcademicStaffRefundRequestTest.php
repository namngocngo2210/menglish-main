<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** TK-2026-0101 — Học vụ lập yêu cầu khất nợ / nghỉ giữa khóa (chi nhánh mình), không được duyệt / từ chối. */
class AcademicStaffRefundRequestTest extends TestCase
{
    use RefreshDatabase;

    private Branch $own;

    private Branch $other;

    private User $staff;

    private Student $ownStudent;

    private Student $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->own = Branch::create(['name' => 'Ba Đình', 'code' => 'BD', 'is_active' => true]);
        $this->other = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->staff = User::factory()->create(['branch_id' => $this->own->id, 'is_active' => true]);
        $this->staff->assignRole('academic_staff');

        $course = Course::create(['code' => 'AS-C', 'name' => 'AS', 'tuition_fee' => 6000000, 'total_lessons' => 10, 'is_active' => true]);
        $this->ownStudent = $this->student('HV-AS-1', $this->own, $course);
        $this->otherStudent = $this->student('HV-AS-2', $this->other, $course);
    }

    private function student(string $code, Branch $branch, Course $course): Student
    {
        $class = ClassModel::create(['code' => $code.'-L', 'name' => 'Lớp '.$code, 'course_id' => $course->id, 'branch_id' => $branch->id, 'max_capacity' => 10, 'tuition_fee' => 6000000, 'status' => 'active']);
        $student = Student::create(['code' => $code, 'name' => 'Học viên '.$code, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $branch->id, 'status' => 'studying', 'current_class_id' => $class->id]);
        StudentTuition::create(['student_id' => $student->id, 'branch_id' => $branch->id, 'class_id' => $class->id, 'session_count' => 10,
            'total_amount' => 6000000, 'final_amount' => 6000000, 'paid_amount' => 0, 'debt_amount' => 6000000, 'due_date' => now()->addDays(5), 'status' => 'unpaid']);

        return $student;
    }

    private function extension(Student $student)
    {
        return $this->actingAs($this->staff)->post(route('tuition.refunds.store'), [
            'student_id' => $student->id, 'type' => 'extension', 'extended_due_date' => now()->addDays(20)->toDateString(), 'reason' => 'Khó khăn',
        ]);
    }

    public function test_academic_staff_has_request_permission_but_no_approve_permissions(): void
    {
        $this->assertTrue($this->staff->can('refund_transfer.request'));
        foreach (['refund_transfer.approve', 'refund_transfer.approve_refund', 'refund_transfer.approve_transfer', 'refund_transfer.reject'] as $permission) {
            $this->assertFalse($this->staff->can($permission), $permission);
        }
    }

    public function test_refunds_page_shows_request_buttons_and_scopes_student_pickers_to_own_branch(): void
    {
        $this->actingAs($this->staff)->get(route('tuition.refunds'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', fn ($can) => ($can['refund_transfer.request'] ?? false) === true
                    && ! isset($can['refund_transfer.approve']) && ! isset($can['refund_transfer.reject']))
                ->where('approvableTypes', [])
                ->where('canRejectAny', false)
                ->where('targets', fn ($t) => collect($t)->pluck('id')->all() === [(string) $this->ownStudent->id]));
    }

    public function test_can_create_extension_and_refund_for_own_branch(): void
    {
        $this->extension($this->ownStudent)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('tuition.refunds.store'), [
            'student_id' => $this->ownStudent->id, 'type' => 'refund', 'refund_amount' => 0, 'reason' => 'Nghỉ', 'no_transfer_reason' => 'Không có người nhận',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['extension', 'refund'], TuitionRefundRequest::orderBy('id')->pluck('type')->all());
    }

    public function test_cannot_create_request_for_other_branch_student_or_transfer_to_other_branch(): void
    {
        $this->extension($this->otherStudent)->assertForbidden();

        $this->actingAs($this->staff)->post(route('tuition.refunds.store'), [
            'student_id' => $this->ownStudent->id, 'type' => 'transfer', 'target_student_id' => $this->otherStudent->id, 'reason' => 'x',
        ])->assertStatus(403);
        $this->assertSame(0, TuitionRefundRequest::count());
    }

    public function test_cannot_approve_or_reject(): void
    {
        $this->extension($this->ownStudent);
        $request = TuitionRefundRequest::sole();

        $this->actingAs($this->staff)->post(route('tuition.refunds.approve', $request->id))->assertForbidden();
        $this->actingAs($this->staff)->post(route('tuition.refunds.reject', $request->id), ['reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }
}
