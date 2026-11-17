<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * TK-2026-0004: modal "Thêm người dùng mới" với vai trò chính Học viên. Cơ sở chính bắt buộc; trước đây ô này để trống thì
 * bong bóng "required" của trình duyệt chặn gửi (không có thông báo rõ). Nay form gửi lên server (novalidate) và nhận
 * lỗi tiếng Việt cho từng ô; người chỉ có 1 cơ sở được chọn sẵn cơ sở đó; trường chỉ dành cho nhân sự không chặn học viên.
 */
class UserCreateStudentTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Chi nhánh Cầu Giấy', 'code' => 'CG-HV', 'is_active' => true]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Đúng các ô bắt buộc trên tab Tài khoản + cờ ẩn form luôn gửi; không có trường hồ sơ / hợp đồng / lương. */
    private function studentPayload(array $overrides = []): array
    {
        return [
            'name' => 'Nguyễn Học Viên',
            'email' => 'hocvien.moi@menglish.edu.vn',
            'phone' => '',
            'branch_id' => $this->branch->id,
            'role' => Roles::STUDENT,
            'password' => 'Password123!',
            'concurrent_roles_present' => '1',
            'weekly_days_off_present' => '1',
            'base_salary' => '',
            'hourly_rate' => '',
            'contract_type' => '',
            ...$overrides,
        ];
    }

    public static function creators(): array
    {
        return ['admin' => [Roles::ADMIN], 'học vụ' => [Roles::ACADEMIC_STAFF]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creators')]
    public function test_rejects_student_as_primary_or_concurrent_role(string $creatorRole): void
    {
        // TK-2026-0125: màn nhân sự không tạo học viên; server từ chối cả vai trò chính lẫn kiêm nhiệm khi gửi tay.
        $this->actingAs($this->actor($creatorRole))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload())
            ->assertSessionHasErrors('role');

        $this->actingAs($this->actor($creatorRole))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload(['role' => Roles::ASSISTANT, 'concurrent_roles' => [Roles::STUDENT]]))
            ->assertSessionHasErrors('concurrent_roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'hocvien.moi@menglish.edu.vn']);
    }

    public function test_update_cannot_switch_staff_to_student_but_existing_student_stays_editable(): void
    {
        $admin = $this->actor(Roles::ADMIN);
        $staff = $this->actor(Roles::ASSISTANT);
        $this->actingAs($admin)->put(route('users.update', $staff), ['name' => 'X', 'email' => $staff->email, 'branch_id' => $this->branch->id, 'role' => Roles::STUDENT])
            ->assertSessionHasErrors('role');
        $this->assertTrue($staff->fresh()->hasRole(Roles::ASSISTANT));

        $student = $this->actor(Roles::STUDENT);
        $this->actingAs($admin)->get(route('users.edit', $student))->assertOk();
        $this->actingAs($admin)->put(route('users.update', $student), ['name' => 'Học viên đổi tên', 'email' => $student->email, 'branch_id' => $this->branch->id, 'role' => Roles::STUDENT])
            ->assertSessionHasNoErrors();
        $this->assertTrue($student->fresh()->hasRole(Roles::STUDENT));
    }

    public function test_create_form_does_not_offer_student_role(): void
    {
        $html = $this->actingAs($this->actor(Roles::ADMIN))->withHeaders(self::MODAL)->get(route('users.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="student"', $html);
        $this->assertStringContainsString('value="'.Roles::ASSISTANT.'"', $html);
    }

    public function test_missing_fields_get_vietnamese_message_per_field(): void
    {
        $this->actingAs($this->actor(Roles::ADMIN))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload(['name' => '', 'email' => '', 'branch_id' => '', 'password' => '']))
            ->assertSessionHasErrors([
                'name' => 'Vui lòng nhập họ và tên.',
                'email' => 'Vui lòng nhập email công việc.',
                'branch_id' => 'Vui lòng chọn cơ sở chính.',
                'password' => 'Vui lòng nhập mật khẩu khởi tạo.',
            ]);

        $this->actingAs($this->actor(Roles::ADMIN))
            ->from(route('users.index'))
            ->withHeaders(self::MODAL)
            ->post(route('users.store'), $this->studentPayload(['role' => '', 'password' => 'short']))
            ->assertSessionHasErrors(['role' => 'Vui lòng chọn vai trò.', 'password' => 'Mật khẩu tối thiểu 8 ký tự.']);

        $this->assertDatabaseMissing('users', ['email' => 'hocvien.moi@menglish.edu.vn']);
    }

    public function test_form_skips_browser_validation_and_preselects_the_only_branch(): void
    {
        $html = $this->actingAs($this->actor(Roles::ACADEMIC_STAFF))
            ->withHeaders(self::MODAL)
            ->get(route('users.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<form[^>]*id="modal-user-form"[^>]*novalidate|<form[^>]*novalidate[^>]*id="modal-user-form"/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$this->branch->id.'" selected[^>]*>Chi nhánh Cầu Giấy<\/option>/', $html);
    }
}
