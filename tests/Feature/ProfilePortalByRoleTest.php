<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Trang cá nhân ("cổng") hiển thị đúng công việc của từng vai trò: Học vụ / Học thuật không thấy giờ dạy, ca dạy;
 * GV / TA thấy lớp và giờ dạy; học viên chỉ thấy tài khoản + lối về cổng học viên.
 */
class ProfilePortalByRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_academic_staff_sees_office_work_not_teaching_widgets(): void
    {
        $user = $this->userWithRole('academic_staff');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Học vụ')
            ->assertDontSee('Academic_staff')
            ->assertDontSee('Giờ dạy tháng này')
            ->assertDontSee('Ca dạy gần nhất')
            ->assertSee('Báo cáo ngày')
            ->assertSee('Màn hình công việc của bạn')
            ->assertSee(route('crm.pipeline'), false)
            ->assertSee(route('students.index'), false)
            ->assertSee('Lương của tôi');
    }

    public function test_academic_lead_sees_approvals_and_weekly_report(): void
    {
        $user = $this->userWithRole('academic_lead');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Học thuật')
            ->assertDontSee('Giờ dạy tháng này')
            ->assertSee('Việc cần duyệt')
            ->assertSee('Báo cáo tuần')
            ->assertSee(route('syllabus.documents'), false);
    }

    public function test_teacher_parttime_and_fulltime_see_teaching_portal(): void
    {
        foreach (['teacher_parttime', 'teacher_fulltime', 'teacher'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get(route('profile.edit'))
                ->assertOk()
                ->assertSee('Giờ dạy tháng này')
                ->assertSee('Lớp đang dạy')
                ->assertSee('Ca dạy gần nhất')
                ->assertSee('Giảng dạy &amp; Nhiệm vụ', false)
                ->assertSee(route('teacher.home'), false)
                ->assertDontSee(route('crm.pipeline'), false);
        }
    }

    public function test_assistant_sees_ta_tasks_and_assisting_hours(): void
    {
        $user = $this->userWithRole('assistant');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Trợ giảng')
            ->assertSee('Giờ trợ giảng tháng này')
            ->assertSee('Lớp đang trợ giảng')
            ->assertSee(route('portal.ta-tasks'), false);
    }

    public function test_student_only_sees_account_settings_and_portal_links(): void
    {
        $user = $this->userWithRole('student');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Về Cổng học viên')
            ->assertSee(route('portal.student.homework'), false)
            ->assertDontSee('Lương kỳ gần nhất')
            ->assertDontSee('Phiếu Lương Cá Nhân')
            ->assertDontSee('Giờ dạy tháng này')
            ->assertDontSee('#NV-')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Profile/Edit')->where('initialTab', 'settings'));
    }

    public function test_forced_password_change_opens_account_settings_tab(): void
    {
        $user = $this->userWithRole('academic_staff', ['must_change_password' => true]);

        $this->actingAs($user)->get(route('profile.edit', ['force_password' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Profile/Edit')->where('initialTab', 'settings'));
    }
}
