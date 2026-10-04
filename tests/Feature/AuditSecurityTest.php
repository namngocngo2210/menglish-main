<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Hồi quy cho đợt rà soát bảo mật 2026-10-04: khớp hồ sơ học viên theo email, đổi email, header bảo mật,
 * đánh dấu thông báo của người khác, bill học phí chéo chi nhánh, giới hạn đăng nhập theo IP.
 */
class AuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branchA = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-AUD', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Ba Đình', 'code' => 'BD-AUD', 'is_active' => true]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['is_active' => true, 'branch_id' => $this->branchA->id]);
        $user->assignRole($role);

        return $user;
    }

    private function studentWithEmail(string $email, ?Branch $branch = null): Student
    {
        return Student::create([
            'name' => 'Học viên email chung', 'code' => 'HV-AUD-'.random_int(100, 999), 'phone' => '0900000001', 'email' => $email,
            'status' => 'studying', 'branch_id' => ($branch ?? $this->branchA)->id,
        ]);
    }

    public function test_staff_cannot_reach_a_student_record_by_setting_the_same_email(): void
    {
        $student = $this->studentWithEmail('phu-huynh@example.test');
        $teacher = $this->userWithRole('teacher', ['email' => 'phu-huynh@example.test']);

        $this->actingAs($teacher)
            ->post(route('portal.student.profile.update', $student->id), ['phone' => '0911111111'])
            ->assertForbidden();

        $this->assertSame('0900000001', $student->fresh()->phone);
    }

    public function test_portal_account_with_the_same_email_still_manages_the_student_record(): void
    {
        $student = $this->studentWithEmail('phu-huynh@example.test');
        $parent = $this->userWithRole('student', ['email' => 'phu-huynh@example.test']);

        $this->actingAs($parent)
            ->post(route('portal.student.profile.update', $student->id), ['phone' => '0911111111'])
            ->assertRedirect();

        $this->assertSame('0911111111', $student->fresh()->phone);
    }

    public function test_staff_with_only_view_permission_cannot_edit_students_through_the_portal(): void
    {
        $student = $this->studentWithEmail('hv@example.test', $this->branchB);
        $branchStaff = $this->userWithRole('academic_staff');

        $this->actingAs($branchStaff)
            ->post(route('portal.student.profile.update', $student->id), ['phone' => '0922222222'])
            ->assertForbidden();
    }

    public function test_changing_email_requires_current_password(): void
    {
        $teacher = $this->userWithRole('teacher', ['email' => 'gv@example.test', 'password' => Hash::make('Mat-khau-cu-123')]);

        $this->actingAs($teacher)
            ->patch(route('profile.update'), ['name' => $teacher->name, 'email' => 'moi@example.test'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('gv@example.test', $teacher->fresh()->email);

        $this->actingAs($teacher)
            ->patch(route('profile.update'), ['name' => $teacher->name, 'email' => 'moi@example.test', 'current_password' => 'sai-mat-khau'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($teacher)
            ->patch(route('profile.update'), ['name' => $teacher->name, 'email' => 'moi@example.test', 'current_password' => 'Mat-khau-cu-123'])
            ->assertSessionHasNoErrors();
        $this->assertSame('moi@example.test', $teacher->fresh()->email);
    }

    public function test_changing_only_the_name_does_not_ask_for_a_password(): void
    {
        $teacher = $this->userWithRole('teacher', ['email' => 'gv@example.test']);

        $this->actingAs($teacher)
            ->patch(route('profile.update'), ['name' => 'Tên mới', 'email' => 'gv@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Tên mới', $teacher->fresh()->name);
    }

    public function test_student_portal_accounts_cannot_change_their_own_email(): void
    {
        $parent = $this->userWithRole('student', ['email' => 'ph@example.test', 'password' => Hash::make('Mat-khau-cu-123')]);

        $this->actingAs($parent)
            ->patch(route('profile.update'), ['name' => $parent->name, 'email' => 'khac@example.test', 'current_password' => 'Mat-khau-cu-123'])
            ->assertSessionHasErrors('email');
        $this->assertSame('ph@example.test', $parent->fresh()->email);
    }

    public function test_responses_carry_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = $this->userWithRole('teacher');
        $other = $this->userWithRole('teacher');
        $notification = AdminNotification::create([
            'user_id' => $owner->id, 'type' => 'info', 'title' => 'Riêng tư', 'message' => 'Nội dung', 'is_read' => false,
        ]);

        $this->actingAs($other)->postJson(route('notifications.read', $notification->id))->assertOk();
        $this->assertFalse($notification->fresh()->is_read);

        $this->actingAs($owner)->postJson(route('notifications.read', $notification->id))->assertOk();
        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_branch_staff_cannot_open_a_tuition_bill_of_another_branch(): void
    {
        $student = $this->studentWithEmail('bill@example.test', $this->branchB);
        $tuition = StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branchB->id,
            'total_amount' => 1000000, 'final_amount' => 1000000, 'paid_amount' => 0, 'debt_amount' => 1000000, 'status' => 'pending',
        ]);
        $staffA = $this->userWithRole('academic_staff');
        $staffB = $this->userWithRole('academic_staff', ['branch_id' => $this->branchB->id]);
        $this->assertTrue($staffA->can('tuition.view'));

        $this->actingAs($staffA)->get(route('crm.tuition-bill', $tuition->id))->assertNotFound();
        $this->actingAs($staffB)->get(route('crm.tuition-bill', $tuition->id))->assertOk();
    }

    public function test_login_is_throttled_per_ip_across_accounts(): void
    {
        RateLimiter::clear('login-ip|127.0.0.1');

        for ($i = 1; $i <= 30; $i++) {
            $this->post(route('login'), ['email' => "khong-ton-tai-{$i}@example.test", 'password' => 'sai-mat-khau'])
                ->assertSessionHasErrors('email');
        }

        $blocked = $this->post(route('login'), ['email' => 'nguoi-khac@example.test', 'password' => 'sai-mat-khau']);
        $blocked->assertSessionHasErrors('email');
        $this->assertStringNotContainsString(trans('auth.failed'), session('errors')->first('email'));

        RateLimiter::clear('login-ip|127.0.0.1');
    }
}
