<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentAccountService;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tài khoản cổng học viên (docs/tai-khoan-hoc-vien.md): tạo cho hồ sơ chưa có tài khoản (học viên nhập từ Excel…),
 * cấp lại mật khẩu tạm — dùng chung cho bước chốt CRM và màn "Tài khoản học viên".
 */
class StudentAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private StudentAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'CS Tài khoản', 'code' => 'TK', 'is_active' => true]);
        $this->accounts = app(StudentAccountService::class);
    }

    private function student(string $code, array $attributes = []): Student
    {
        return Student::create($attributes + [
            'code' => $code, 'name' => 'HV '.$code, 'phone' => '0900000'.random_int(100, 999),
            'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
    }

    public function test_creates_portal_account_with_temporary_password(): void
    {
        $student = $this->student('HV000123');

        $password = $this->accounts->create($student);

        $account = $student->fresh()->user;
        $this->assertNotNull($account);
        $this->assertSame(['HV000123', User::generatedStudentEmail($student->id), $this->branch->id], [$account->employee_code, $account->email, (int) $account->branch_id]);
        $this->assertSame([Roles::STUDENT], $account->getRoleNames()->all());
        $this->assertTrue((bool) $account->must_change_password);
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertSame($account->email, $student->fresh()->email);
    }

    public function test_uses_the_students_own_email_when_nobody_else_has_it(): void
    {
        $student = $this->student('HV000124', ['email' => 'be.an@example.com']);
        $this->accounts->create($student);
        $this->assertSame('be.an@example.com', $student->fresh()->user->email);

        // Email riêng đã thuộc tài khoản khác → không tạo, báo lý do.
        User::factory()->create(['email' => 'trung@example.com']);
        $other = $this->student('HV000125', ['email' => 'trung@example.com']);
        $this->assertSame('email trung@example.com đã thuộc tài khoản khác', $this->accounts->skipReason($other));
    }

    public function test_skip_reasons_for_students_that_cannot_get_an_account(): void
    {
        $linked = $this->student('HV000126');
        $this->accounts->create($linked);
        $this->assertSame('đã có tài khoản', $this->accounts->skipReason($linked->fresh()));

        // Mã HV-<ULID> (định dạng chuẩn của bước chốt CRM / nhập Excel) dùng làm tên đăng nhập được.
        $ulidCode = $this->student('HV-01JABCDEFGHJKMNPQRSTVWXYZ');
        $this->assertNull($this->accounts->skipReason($ulidCode));

        User::factory()->create(['employee_code' => 'HV000127']);
        $taken = $this->student('HV000127');
        $this->assertSame('mã học viên trùng tên đăng nhập của tài khoản khác', $this->accounts->skipReason($taken));

        $this->expectException(ValidationException::class);
        $this->accounts->create($taken);
    }

    public function test_reset_password_only_for_pure_student_accounts(): void
    {
        $student = $this->student('HV000128');
        $this->accounts->create($student);
        $account = $student->fresh()->user;
        $account->forceFill(['must_change_password' => false])->save();

        $password = $this->accounts->resetPassword($account);
        $this->assertTrue(Hash::check($password, $account->fresh()->password));
        $this->assertTrue((bool) $account->fresh()->must_change_password);

        $account->assignRole(Roles::ASSISTANT);
        $this->expectException(ValidationException::class);
        $this->accounts->resetPassword($account->fresh());
    }
}
