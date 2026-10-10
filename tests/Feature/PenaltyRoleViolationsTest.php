<?php

namespace Tests\Feature;

use App\Models\Penalty;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Lỗi thường gặp khi lập biên bản đi theo vai trò của nhân sự vi phạm: mỗi vai trò chỉ được gợi ý lỗi của vai trò đó,
 * chọn lỗi có sẵn thì loại lỗi (người chốt) đi theo lỗi.
 */
class PenaltyRoleViolationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Penalty::EVIDENCE_DISK);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->manager = $this->userWithRole(Roles::MANAGER);
    }

    private function userWithRole(string ...$roles): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($roles);

        return $user;
    }

    public function test_each_role_only_gets_its_own_violations(): void
    {
        $teacher = array_keys(Penalty::commonViolationsFor([Roles::TEACHER_PARTTIME]));
        $sales = array_keys(Penalty::commonViolationsFor([Roles::SALES_CONSULTANT]));
        $academicStaff = array_keys(Penalty::commonViolationsFor([Roles::ACADEMIC_STAFF]));

        $this->assertContains('Nghỉ dạy không phép', $teacher);
        $this->assertNotContains('Thu sai / thiếu học phí', $teacher);
        $this->assertNotContains('Quá hạn SLA liên hệ khách mới', $teacher);

        $this->assertContains('Quá hạn SLA liên hệ khách mới', $sales);
        $this->assertNotContains('Nghỉ dạy không phép', $sales);
        $this->assertNotContains('Dạy sai tiến độ giáo trình', $sales);

        $this->assertContains('Quá hạn SLA chăm sóc học viên tháng đầu', $academicStaff);
        $this->assertNotContains('Nghỉ dạy không phép', $academicStaff);

        // Kiêm nhiệm: gộp lỗi của các vai trò, không lặp.
        $both = array_keys(Penalty::commonViolationsFor([Roles::ACADEMIC_LEAD, Roles::TEACHER_PARTTIME]));
        $this->assertContains('Trễ deadline mốc dự án học thuật', $both);
        $this->assertContains('Nghỉ dạy không phép', $both);
        $this->assertSame($both, array_values(array_unique($both)));

        // Không có vai trò nào được gán lỗi (Admin) → lỗi của mọi vai trò.
        $this->assertSame(Penalty::commonViolationsFor([]), Penalty::commonViolationsFor([Roles::ADMIN]));
    }

    public function test_violation_names_match_the_automatic_sla_penalties(): void
    {
        $slaViolations = collect(config('sla.rules'))->pluck('violation');

        foreach (['Gửi nhận xét sau buổi học trễ', 'Trả kết quả Big Test trễ', 'Quá hạn SLA liên hệ khách mới', 'Trễ deadline mốc dự án học thuật', 'Quá hạn SLA chăm sóc học viên tháng đầu'] as $name) {
            $this->assertContains($name, $slaViolations, "{$name} phải trùng tên lỗi SLA");
            $this->assertArrayHasKey($name, Penalty::commonViolationsFor([]));
        }
    }

    public function test_list_page_sends_roles_of_staff_and_violations_per_role(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_PARTTIME);
        $sales = $this->userWithRole(Roles::SALES_CONSULTANT);

        $this->actingAs($this->manager)->get(route('penalties.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Penalties/Index')
                ->where('users', fn ($users) => collect($users)->firstWhere('value', $teacher->id)['roles'] == [Roles::TEACHER_PARTTIME]
                    && collect($users)->firstWhere('value', $sales->id)['roles'] == [Roles::SALES_CONSULTANT])
                ->where('roleViolations.'.Roles::TEACHER_PARTTIME.'.violations', fn ($list) => collect($list)->pluck('value')->contains('Nghỉ dạy không phép')
                    && ! collect($list)->pluck('value')->contains('Quá hạn SLA liên hệ khách mới')
                    && collect($list)->firstWhere('value', 'Gửi nhận xét sau buổi học trễ')['category'] === 'academic')
                ->where('roleViolations.'.Roles::SALES_CONSULTANT.'.violations', fn ($list) => ! collect($list)->pluck('value')->contains('Nghỉ dạy không phép'))
                ->where('allViolations', fn ($list) => count($list) === count(Penalty::commonViolationsFor([]))));
    }

    public function test_known_violation_sets_its_category_free_text_keeps_the_chosen_one(): void
    {
        $teacher = $this->userWithRole(Roles::TEACHER_PARTTIME);
        $store = fn (string $type, string $category) => $this->actingAs($this->manager)->post(route('penalties.store'), [
            'user_id' => $teacher->id,
            'error_category' => $category,
            'violation_type' => $type,
            'violation_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'evidence' => UploadedFile::fake()->image('bang-chung.jpg'),
        ])->assertSessionHasNoErrors();

        $store('Gửi nhận xét sau buổi học trễ', 'operations');
        $this->assertSame('academic', Penalty::latest('id')->value('error_category'));

        $store('Nghỉ dạy không phép', 'academic');
        $this->assertSame('operations', Penalty::latest('id')->value('error_category'));

        $store('Làm hỏng máy chiếu phòng 3', 'academic');
        $this->assertSame('academic', Penalty::latest('id')->value('error_category'));
    }
}
