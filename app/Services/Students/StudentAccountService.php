<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Tài khoản cổng học viên (docs/tai-khoan-hoc-vien.md), dùng chung cho bước chốt CRM và màn "Tài khoản học viên":
 * tên đăng nhập = mã học viên (users.employee_code), email riêng của hồ sơ (nếu chưa ai dùng) hoặc email tự sinh,
 * mật khẩu tạm bắt buộc đổi ở lần đăng nhập đầu. Mật khẩu tạm chỉ trả về cho người gọi, không lưu / ghi nhật ký.
 */
class StudentAccountService
{
    /** Lý do không tạo được tài khoản cho hồ sơ này với email $email (mặc định emailFor) — null = tạo được. */
    public function skipReason(Student $student, ?string $email = null): ?string
    {
        if ($student->user_id) {
            return 'đã có tài khoản';
        }
        if (User::withTrashed()->whereRaw('UPPER(employee_code) = ?', [Str::upper($student->code)])->exists()) {
            return 'mã học viên trùng tên đăng nhập của tài khoản khác';
        }
        $email ??= $this->emailFor($student);
        if (User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            return "email {$email} đã thuộc tài khoản khác";
        }

        return null;
    }

    /**
     * Tạo tài khoản cho hồ sơ chưa có. Trả về mật khẩu tạm; $student được gắn user_id / email / quan hệ `user` mới.
     *
     * @throws ValidationException hồ sơ không tạo được tài khoản (xem skipReason)
     */
    public function create(Student $student, ?string $email = null): string
    {
        return DB::transaction(function () use ($student, $email) {
            // Khóa hồ sơ, đọc lại user_id: hai người cùng bấm tạo thì người sau thấy "đã có tài khoản".
            $locked = Student::query()->lockForUpdate()->findOrFail($student->id);
            $email ??= $this->emailFor($locked);
            if ($reason = $this->skipReason($locked, $email)) {
                throw ValidationException::withMessages(['student' => "{$locked->code}: {$reason}."]);
            }

            $password = TemporaryPassword::generate();
            $account = User::create([
                'employee_code' => $locked->code,
                'name' => $locked->name,
                'email' => $email,
                'phone' => $locked->phone,
                'branch_id' => $locked->branch_id,
                'password' => Hash::make($password),
                'must_change_password' => true,
                'is_active' => true,
                'email_verified_at' => null,
            ]);
            Role::findOrCreate(Roles::STUDENT, 'web');
            $account->assignRole(Roles::STUDENT);
            $student->forceFill(['user_id' => $account->id, 'email' => $email])->save();
            $student->setRelation('user', $account);

            return $password;
        });
    }

    /**
     * Cấp mật khẩu tạm mới cho tài khoản thuần học viên (không đụng tài khoản nhân sự). Trả về mật khẩu tạm.
     *
     * @throws ValidationException tài khoản có vai trò khác ngoài học viên
     */
    public function resetPassword(User $account): string
    {
        if ($account->getRoleNames()->all() !== [Roles::STUDENT]) {
            throw ValidationException::withMessages(['student' => 'Tài khoản này không phải tài khoản riêng của học viên, liên hệ Admin.']);
        }

        $password = TemporaryPassword::generate();
        $account->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'is_active' => true,
        ])->saveQuietly();

        return $password;
    }

    /** Email đăng nhập: email riêng của hồ sơ (không phải email tự sinh) hoặc email tự sinh theo id hồ sơ. */
    private function emailFor(Student $student): string
    {
        return filled($student->email) && ! User::isGeneratedStudentEmail($student->email)
            ? Str::lower(trim($student->email))
            : User::generatedStudentEmail($student->id);
    }
}
