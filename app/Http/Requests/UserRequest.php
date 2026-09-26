<?php

namespace App\Http\Requests;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_code')->ignore($userId)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['required', 'exists:branches,id'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'id_card_number' => ['nullable', 'string', 'max:30'],
            'hometown' => ['nullable', 'string', 'max:255'],
            'current_address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'graduation_school' => ['nullable', 'string', 'max:255'],
            'certificates' => ['nullable', 'string', 'max:255'],
            'teaching_level' => ['nullable', 'string', 'max:255'],
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
            'employee_code.unique' => 'Mã nhân viên này đã tồn tại.',
            'branch_id.required' => 'Vui lòng chọn chi nhánh.',
            'role.required' => 'Vui lòng chọn vai trò.',
        ];
    }
}
