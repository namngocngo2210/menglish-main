<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentTuition;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — Hồ sơ học viên: phạm vi chi nhánh/lớp (Q7), 6 trạng thái (Q5), trang phân quyền
 * render phía server, lộ trình/điểm danh từ dữ liệu thật, liên kết lớp khác, thôi học.
 */
class Phase1StudentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $hn;

    private Branch $hcm;

    private Course $course;

    private ClassModel $classHn;

    private ClassModel $classHn2;

    private ClassModel $classHcm;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->hn = Branch::create(['name' => 'CN Hà Nội', 'code' => 'HN', 'is_active' => true]);
        $this->hcm = Branch::create(['name' => 'CN HCM', 'code' => 'HCM', 'is_active' => true]);
        $this->course = Course::create(['code' => 'IE', 'name' => 'IELTS', 'is_active' => true]);

        $this->teacher = $this->user('teacher', $this->hn);

        $this->classHn = ClassModel::create([
            'code' => 'HN-01', 'name' => 'Lớp Hà Nội 01', 'course_id' => $this->course->id,
            'branch_id' => $this->hn->id, 'teacher_id' => $this->teacher->id, 'status' => 'active', 'max_capacity' => 10,
        ]);
        $this->classHn2 = ClassModel::create([
            'code' => 'HN-02', 'name' => 'Lớp Hà Nội 02', 'course_id' => $this->course->id,
            'branch_id' => $this->hn->id, 'status' => 'active', 'max_capacity' => 1,
        ]);
        $this->classHcm = ClassModel::create([
            'code' => 'HCM-01', 'name' => 'Lớp HCM 01', 'course_id' => $this->course->id,
            'branch_id' => $this->hcm->id, 'status' => 'active',
        ]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $branch?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function student(string $code, Branch $branch, ?ClassModel $class = null, array $extra = []): Student
    {
        return Student::create($extra + [
            'code' => $code, 'name' => "Học viên {$code}", 'phone' => '09'.str_pad((string) crc32($code) % 100000000, 8, '0'),
            'branch_id' => $branch->id, 'current_class_id' => $class?->id, 'status' => 'studying',
        ]);
    }

    // ───────────── 3. Danh sách theo phạm vi + lọc ─────────────

    public function test_manager_and_academic_staff_only_see_students_of_their_branch(): void
    {
        $this->student('HV-HN', $this->hn, $this->classHn);
        $this->student('HV-HCM', $this->hcm, $this->classHcm);

        foreach (['manager', 'academic_staff'] as $role) {
            $this->actingAs($this->user($role, $this->hn))->get(route('students.index'))
                ->assertOk()->assertSee('Học viên HV-HN')->assertDontSee('Học viên HV-HCM');
        }

        $this->actingAs($this->user('admin'))->get(route('students.index'))
            ->assertOk()->assertSee('Học viên HV-HN')->assertSee('Học viên HV-HCM');
    }

    public function test_branch_user_sees_additional_branches_granted(): void
    {
        $this->student('HV-HCM', $this->hcm, $this->classHcm);
        $manager = $this->user('manager', $this->hn);
        $manager->branches()->attach($this->hcm->id);

        $this->actingAs($manager)->get(route('students.index'))->assertOk()->assertSee('Học viên HV-HCM');
    }

    public function test_detail_pages_are_404_outside_scope_or_for_unknown_id(): void
    {
        $other = $this->student('HV-HCM', $this->hcm, $this->classHcm);
        $this->student('HV-HN', $this->hn, $this->classHn);
        $staff = $this->user('academic_staff', $this->hn);

        $this->actingAs($staff)->get(route('students.show', $other->id))->assertNotFound();
        $this->actingAs($staff)->get(route('students.scoped', $other->id))->assertNotFound();
        $this->actingAs($staff)->put(route('students.status.update', $other->id), ['status' => 'deferred'])->assertNotFound();
        // Trước đây id sai => hiện học viên đầu tiên.
        $this->actingAs($staff)->get(route('students.show', 999999))->assertNotFound();
        $this->actingAs($staff)->get(route('students.scoped', 'HV-KHONG-CO'))->assertNotFound();
    }

    public function test_teacher_scope_is_limited_to_students_of_their_classes(): void
    {
        $mine = $this->student('HV-MINE', $this->hn, $this->classHn);
        $linked = $this->student('HV-LINK', $this->hn, $this->classHn2);
        ClassEnrollment::create(['student_id' => $linked->id, 'class_id' => $this->classHn->id, 'status' => 'pending']);
        $notMine = $this->student('HV-OTHER', $this->hn, $this->classHn2);

        $ids = Student::visibleTo($this->teacher)->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($linked->id, $ids);
        $this->assertNotContains($notMine->id, $ids);
        $this->assertSame([], Student::visibleTo($this->user('assistant', $this->hn))->pluck('id')->all());
    }

    public function test_index_filters_by_class_including_linked_students_and_by_status(): void
    {
        $this->student('HV-A', $this->hn, $this->classHn);
        $b = $this->student('HV-B', $this->hn, $this->classHn2, ['status' => 'deferred']);
        ClassEnrollment::create(['student_id' => $b->id, 'class_id' => $this->classHn->id, 'status' => 'completed']);
        $this->student('HV-C', $this->hn, $this->classHn2);
        $staff = $this->user('academic_staff', $this->hn);

        $this->actingAs($staff)->get(route('students.index', ['class_id' => $this->classHn->id]))
            ->assertOk()->assertSee('Học viên HV-A')->assertSee('Học viên HV-B')->assertDontSee('Học viên HV-C')
            ->assertSee('Lớp Hà Nội 02')->assertDontSee('Lớp HCM 01');

        $this->actingAs($staff)->get(route('students.index', ['status' => 'deferred']))
            ->assertOk()->assertSee('Học viên HV-B')->assertDontSee('Học viên HV-A');

        $response = $this->actingAs($staff)->get(route('students.index'));
        foreach (Student::STATUSES as $label) {
            $response->assertSee($label);
        }
    }

    // ───────────── 5. Tạo mới: trạng thái ban đầu + phạm vi ─────────────

    public function test_store_student_starts_waiting_start_with_unique_codes(): void
    {
        $staff = $this->user('academic_staff', $this->hn);
        $existing = $this->student('HV-00002', $this->hn);
        $existing->delete(); // mã đã xóa mềm vẫn không được cấp lại

        foreach (['0911000001', '0911000002'] as $phone) {
            $this->actingAs($staff)->post(route('students.store'), ['name' => "HV {$phone}", 'phone' => $phone])
                ->assertRedirect(route('students.index'));
        }

        $created = Student::whereIn('phone', ['0911000001', '0911000002'])->orderBy('id')->get();
        $this->assertSame(['HV-00003', 'HV-00004'], $created->pluck('code')->all());
        $this->assertSame(['waiting_start', 'waiting_start'], $created->pluck('status')->all());
        $this->assertSame([$this->hn->id, $this->hn->id], $created->pluck('branch_id')->all());
    }

    public function test_store_student_rejects_other_branch_and_full_class(): void
    {
        $staff = $this->user('academic_staff', $this->hn);

        $this->actingAs($staff)->post(route('students.store'), ['name' => 'X', 'phone' => '0911', 'branch_id' => $this->hcm->id])
            ->assertSessionHasErrors('branch_id');

        $this->student('HV-FULL', $this->hn, $this->classHn2);
        ClassEnrollment::create(['student_id' => Student::first()->id, 'class_id' => $this->classHn2->id, 'status' => 'completed']);
        $this->actingAs($staff)->post(route('students.store'), ['name' => 'Y', 'phone' => '0912', 'current_class_id' => $this->classHn2->id])
            ->assertSessionHasErrors('current_class_id');

        $this->actingAs($staff)->post(route('students.store'), ['name' => 'Z', 'phone' => '0913', 'current_class_id' => $this->classHn->id])
            ->assertRedirect();
        $z = Student::where('phone', '0913')->firstOrFail();
        $this->assertSame($this->classHn->id, $z->current_class_id);
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $z->id, 'class_id' => $this->classHn->id, 'status' => 'pending']);
    }

    // ───────────── 2. Trang phân quyền render phía server ─────────────

    public function test_scoped_page_only_renders_tuition_for_users_with_tuition_view(): void
    {
        $student = $this->student('HV-TUI', $this->hn, $this->classHn);
        StudentTuition::create([
            'student_id' => $student->id, 'total_amount' => 7300000, 'final_amount' => 7300000,
            'paid_amount' => 1000000, 'debt_amount' => 6300000, 'status' => 'partial',
        ]);

        // academic_lead: có student.view, không có tuition.view / student.update
        $lead = $this->user('academic_lead', $this->hn);
        $this->actingAs($lead)->get(route('students.scoped', $student->id))
            ->assertOk()
            ->assertSee('Học viên HV-TUI')
            ->assertDontSee('7,300,000')->assertDontSee('6,300,000')
            ->assertDontSee('data-section="tuition"', false)
            ->assertDontSee('data-section="contact"', false)
            ->assertDontSee('currentRole', false)
            ->assertSee('data-section="academic"', false);

        $this->actingAs($this->user('academic_staff', $this->hn))->get(route('students.scoped', $student->id))
            ->assertOk()->assertSee('7,300,000')->assertSee('6,300,000')->assertSee('data-section="contact"', false);
    }

    public function test_scoped_page_has_no_fake_tuition_fallback(): void
    {
        $student = $this->student('HV-NOTUI', $this->hn, $this->classHn);

        $this->actingAs($this->user('academic_staff', $this->hn))->get(route('students.scoped', $student->id))
            ->assertOk()->assertSee('Chưa có sổ học phí')->assertDontSee('12,500,000');
    }

    // ───────────── 4. Chi tiết: lộ trình + điểm danh thật, form sửa theo quyền ─────────────

    public function test_show_uses_real_sessions_and_attendance_of_the_student(): void
    {
        $student = $this->student('HV-SHOW', $this->hn, $this->classHn);
        $classmate = $this->student('HV-MATE', $this->hn, $this->classHn);

        $past = ClassSession::create([
            'class_id' => $this->classHn->id, 'branch_id' => $this->hn->id, 'date' => now()->subDays(2)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'room' => 'P-ROADMAP', 'teacher_id' => $this->teacher->id, 'status' => 'completed',
        ]);
        ClassSession::create([
            'class_id' => $this->classHn->id, 'branch_id' => $this->hn->id, 'date' => now()->addDays(5)->toDateString(),
            'start_time' => '18:00', 'end_time' => '19:30', 'status' => 'scheduled',
        ]);
        // Buổi của lớp khác => không thuộc lộ trình.
        ClassSession::create([
            'class_id' => $this->classHcm->id, 'date' => now()->toDateString(), 'start_time' => '08:00', 'end_time' => '09:00', 'room' => 'P-KHAC',
        ]);

        StudentAttendance::create([
            'class_id' => $this->classHn->id, 'class_session_id' => $past->id, 'student_id' => $student->id,
            'session_date' => $past->date, 'status' => 'absent', 'note' => 'Ốm có báo',
        ]);
        StudentAttendance::create([
            'class_id' => $this->classHn->id, 'class_session_id' => $past->id, 'student_id' => $classmate->id,
            'session_date' => $past->date, 'status' => 'present', 'note' => 'Ghi chú của bạn cùng lớp',
        ]);

        $this->actingAs($this->user('academic_staff', $this->hn))->get(route('students.show', $student->id))
            ->assertOk()
            ->assertSee('P-ROADMAP')->assertDontSee('P-KHAC')
            ->assertSee('Sắp tới')
            ->assertSee('Ốm có báo')->assertDontSee('Ghi chú của bạn cùng lớp')
            ->assertSee('Lịch sử điểm danh')
            ->assertSee('0%'); // 0/1 buổi có mặt
    }

    public function test_show_empty_states_without_class_or_attendance(): void
    {
        $student = $this->student('HV-EMPTY', $this->hn);

        $this->actingAs($this->user('academic_staff', $this->hn))->get(route('students.show', $student->id))
            ->assertOk()
            ->assertSee('Chưa có buổi học nào')
            ->assertSee('Chưa có điểm danh')
            ->assertSee('Chưa xếp lớp');
    }

    public function test_show_hides_edit_form_tuition_and_fake_role_badge_without_permissions(): void
    {
        $student = $this->student('HV-PERM', $this->hn, $this->classHn);

        $this->actingAs($this->user('academic_lead', $this->hn))->get(route('students.show', $student->id))
            ->assertOk()
            ->assertDontSee('data-testid="student-edit-form"', false)
            ->assertDontSee('Quyền: Quản trị viên')
            ->assertSee('Học thuật độc lập')
            ->assertDontSee('data-section="tuition"', false)
            ->assertDontSee('data-testid="link-class-form"', false);

        $this->actingAs($this->user('academic_staff', $this->hn))->get(route('students.show', $student->id))
            ->assertOk()
            ->assertSee('data-testid="student-edit-form"', false)
            ->assertSee('Học vụ (Academic Staff)')
            ->assertSee('data-section="tuition"', false)
            ->assertSee('data-testid="link-class-form"', false);
    }

    // ───────────── 4. Liên kết lớp khác ─────────────

    public function test_link_additional_class_keeps_main_class_and_checks_capacity(): void
    {
        $student = $this->student('HV-LINK', $this->hn, $this->classHn);
        $staff = $this->user('academic_staff', $this->hn);

        $this->actingAs($staff)->post(route('students.link-class', $student->id), ['class_id' => $this->classHn2->id])
            ->assertRedirect(route('students.show', $student->id));
        $this->assertSame($this->classHn->id, $student->fresh()->current_class_id);
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $student->id, 'class_id' => $this->classHn2->id, 'status' => 'pending']);

        // Linking lần 2 cùng lớp => lỗi; lớp 02 (sĩ số 1) đã đầy => học viên khác bị chặn.
        $this->actingAs($staff)->post(route('students.link-class', $student->id), ['class_id' => $this->classHn2->id])
            ->assertSessionHasErrors('class_id');
        $other = $this->student('HV-OTHER', $this->hn, $this->classHn);
        $this->actingAs($staff)->post(route('students.link-class', $other->id), ['class_id' => $this->classHn2->id])
            ->assertSessionHasErrors('class_id');
        // Lớp chi nhánh khác => không thuộc phạm vi.
        $this->actingAs($staff)->post(route('students.link-class', $student->id), ['class_id' => $this->classHcm->id])
            ->assertSessionHasErrors('class_id');

        // Hiển thị lớp liên kết trên hồ sơ.
        $this->actingAs($staff)->get(route('students.show', $student->id))->assertSee('(Liên kết)');
    }

    public function test_link_class_requires_assign_class_permission(): void
    {
        $student = $this->student('HV-NOPERM', $this->hn, $this->classHn);

        $this->actingAs($this->user('academic_lead', $this->hn))
            ->post(route('students.link-class', $student->id), ['class_id' => $this->classHn2->id])
            ->assertForbidden();
    }

    // ───────────── 6. Thôi học ─────────────

    public function test_dropping_student_removes_from_class_lists_but_keeps_history(): void
    {
        $student = $this->student('HV-DROP', $this->hn, $this->classHn);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $this->classHn->id, 'status' => 'completed']);
        StudentAttendance::create([
            'class_id' => $this->classHn->id, 'student_id' => $student->id, 'session_date' => now()->subDay()->toDateString(), 'status' => 'present',
        ]);
        $staff = $this->user('academic_staff', $this->hn);

        $this->actingAs($staff)->put(route('students.status.update', $student->id), ['status' => 'dropped'])->assertRedirect();

        $student->refresh();
        $this->assertSame('dropped', $student->status);
        $this->assertNull($student->current_class_id);
        $this->assertFalse($this->classHn->students()->whereKey($student->id)->exists());
        $this->assertDatabaseHas('class_enrollments', ['student_id' => $student->id, 'class_id' => $this->classHn->id, 'status' => 'dropped']);
        $this->assertSame(1, StudentAttendance::where('student_id', $student->id)->count());

        // Không còn trong lọc theo lớp, vẫn tìm thấy hồ sơ.
        $this->actingAs($staff)->get(route('students.index', ['class_id' => $this->classHn->id]))->assertDontSee('Học viên HV-DROP');
        $this->actingAs($staff)->get(route('students.index', ['status' => 'dropped']))->assertSee('Học viên HV-DROP');

        // Không liên kết lớp mới cho học viên đã thôi học.
        $this->actingAs($staff)->post(route('students.link-class', $student->id), ['class_id' => $this->classHn2->id])
            ->assertSessionHasErrors('class_id');
    }
}
