<?php

namespace App\Http\Requests;

use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * Quyền đã được enforce qua route middleware can:user.create|user.update.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ô lương để trống trên form → 0 (cột NOT NULL DEFAULT 0); ConvertEmptyStringsToNull đã đổi "" thành null.
     * Chỉ áp dụng khi form có gửi trường, để không ghi đè lương khi request không chứa trường đó.
     */
    protected function prepareForValidation(): void
    {
        foreach (['base_salary', 'hourly_rate'] as $field) {
            if ($this->exists($field) && $this->input($field) === null) {
                $this->merge([$field => 0]);
            }
        }
        // "Kiêm nhiệm giảng dạy" chỉ có nghĩa với vai trò chính Học thuật: đổi sang vai trò khác thì tắt.
        if ($this->filled('role') && $this->input('role') !== Roles::ACADEMIC_LEAD) {
            $this->merge(['academic_teaching' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            // Không có employee_code: mã nhân viên (tên đăng nhập) do hệ thống tự cấp MESxxx (User::nextEmployeeCode), form không gửi / không sửa.
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['required', 'exists:branches,id'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'academic_teaching' => ['nullable', 'boolean'],
            'id_card_number' => ['nullable', 'string', 'max:30'],
            'hometown' => ['nullable', 'string', 'max:255'],
            'current_address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'graduation_school' => ['nullable', 'string', 'max:255'],
            'certificates' => ['nullable', 'string', 'max:255'],
            'teaching_level' => ['nullable', 'string', 'max:255'],
            'personal_email' => ['nullable', 'string', 'email', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'id_card_issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'major' => ['nullable', 'string', 'max:255'],
            'joined_on' => ['nullable', 'date'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'contact_channel' => ['nullable', 'string', 'max:50'],
            'staff_note' => ['nullable', 'string', 'max:2000'],
            'contract_type' => ['nullable', 'string', 'max:50'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'contract_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'],
            'concurrent_roles_present' => ['nullable', 'boolean'],
            'concurrent_roles' => ['nullable', 'array'],
            'concurrent_roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email này đã được sử dụng bởi tài khoản khác.',
            'branch_id.required' => 'Vui lòng chọn chi nhánh.',
            'role.required' => 'Vui lòng chọn vai trò.',
            'personal_email.email' => 'Email cá nhân không hợp lệ.',
            'date_of_birth.before' => 'Ngày sinh phải trước hôm nay.',
        ];
    }
}
