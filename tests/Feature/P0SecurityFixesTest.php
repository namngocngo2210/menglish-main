<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\SensitiveData;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Hồi quy cho các lỗi bảo mật P0 #1–#6 và lỗi lộ hồ sơ nhân sự (B3)
 * trong docs/audit-report-and-roadmap.md.
 */
class P0SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SEC', 'is_active' => true]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    // ── #1: Chiếm quyền tài khoản Admin ───────────────────────────────

    public function test_academic_staff_cannot_take_over_admin_account(): void
    {
        $admin = $this->userWithRole('admin', ['password' => Hash::make('original-pass')]);
        $staff = $this->userWithRole('academic_staff');

        $this->actingAs($staff)->put(route('users.update', $admin), [
            'name' => 'Hacked', 'email' => $admin->email, 'branch_id' => $this->branch->id,
            'role' => 'teacher', 'password' => 'new-password-123',
        ])->assertForbidden();

        $admin->refresh();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(Hash::check('original-pass', $admin->password));

        $this->actingAs($staff)->put(route('users.roles.update', $admin), ['roles' => ['teacher']])->assertForbidden();
        $this->actingAs($staff)->get(route('users.edit', $admin))->assertForbidden();
        $this->actingAs($staff)->get(route('users.show', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_manager_cannot_lock_reset_or_downgrade_admin(): void
    {
        $admin = $this->userWithRole('admin', ['password' => Hash::make('original-pass')]);
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->post(route('users.lock', $admin))->assertForbidden();
        $this->actingAs($manager)->post(route('users.reset-password', $admin))->assertForbidden();
        $this->actingAs($manager)->put(route('users.roles.update', $admin), ['roles' => ['manager']])->assertForbidden();

        $admin->refresh();
        $this->assertNull($admin->locked_at);
        $this->assertTrue(Hash::check('original-pass', $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_academic_staff_cannot_manage_manager_but_can_manage_teacher(): void
    {
        $staff = $this->userWithRole('academic_staff');
        $manager = $this->userWithRole('manager');
        $teacher = $this->userWithRole('teacher');

        $this->actingAs($staff)->put(route('users.update', $manager), [
            'name' => $manager->name, 'email' => $manager->email, 'branch_id' => $this->branch->id, 'role' => 'teacher',
        ])->assertForbidden();
        $this->assertTrue($manager->fresh()->hasRole('manager'));

        $this->actingAs($staff)->put(route('users.update', $teacher), [
            'name' => 'Giáo viên đổi tên', 'email' => $teacher->email, 'branch_id' => $this->branch->id, 'role' => 'teacher',
        ])->assertRedirect(route('users.index'));
        $this->assertSame('Giáo viên đổi tên', $teacher->fresh()->name);
    }

    public function test_admin_can_still_manage_other_admins(): void
    {
        $admin = $this->userWithRole('admin');
        $otherAdmin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('users.lock', $otherAdmin))->assertRedirect();
        $this->assertNotNull($otherAdmin->fresh()->locked_at);
    }

    // ── B3: Danh sách nhân sự nhúng CCCD / lương ─────────────────────

    public function test_staff_list_does_not_embed_sensitive_profile_for_non_payroll_roles(): void
    {
        $staff = $this->userWithRole('academic_staff');
        $this->userWithRole('teacher', [
            'id_card_number' => '001099012345', 'base_salary' => 12345678, 'created_by' => $staff->id,
        ]);

        $html = $this->actingAs($staff)->get(route('users.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('001099012345', $html);
        $this->assertStringNotContainsString('12345678', $html);

        $admin = $this->userWithRole('admin');
        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('001099012345', $html);
    }

    // ── #2: Upload file chạy được code ───────────────────────────────

    private function studentAccount(): array
    {
        $user = $this->userWithRole('student');
        $student = Student::create([
            'user_id' => $user->id, 'name' => 'HV Upload', 'code' => 'HV-UP', 'phone' => '0900000011',
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);

        return [$user, $student];
    }

    private function gifNamed(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

        return new UploadedFile($path, $clientName, 'image/gif', null, true);
    }

    public function test_homework_upload_rejects_php_file(): void
    {
        Storage::fake('public');
        [$user, $student] = $this->studentAccount();

        $shell = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "pwned"; ?>');

        $this->actingAs($user)->post(route('portal.student.homework.submit'), [
            'student_id' => $student->id, 'homework_type' => 'workbook', 'attachment' => $shell,
        ])->assertSessionHasErrors('attachment');

        $this->assertSame([], Storage::disk('public')->allFiles('homework_submissions'));
    }

    public function test_homework_upload_uses_content_extension_not_client_name(): void
    {
        Storage::fake('public');
        [$user, $student] = $this->studentAccount();

        $this->actingAs($user)->post(route('portal.student.homework.submit'), [
            'student_id' => $student->id, 'homework_type' => 'workbook', 'attachment' => $this->gifNamed('photo.gif'),
        ])->assertSessionHasNoErrors();

        $files = Storage::disk('public')->allFiles('homework_submissions');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.gif', $files[0]);
        $this->assertStringNotContainsString('photo', $files[0]);
    }

    public function test_task_proof_image_rejects_php_polyglot(): void
    {
        Storage::fake('public');
        $manager = $this->userWithRole('manager');
        $task = WorkTask::create([
            'title' => 'Việc test', 'assignee_id' => $manager->id, 'creator_id' => $manager->id, 'status' => 'pending',
        ]);

        $this->actingAs($manager)->post(route('tasks.complete', $task->id), [
            'proof_image' => $this->gifNamed('shell.php'),
        ])->assertSessionHasErrors('proof_image');

        $this->assertSame([], Storage::disk('public')->allFiles('task_proofs'));
    }

    // ── #3: Màn mockup cũ lộ dữ liệu học viên ────────────────────────

    public function test_legacy_mockup_screens_are_admin_only(): void
    {
        AcademicRecord::create([
            'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback',
            'module' => 'student_portal', 'record_code' => 'FB-1', 'title' => 'Phản hồi bí mật của học viên khác',
            'status' => 'new', 'data' => ['student_name' => 'Người khác'],
        ]);
        $student = $this->userWithRole('student');
        $sales = $this->userWithRole('sales_consultant');

        // Không phải Admin: chuyển sang màn thật (tự kiểm tra quyền), không nhận dữ liệu mockup
        $this->actingAs($student)->get('/parent-portal/07_phu_huynh_gui_feedback')
            ->assertRedirect(route('portal.student.feedback'));
        $this->actingAs($student)->get('/academic-system/04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback?embed=1')
            ->assertRedirect(route('portal.student.feedback'));
        // Màn không có bản thật tương ứng thì chặn hẳn
        $this->actingAs($sales)->get('/academic-system/01_Web_Admin/13_tao_lop_moi_khong_ton_tai')->assertForbidden();
        $this->actingAs($student)->get('/academic-system')->assertForbidden();
        $this->actingAs($student)->get('/mockup-hub')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/academic-system/records')->assertForbidden();
        $this->actingAs($student)->get(route('academic.reports'))->assertForbidden();

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->get('/academic-system/04_Cong_Phu_Huynh_Hoc_Sinh/07_phu_huynh_gui_feedback')
            ->assertOk()->assertSee('Phản hồi bí mật của học viên khác', false);
    }

    // ── #4: Ghi chú nội bộ ticket ────────────────────────────────────

    public function test_internal_ticket_notes_are_hidden_from_creator_and_not_emailed(): void
    {
        Mail::fake();
        $student = $this->userWithRole('student');
        $manager = $this->userWithRole('manager');

        $ticket = SupportTicket::create([
            'code' => 'TK-SEC-1', 'title' => 'Hỏi học phí', 'category' => 'other', 'priority' => 'medium',
            'description' => 'Mô tả', 'creator_id' => $student->id, 'status' => 'open',
        ]);
        TicketMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $student->id, 'message' => 'Mô tả', 'is_internal_note' => false]);

        $this->actingAs($manager)->post(route('tickets.messages.store', $ticket->id), [
            'message' => 'NOI-BO: khach nay hay no phi', 'is_internal_note' => 1,
        ])->assertRedirect();

        $this->assertDatabaseMissing('admin_notifications', ['user_id' => $student->id, 'type' => 'ticket_message']);

        $html = $this->actingAs($student)->get(route('tickets.show', $ticket->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('NOI-BO', $html);
        $this->assertStringNotContainsString('name="is_internal_note"', $html);

        $this->actingAs($manager)->get(route('tickets.show', $ticket->id))->assertOk()->assertSee('NOI-BO');
    }

    // ── #5: Học viên / giáo viên xem lớp người khác ──────────────────

    public function test_teacher_sees_only_own_classes_and_student_cannot_browse_classes(): void
    {
        $teacher = $this->userWithRole('teacher');
        $otherTeacher = $this->userWithRole('teacher');
        ClassModel::create(['name' => 'Lớp của tôi', 'code' => 'MINE-01', 'branch_id' => $this->branch->id, 'teacher_id' => $teacher->id, 'status' => 'active']);
        $other = ClassModel::create(['name' => 'Lớp người khác', 'code' => 'OTHER-01', 'branch_id' => $this->branch->id, 'teacher_id' => $otherTeacher->id, 'status' => 'active']);
        Student::create([
            'name' => 'Học viên lớp khác', 'code' => 'HV-OTH', 'phone' => '0900000022',
            'current_class_id' => $other->id, 'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);

        foreach (['classes.index', 'classes.academic-list'] as $route) {
            $this->actingAs($teacher)->get(route($route))->assertOk()
                ->assertSee('MINE-01')->assertDontSee('OTHER-01');
        }

        $this->actingAs($teacher)->get(route('classes.profile', $other->id))->assertOk()
            ->assertDontSee('Học viên lớp khác');

        $student = $this->userWithRole('student');
        $this->actingAs($student)->get(route('classes.index'))->assertForbidden();
        $this->actingAs($student)->get(route('classes.academic-list'))->assertForbidden();

        $manager = $this->userWithRole('manager');
        $this->actingAs($manager)->get(route('classes.index'))->assertOk()->assertSee('MINE-01')->assertSee('OTHER-01');
    }

    // ── #6: Khóa bí mật trong nhật ký thao tác ───────────────────────

    public function test_activity_log_masks_secrets(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('system-config.ticket-emails.update'), [
            'mail_password' => 'super-secret-smtp',
            'mail_username' => 'noreply@example.com',
        ]);
        $this->actingAs($admin)->post(route('system-config.sepay.update'), [
            'auth_method' => 'hmac_sha256', 'secret_key' => 'sepay-secret-xyz', 'api_key' => 'sepay-api-xyz',
        ]);

        $logs = Activity::query()->get()->map(fn ($a) => json_encode($a->properties))->implode("\n");
        $this->assertNotSame('', $logs);
        $this->assertStringNotContainsString('super-secret-smtp', $logs);
        $this->assertStringNotContainsString('sepay-secret-xyz', $logs);
        $this->assertStringNotContainsString('sepay-api-xyz', $logs);
    }

    public function test_sensitive_data_masks_nested_keys(): void
    {
        $masked = SensitiveData::mask([
            '_token' => 'abc', 'secret_key' => 'sk_live', 'api_key' => 'ak', 'name' => 'Lớp A',
            'config' => ['mail_password' => 'x', 'host' => 'smtp'], 'id_card_number' => '0010',
        ]);

        $this->assertArrayNotHasKey('_token', $masked);
        $this->assertSame('***', $masked['secret_key']);
        $this->assertSame('***', $masked['api_key']);
        $this->assertSame('***', $masked['config']['mail_password']);
        $this->assertSame('smtp', $masked['config']['host']);
        $this->assertSame('***', $masked['id_card_number']);
        $this->assertSame('Lớp A', $masked['name']);
    }
}
